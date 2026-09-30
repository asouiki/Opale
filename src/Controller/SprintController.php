<?php

namespace App\Controller;

use App\Model\Config;
use App\Model\Sprint;
use App\Service\LlmAnalysisCacheService;
use App\Service\LlmPromptRepository;
use App\Service\LlmService;
use App\Service\MetricsDigestBuilder;
use App\Service\SprintCacheService;
use App\Service\SprintConfigRepository;
use App\View\SprintView;
use DateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SprintController
 *
 * Gère la page /sprint : sélection d'un sprint Jira → analyse du sprint en cours
 * (atterrissage, objectifs, prédictibilité, changement de périmètre, reliquat...).
 */
class SprintController
{
    /**
     * Affiche la page vide (formulaire de sélection seul).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        $view                = null;
        $error               = null;
        $fromCache           = false;
        $cachedAt            = null;
        $manDays             = null;
        $capacityPoints      = null;
        $manDaysFromCapacity = false;
        $aiAnalysis          = null;
        $sprintIsClosed      = false;
        $savedAsOf           = null;
        $asOfIsSaved         = false;
        // Drapeau d'affichage de la synthèse IA (masque tout le bloc si désactivé).
        $llmEnabled = (new LlmService())->isEnabled();
        require __DIR__ . '/../../views/pages/sprint.phtml';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Traite la soumission : sprintId → instancie Sprint → render.
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // getParsedBody() peut renvoyer un objet (corps JSON) ou null : on normalise en tableau.
        $data         = (array) ($request->getParsedBody() ?? []);
        $sprintId     = trim((string) ($data['sprintId'] ?? ''));
        $projectKey   = trim((string) ($data['projectKey'] ?? ''));
        $boardId      = trim((string) ($data['boardId'] ?? ''));
        $forceRefresh = !empty($data['refresh']);
        // Date d'observation demandée (champ datetime-local) : reconstruit le sprint tel qu'il était à cet instant.
        $asOfDate = $this->parseAsOfDate((string) ($data['asOfDate'] ?? ''));
        // Actions sur la photo enregistrée : mémorisation de l'état affiché, ou suppression.
        $saveAsOf   = !empty($data['saveAsOf']);
        $deleteAsOf = !empty($data['deleteAsOf']);

        // Active la résolution du workflow propre au projet (sinon fallback global).
        Config::setCurrentProjectKey($projectKey);

        $view                = null;
        $error               = null;
        $fromCache           = false;
        $cachedAt            = null;
        $manDays             = null;
        $capacityPoints      = null;
        $manDaysFromCapacity = false;
        $aiAnalysis          = null;
        // Photo « à date » enregistrée pour ce sprint (null si aucune) : alimente le bandeau de rappel.
        $savedAsOf   = null;
        $asOfIsSaved = false;
        // Sprint clôturé ou non : conditionne le libellé du badge de fraîcheur de la synthèse IA.
        $sprintIsClosed = false;
        // Drapeau d'affichage de la synthèse IA (masque tout le bloc si désactivé).
        $llmEnabled = (new LlmService())->isEnabled();

        try {
            if ($sprintId === '') {
                throw new \Exception('Le paramètre sprintId est requis.');
            }

            $cache = new SprintCacheService();

            if ($deleteAsOf) {
                $cache->deleteAsOf($sprintId, $projectKey);
            }

            if ($asOfDate !== null) {
                // Mode « photo à date » : cache propre à la date observée, jamais le cache nominal — l'état de
                // clôture réel doit rester récupérable.
                $sprint = $this->loadSprintAtDate($cache, $sprintId, $projectKey, $boardId, $asOfDate, $forceRefresh);
                if ($saveAsOf) {
                    $cache->putAsOf($sprintId, $sprint->toSnapshot(), $asOfDate, $projectKey);
                }
            } else {
                $cached = $forceRefresh ? null : $cache->get($sprintId, $projectKey);

                if ($cached !== null) {
                    // Photo en cache (sprint clôturé) → réhydratation sans appel Jira.
                    $sprint    = Sprint::fromSnapshot($cached['data']);
                    $fromCache = true;
                    $cachedAt  = $cached['cached_at'];
                } else {
                    // Calcul live, puis mise en cache si le sprint est clôturé (immuable).
                    $sprint = new Sprint($sprintId, $projectKey, $boardId);
                    if ($sprint->getState() === Sprint::STATE_CLOSED) {
                        $cache->put($sprintId, $sprint->toSnapshot(), $projectKey);
                    }
                }
            }

            // Photo enregistrée pour ce sprint : signalée en permanence, même hors mode « à date », car elle fera
            // foi dans les métriques d'équipe une fois sélectionnée.
            $savedAsOf = $cache->getAsOf($sprintId, $projectKey);
            // Vrai si l'état affiché EST la photo enregistrée : le bandeau propose alors de la remplacer plutôt que
            // de l'enregistrer, et confirme qu'elle est bien mémorisée.
            $asOfIsSaved = $asOfDate !== null
                && $savedAsOf !== null
                && ($savedAsOf['data']['asOfDate'] ?? null) === $asOfDate->format(DateTime::ATOM);

            $view = new SprintView($sprint);

            $sprintIsClosed = $sprint->getState() === Sprint::STATE_CLOSED;

            // Synthèse IA mémorisée : pré-chargée pour un affichage direct au rechargement de la page,
            // sans reclic sur « Générer ». Vaut aussi pour un sprint en cours : l'analyse est alors une
            // photo datée, et la vue invite à régénérer via la date affichée.
            if ($llmEnabled) {
                $aiAnalysis = (new LlmAnalysisCacheService())->get($this->analysisCacheKey($sprintId), $projectKey);
            }

            // Jours/homme et capacité en points de ce sprint (partagés avec la page Team Metrics).
            // Les jours/homme peuvent provenir de la grille de l'onglet Capacité, qui fait alors foi :
            // le drapeau bascule le champ en lecture seule côté vue.
            $sprintConfig        = new SprintConfigRepository();
            $manDays             = $sprintConfig->getManDays($projectKey, $sprintId);
            $capacityPoints      = $sprintConfig->getCapacityPoints($projectKey, $sprintId);
            $manDaysFromCapacity = $sprintConfig->hasCapacityManDays($projectKey, $sprintId);
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ob_start();
        require __DIR__ . '/../../views/pages/sprint.phtml';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Normalise la date d'observation saisie (champ `datetime-local`, ex. « 2026-08-27T17:30 »).
     *
     * Interprétée dans le fuseau applicatif (posé au bootstrap depuis \App\Service\JiraDate::APP_TIMEZONE), donc à
     * l'heure que l'utilisateur voit. Une saisie vide ou invalide retourne null : la page retombe alors sur son
     * comportement nominal plutôt que d'afficher une erreur pour un champ facultatif.
     */
    private function parseAsOfDate(string $raw): ?DateTime
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTime($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Charge le sprint reconstruit à une date d'observation, en réutilisant la photo enregistrée quand elle porte
     * exactement cette date.
     *
     * Sans cette réutilisation, chaque rechargement de la page relancerait le chargement complet du sprint
     * (issues + changelog + rapport de sprint). Une date différente de la photo force en revanche le recalcul :
     * la photo n'est pas modifiée pour autant, seul « Enregistrer » la remplace.
     */
    private function loadSprintAtDate(
        SprintCacheService $cache,
        string $sprintId,
        string $projectKey,
        string $boardId,
        DateTime $asOfDate,
        bool $forceRefresh
    ): Sprint {
        if (!$forceRefresh) {
            $saved = $cache->getAsOf($sprintId, $projectKey);
            $savedDate = $saved !== null ? ($saved['data']['asOfDate'] ?? null) : null;

            if (is_string($savedDate) && $savedDate === $asOfDate->format(DateTime::ATOM)) {
                return Sprint::fromSnapshot($saved['data']);
            }
        }

        return new Sprint($sprintId, $projectKey, $boardId, $asOfDate);
    }

    /**
     * GET /api/internal/sprints/cache-status?sprintId[]=123&sprintId[]=456&projectKey=ABC
     *
     * Retourne un objet JSON { "123": { "cached": true, "asOf": null }, ... } indiquant pour chaque sprint :
     *  - `cached` : un snapshot valide (produit par la version d'algorithme courante) existe déjà en cache.
     *    Seuls les sprints CLÔTURÉS sont mis en cache : un sprint en cours répondra toujours false.
     *    Alimente les badges ⚡ / ⏳ des sélecteurs de sprint des pages Sprint et Métriques d'équipe.
     *  - `asOf` : date ISO de la photo « à date » enregistrée pour ce sprint, ou null. La page Métriques d'équipe
     *    en fait une entrée sélectionnable à la place de l'état de clôture.
     *
     * @throws \JsonException Si la charge utile ne peut pas être encodée en JSON
     */
    public function getCacheStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $rawIds     = $params['sprintId'] ?? [];
        $projectKey = trim((string) ($params['projectKey'] ?? ''));
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }

        $ids = [];
        foreach ($rawIds as $id) {
            $id = trim((string) $id);
            // Les IDs de sprint Jira sont des entiers ; on rejette tout autre format.
            if ($id !== '' && ctype_digit($id)) {
                $ids[] = $id;
            }
        }

        $cache  = new SprintCacheService();
        $photos = $cache->listAsOf($ids, $projectKey);

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'cached' => $cache->has($id, $projectKey),
                'asOf'   => $photos[$id] ?? null,
            ];
        }

        return $this->json($response, $result);
    }

    /**
     * POST /api/internal/projects/{projectKey}/sprints/{sprintId}/analysis
     *
     * Génère (ou récupère depuis le cache) une synthèse IA narrative du sprint à
     * partir de ses métriques déjà calculées. Renvoie du JSON consommé par la
     * carte « Synthèse IA » de la page Sprint.
     *
     * Corps (urlencoded) : boardId (optionnel), refresh=1 pour ignorer le cache,
     * extraPrompt (optionnel) pour ajouter des indications libres au prompt système.
     * Répond un JSON {analysis, fromCache, model} ou {error}.
     *
     * @param array $args Paramètres de route (projectKey, sprintId)
     */
    public function analyze(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $sprintId   = trim((string) ($args['sprintId'] ?? ''));
        $data       = (array) ($request->getParsedBody() ?? []);
        $boardId    = trim((string) ($data['boardId'] ?? ''));
        $refresh    = !empty($data['refresh']);
        $extra      = trim((string) ($data['extraPrompt'] ?? ''));

        $llm = new LlmService();

        // Sécurité : la fonctionnalité est masquée côté UI quand elle est désactivée ;
        // ce garde-fou refuse en plus un appel direct à l'API.
        if (!$llm->isEnabled()) {
            return $this->json($response, ['error' => 'Fonctionnalité IA désactivée.'], 403);
        }

        try {
            if ($sprintId === '') {
                throw new \InvalidArgumentException('Le paramètre sprintId est requis.');
            }

            // Active la résolution du workflow propre au projet (statuts done/attente...).
            Config::setCurrentProjectKey($projectKey);

            // Chargement du sprint, cache-first (même stratégie que process()).
            $sprintCache = new SprintCacheService();
            $cached      = $sprintCache->get($sprintId, $projectKey);
            if ($cached !== null) {
                $sprint = Sprint::fromSnapshot($cached['data']);
            } else {
                $sprint = new Sprint($sprintId, $projectKey, $boardId);
                if ($sprint->getState() === Sprint::STATE_CLOSED) {
                    $sprintCache->put($sprintId, $sprint->toSnapshot(), $projectKey);
                }
            }

            $analysisCache = new LlmAnalysisCacheService();
            $cacheKey      = $this->analysisCacheKey($sprintId);

            $hasExtra = $extra !== '';

            // Synthèse réutilisable depuis le cache, sprint clôturé comme en cours : la date de génération
            // affichée permet d'en juger l'ancienneté et de régénérer. Des indications ad hoc forcent une
            // génération fraîche (on ne sert pas la synthèse mémorisée).
            if (!$refresh && !$hasExtra) {
                $hit = $analysisCache->get($cacheKey, $projectKey);
                if ($hit !== null) {
                    return $this->json($response, [
                        'analysis'    => $hit['analysis'],
                        'fromCache'   => true,
                        'model'       => $hit['model'],
                        'extraPrompt' => $hit['extraPrompt'],
                    ]);
                }
            }

            // Capacité (jours/homme + points) : hors snapshot du sprint, stockée dans la config d'équipe.
            $sprintConfig   = new SprintConfigRepository();
            $manDays        = $sprintConfig->getManDays($projectKey, $sprintId);
            $capacityPoints = $sprintConfig->getCapacityPoints($projectKey, $sprintId);

            $digest   = (new MetricsDigestBuilder())->forSprint($sprint, $manDays, $capacityPoints);
            $analysis = $llm->chat($this->buildSystemPrompt($extra), $digest);

            // On mémorise la dernière synthèse : avec ou sans indications, la dernière génération
            // écrase la précédente.
            $analysisCache->put($cacheKey, $analysis, $llm->getModel(), $projectKey, $extra);

            return $this->json($response, [
                'analysis'  => $analysis,
                'fromCache' => false,
                'model'     => $llm->getModel(),
            ]);
        } catch (\Throwable $e) {
            // Erreur LLM (timeout, modèle absent...) : message clair, la page reste utilisable.
            return $this->json($response, ['error' => $e->getMessage()], 200);
        }
    }

    /**
     * Clé de cache de la synthèse IA d'un sprint.
     *
     * Le préfixe distingue le fichier (analysis_{projet}_sprint_{id}.json) de celui des synthèses
     * d'équipe, stockées dans le même répertoire sous analysis_{projet}_team-metrics_{hash}.json.
     */
    private function analysisCacheKey(string $sprintId): string
    {
        return 'sprint_' . $sprintId;
    }

    /**
     * Assemble le prompt système final : prompt de base versionné, éventuellement complété
     * par les indications libres saisies par l'utilisateur (contexte particulier, changement
     * de ressource sur la période...). Chaîne vide => prompt de base inchangé.
     *
     * @throws \RuntimeException Si le prompt de base est absent ou vide
     */
    private function buildSystemPrompt(string $extra): string
    {
        $base = (new LlmPromptRepository())->load('llm_sprint_prompt.md');

        if ($extra === '') {
            return $base;
        }

        // Cadrage explicite : le prompt de base interdit d'utiliser autre chose que les chiffres
        // fournis. On lève cette contrainte pour ce bloc, qui peut porter deux natures d'entrée :
        // des faits qualitatifs fiables ET/OU des directives d'analyse (focus, angle) à appliquer.
        return $base . "\n\n---\n\n## Contexte complémentaire fourni par le Scrum Master\n\n"
            . "Le bloc ci-dessous peut contenir deux natures d'éléments : (a) des faits qualitatifs "
            . 'fiables sur la période, à intégrer comme des données (ce ne sont pas des inventions ; '
            . "la règle « n'utilise que les chiffres fournis » ne s'y applique pas), et/ou (b) des "
            . "directives d'analyse (mettre en avant une métrique, adopter un angle particulier). "
            . 'Traite les faits comme fiables et applique les directives dans la mesure du possible, '
            . "sans contredire les chiffres ni omettre les signaux clés de santé du sprint :\n\n" . $extra;
    }

    /**
     * Écrit une charge utile JSON dans la réponse (helper interne au contrôleur).
     *
     * @throws \JsonException Si la charge utile ne peut pas être encodée en JSON
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
