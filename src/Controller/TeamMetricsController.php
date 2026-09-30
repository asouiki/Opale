<?php

namespace App\Controller;

use App\Model\AbstractPiEntities;
use App\Model\Config;
use App\Model\CurrentPiFeatures;
use App\Model\CurrentPiReleases;
use App\Model\Sprint;
use App\Model\TeamMetrics;
use App\Service\LlmAnalysisCacheService;
use App\Service\LlmPromptRepository;
use App\Service\LlmService;
use App\Service\MetricsDigestBuilder;
use App\Service\PiEntitiesCountCacheService;
use App\Service\TeamConfigRepository;
use App\View\TeamMetricsView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TeamMetricsController
 *
 * Gère la page /team-metrics : sélection de plusieurs sprints clôturés d'un board → calcul et affichage des
 * métriques moyennes de l'équipe (onglet « Sprints »).
 * Les onglets « Releases » et « Features » sont servis par les endpoints d'API de cette classe, pas par le
 * rendu PHP de la page.
 */
class TeamMetricsController
{
    /**
     * Suffixe distinguant, dans `sprintIds[]`, l'entrée « photo à date » d'un sprint de son entrée nominale.
     * Doit rester aligné sur la constante PHOTO_SUFFIX du sélecteur (selectTeamSprints.phtml).
     */
    private const PHOTO_ID_SUFFIX = ':asof';

    /**
     * Affiche la page vide (formulaire de sélection seul).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        $view       = null;
        $error      = null;
        $aiAnalysis = null;
        // Drapeau d'affichage de la synthèse IA (masque tout le bloc si désactivé).
        $llmEnabled = (new LlmService())->isEnabled();
        require __DIR__ . '/../../views/pages/team-metrics.phtml';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-releases?from=YYYY-MM-DD&to=YYYY-MM-DD&refreshKeys[]=42
     *
     * Retourne en JSON les Releases/Versions du « PI en cours » (fenêtre [from, to] déduite des
     * sprints sélectionnés) avec, pour chacune, ses Lead/Cycle/Waiting Time et son efficacité du flow,
     * plus les agrégats d'en-tête. Alimente l'onglet « Releases » de la page Métriques d'équipe.
     */
    public function getPiReleases(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiEntities($request, $response, $args, PiEntitiesCountCacheService::KIND_RELEASES);
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-features?from=YYYY-MM-DD&to=YYYY-MM-DD&refreshKeys[]=ABC-1
     *
     * Retourne en JSON les Features (issues de type Feature) du « PI en cours » (fenêtre [from, to] déduite
     * des sprints sélectionnés) avec, pour chacune, ses Lead/Cycle/Waiting Time et son efficacité du flow,
     * plus les agrégats d'en-tête. Alimente l'onglet « Features » de la page Métriques d'équipe.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     */
    public function getPiFeatures(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiEntities($request, $response, $args, PiEntitiesCountCacheService::KIND_FEATURES);
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-features/keys?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     *
     * @throws \App\Exception\JiraCredentialsException Si les identifiants Jira sont invalides
     */
    public function getPiFeaturesKeys(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiKeys($request, $response, $args, PiEntitiesCountCacheService::KIND_FEATURES);
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-releases/keys?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     *
     * @throws \App\Exception\JiraCredentialsException Si les identifiants Jira sont invalides
     */
    public function getPiReleasesKeys(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiKeys($request, $response, $args, PiEntitiesCountCacheService::KIND_RELEASES);
    }

    /**
     * Tronc commun des deux endpoints de planification : retourne la liste ORDONNÉE des clés de la
     * fenêtre, { "keys": ["ABC-1", ...] }.
     *
     * Première étape du chargement par lots. Utilise le mode comptage seul, donc n'engage aucun calcul
     * de métrique : une seule recherche Jira, quelle que soit la volumétrie. L'IHM connaît ainsi le
     * total dès le départ (barre d'avancement exacte) puis redemande les lignes par paquets de clés
     * EXPLICITES — un découpage par offset serait instable, la fenêtre étant triée sur `updated`, qui
     * change pendant le chargement et provoquerait doublons et oublis.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     * @param string                $kind Nature d'entité (cf. PiEntitiesCountCacheService::KIND_*)
     *
     * @throws \App\Exception\JiraCredentialsException Si les identifiants Jira sont invalides
     */
    private function respondPiKeys(ServerRequestInterface $request, ResponseInterface $response, array $args, string $kind): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $params     = $request->getQueryParams();
        $from       = trim((string) ($params['from'] ?? ''));
        $to         = trim((string) ($params['to'] ?? ''));

        try {
            if ($projectKey === '' || $from === '' || $to === '') {
                throw new \InvalidArgumentException('Paramètres requis : projectKey, from, to.');
            }

            Config::setCurrentProjectKey($projectKey);

            $pi = $this->buildPiEntities($kind, $projectKey, $from, $to, refresh: false, countOnly: true);

            return $this->json($response, ['keys' => array_column($pi->getItems(), 'key')]);
        } catch (\App\Exception\JiraCredentialsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Tronc commun des deux endpoints de liste : valide la fenêtre, construit l'agrégat de la nature
     * demandée avec ses métriques complètes, et renvoie agrégats d'en-tête + lignes.
     *
     * Query param `refreshKeys[]` : resynchronise avec Jira les SEULES entités désignées (les lignes
     * cochées côté IHM), qu'elles soient terminées ou non — seul moyen de rafraîchir une entité Done,
     * dont le cache est permanent alors que son détail (SP, types, étiquettes) reste éditable dans Jira.
     *
     * Pendant « liste » de {@see self::respondPiCount()}, qui ne remonte que le compteur.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     * @param string                $kind Nature d'entité (cf. PiEntitiesCountCacheService::KIND_*)
     *
     * @throws \App\Exception\JiraCredentialsException Si les identifiants Jira sont invalides
     */
    private function respondPiEntities(ServerRequestInterface $request, ResponseInterface $response, array $args, string $kind): ResponseInterface
    {
        $projectKey  = trim((string) ($args['projectKey'] ?? ''));
        $params      = $request->getQueryParams();
        $from        = trim((string) ($params['from'] ?? ''));
        $to          = trim((string) ($params['to'] ?? ''));
        $refresh     = !empty($params['refresh']);
        // Rafraîchissement ciblé sur les lignes cochées : recalcule aussi les entités terminées.
        $refreshKeys = $this->parseRefreshKeys($params['refreshKeys'] ?? []);
        // Chargement par lots : ne calculer que ces entités. Absent = toute la fenêtre (comportement
        // historique, et requête finale de l'onglet une fois le cache réchauffé).
        $onlyKeys    = $this->parseRefreshKeys($params['keys'] ?? []);

        try {
            if ($projectKey === '' || $from === '' || $to === '') {
                throw new \InvalidArgumentException('Paramètres requis : projectKey, from, to.');
            }

            // Active la résolution du workflow propre au projet (statuts/labels d'attente).
            Config::setCurrentProjectKey($projectKey);

            $pi = $this->buildPiEntities($kind, $projectKey, $from, $to, $refresh, countOnly: false, refreshKeys: $refreshKeys, onlyKeys: $onlyKeys);

            return $this->json($response, ['stats' => $pi->getStats(), 'items' => $pi->getItems()]);
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-releases/count?from=YYYY-MM-DD&to=YYYY-MM-DD&refresh=1
     *
     * Retourne en JSON le nombre de Releases + Versions du « PI en cours » (fenêtre [from, to] déduite
     * des sprints sélectionnés) : { "count": 7, "fromCache": true }. Alimente la carte compteur de
     * l'onglet Sprints, avec un lien vers l'onglet Releases. Le count est mémorisé par fenêtre
     * (≡ sélection de sprints) via PiEntitiesCountCacheService pour un affichage instantané ensuite.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     */
    public function getPiReleasesCount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiCount($request, $response, $args, PiEntitiesCountCacheService::KIND_RELEASES);
    }

    /**
     * GET /api/internal/projects/{projectKey}/pi-features/count?from=YYYY-MM-DD&to=YYYY-MM-DD&refresh=1
     *
     * Pendant Features de {@see self::getPiReleasesCount()} : nombre de Features du « PI en cours »,
     * alimentant la carte compteur de l'onglet Sprints avec un lien vers l'onglet Features.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     */
    public function getPiFeaturesCount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respondPiCount($request, $response, $args, PiEntitiesCountCacheService::KIND_FEATURES);
    }

    /**
     * Tronc commun des deux endpoints de comptage : valide la fenêtre, sert le count mémorisé si
     * disponible, sinon recalcule via l'agrégat de la nature demandée (même source que l'onglet
     * correspondant → chiffre identique) puis mémorise le résultat.
     *
     * Query param cacheOnly=1 : renvoie le count uniquement s'il est déjà en cache (sinon count=null,
     * sans recalcul) — utilisé par le loader de l'onglet pour éviter un double-build sur cache froid.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     * @param string                $kind Nature d'entité (cf. PiEntitiesCountCacheService::KIND_*)
     *
     * @throws \App\Exception\JiraCredentialsException Si les identifiants Jira sont invalides
     */
    private function respondPiCount(ServerRequestInterface $request, ResponseInterface $response, array $args, string $kind): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $params     = $request->getQueryParams();
        $from       = trim((string) ($params['from'] ?? ''));
        $to         = trim((string) ($params['to'] ?? ''));
        $refresh    = !empty($params['refresh']);
        // Mode « cache uniquement » (loader) : ne veut le count que s'il est déjà mémorisé, jamais au prix d'un calcul.
        $cacheOnly  = !empty($params['cacheOnly']);

        try {
            if ($projectKey === '' || $from === '' || $to === '') {
                throw new \InvalidArgumentException('Paramètres requis : projectKey, from, to.');
            }

            $cache = new PiEntitiesCountCacheService($kind);

            // Cache-first : un count mémorisé pour cette fenêtre est servi tel quel (hors recalcul forcé).
            if (!$refresh) {
                $cached = $cache->get($projectKey, $from, $to);
                if ($cached !== null) {
                    return $this->json($response, ['count' => $cached, 'fromCache' => true]);
                }
            }

            // Cache absent en mode « cache uniquement » : count=null sans déclencher le calcul complet
            // (N+1), pour ne pas doubler la charge du fetch principal de l'onglet sur cache froid.
            if ($cacheOnly) {
                return $this->json($response, ['count' => null, 'fromCache' => false]);
            }

            // Active la résolution du workflow propre au projet (statuts/labels d'attente).
            Config::setCurrentProjectKey($projectKey);

            $count = (int) ($this->buildPiEntities($kind, $projectKey, $from, $to, $refresh, countOnly: true)->getStats()['total'] ?? 0);

            $cache->put($projectKey, $from, $to, $count);

            return $this->json($response, ['count' => $count, 'fromCache' => false]);
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Instancie l'agrégat du « PI en cours » correspondant à la nature demandée, avec les réglages
     * d'équipe (projet parent + nom d'équipe) partagés par les deux onglets.
     *
     * En mode « comptage seul », seul stats['total'] est résolu : les cartes compteur n'ont pas besoin
     * de plus, et calculer les métriques imposerait un aller-retour Jira par entité (tickets liés +
     * changelog paginé), au risque de dépasser le temps d'exécution sur une large fenêtre de sprints.
     * Les onglets de liste, eux, demandent les métriques complètes.
     *
     * @param string       $kind        Nature d'entité (cf. PiEntitiesCountCacheService::KIND_*)
     * @param bool         $countOnly   true = compteur seul (cartes) ; false = métriques complètes (onglets)
     * @param list<string> $refreshKeys Identifiants des seules entités à resynchroniser avec Jira, terminées comprises
     * @param list<string> $onlyKeys    Restriction au lot demandé (chargement par lots) ; vide = toute la fenêtre
     */
    private function buildPiEntities(string $kind, string $projectKey, string $from, string $to, bool $refresh, bool $countOnly, array $refreshKeys = [], array $onlyKeys = []): AbstractPiEntities
    {
        $teamConfig       = new TeamConfigRepository();
        $parentProjectKey = $teamConfig->getParentProjectKey($projectKey);
        $releaseTeamName  = $teamConfig->getReleaseTeamName($projectKey);

        return $kind === PiEntitiesCountCacheService::KIND_FEATURES
            ? new CurrentPiFeatures($projectKey, $from, $to, $refresh, $parentProjectKey, $releaseTeamName, $countOnly, $refreshKeys, null, $onlyKeys)
            : new CurrentPiReleases($projectKey, $from, $to, $refresh, $parentProjectKey, $releaseTeamName, $countOnly, $refreshKeys, null, $onlyKeys);
    }

    /**
     * Normalise le paramètre de requête `refreshKeys[]` (tableau, valeur unique ou absent) en liste de clés.
     *
     * Aucune sanitisation JQL n'est nécessaire : ces valeurs ne sont jamais interpolées dans une requête,
     * elles sont uniquement COMPARÉES aux identifiants renvoyés par Jira (cf. AbstractPiEntities::mustRecompute()).
     * Une clé fantaisiste ne correspond donc à rien et reste sans effet.
     *
     * @param  mixed        $raw Valeur brute du paramètre
     * @return list<string>      Clés non vides, dans l'ordre de soumission
     */
    private function parseRefreshKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $raw
        )));
    }

    /**
     * Traite la soumission : liste de sprintIds → agrégat TeamMetrics → render.
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // getParsedBody() peut renvoyer un objet (corps JSON) ou null : on normalise en tableau.
        $data         = (array) ($request->getParsedBody() ?? []);
        $projectKey   = trim((string) ($data['projectKey'] ?? ''));
        $boardId      = trim((string) ($data['boardId'] ?? ''));
        $forceRefresh = !empty($data['refresh']);

        // Active la résolution du workflow propre au projet (sinon fallback global).
        Config::setCurrentProjectKey($projectKey);

        // Cases à cocher : zéro, une ou plusieurs valeurs `sprintIds[]`, dont d'éventuelles entrées « photo ».
        $selection    = $this->splitSprintSelection($data['sprintIds'] ?? []);
        $rawSprintIds = $selection['raw'];
        $sprintIds    = $selection['ids'];
        $photoIds     = $selection['photos'];

        $view       = null;
        $error      = null;
        $aiAnalysis = null;
        // Drapeau d'affichage de la synthèse IA (masque tout le bloc si désactivé).
        $llmEnabled = (new LlmService())->isEnabled();

        try {
            if ($sprintIds === []) {
                throw new \Exception('Sélectionnez au moins un sprint clôturé.');
            }

            $metrics = new TeamMetrics($sprintIds, $projectKey, $boardId, $forceRefresh, $photoIds);
            $view    = new TeamMetricsView($metrics);

            $this->rememberAvgVelocity($projectKey, $view);

            // Synthèse IA mémorisée d'une sélection 100 % clôturée : pré-chargée pour un affichage
            // direct au rechargement de la page, sans reclic sur « Générer ».
            // Clé dérivée de la sélection BRUTE : sélectionner la photo d'un sprint plutôt que son état de clôture
            // change les chiffres, donc la synthèse.
            if ($llmEnabled && $this->allSprintsClosed($metrics)) {
                $aiAnalysis = (new LlmAnalysisCacheService())->get($this->analysisCacheKey($rawSprintIds), $projectKey);
            }
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ob_start();
        require __DIR__ . '/../../views/pages/team-metrics.phtml';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * POST /api/internal/projects/{projectKey}/team-metrics/analysis
     *
     * Génère (ou récupère depuis le cache) une synthèse IA narrative des métriques
     * agrégées de l'équipe sur la sélection de sprints. Renvoie du JSON consommé
     * par la carte « Synthèse IA » de la page Métriques d'équipe.
     *
     * Corps (urlencoded) : sprintIds[] (requis), boardId (optionnel), refresh=1,
     * extraPrompt (optionnel) pour ajouter des indications libres au prompt système.
     * Répond un JSON {analysis, fromCache, model} ou {error}.
     *
     * @param array $args Paramètres de route (projectKey)
     */
    public function analyze(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $data       = (array) ($request->getParsedBody() ?? []);
        $boardId    = trim((string) ($data['boardId'] ?? ''));
        $refresh    = !empty($data['refresh']);
        $extra      = trim((string) ($data['extraPrompt'] ?? ''));

        $selection    = $this->splitSprintSelection($data['sprintIds'] ?? []);
        $rawSprintIds = $selection['raw'];
        $sprintIds    = $selection['ids'];
        $photoIds     = $selection['photos'];

        $llm = new LlmService();

        // Sécurité : la fonctionnalité est masquée côté UI quand elle est désactivée ;
        // ce garde-fou refuse en plus un appel direct à l'API.
        if (!$llm->isEnabled()) {
            return $this->json($response, ['error' => 'Fonctionnalité IA désactivée.'], 403);
        }

        try {
            if ($sprintIds === []) {
                throw new \InvalidArgumentException('Sélectionnez au moins un sprint clôturé.');
            }

            // Active la résolution du workflow propre au projet (statuts done/attente...).
            Config::setCurrentProjectKey($projectKey);

            // Agrégat d'équipe : TeamMetrics recharge chaque sprint cache-first.
            $metrics = new TeamMetrics($sprintIds, $projectKey, $boardId, false, $photoIds);

            $cacheKey      = $this->analysisCacheKey($rawSprintIds);
            $hasExtra      = $extra !== '';
            $allClosed     = $this->allSprintsClosed($metrics);
            $analysisCache = new LlmAnalysisCacheService();

            // Sélection 100 % clôturée (immuable) : synthèse réutilisable depuis le cache. Des
            // indications ad hoc forcent une génération fraîche (on ne sert pas la mémorisée).
            if ($allClosed && !$refresh && !$hasExtra) {
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

            $analysis = $llm->chat($this->buildSystemPrompt($extra), (new MetricsDigestBuilder())->forTeam($metrics));

            // On mémorise la dernière synthèse d'une sélection 100 % clôturée : avec ou sans
            // indications, la dernière génération écrase la précédente.
            if ($allClosed) {
                $analysisCache->put($cacheKey, $analysis, $llm->getModel(), $projectKey, $extra);
            }

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
     * Mémorise dans la config d'équipe la vélocité agrégée de la sélection analysée : elle alimente la
     * capacité suggérée du champ « Capacité totale brute » de la page /planification.
     *
     * La valeur retenue est celle calculée côté serveur au moment de l'analyse. La page la recalcule
     * ensuite côté navigateur si l'utilisateur édite un « jours/homme » en ligne : le décalage éventuel
     * se résorbe à la prochaine analyse.
     *
     * Best-effort et non destructif :
     *  - une vélocité non calculable (aucun jours/homme saisi) n'efface PAS la dernière valeur connue ;
     *  - un fichier de config corrompu n'est jamais réécrit (il serait remplacé par une config partielle) ;
     *  - un échec d'écriture ne doit pas priver l'utilisateur de ses métriques, déjà calculées.
     */
    private function rememberAvgVelocity(string $projectKey, TeamMetricsView $view): void
    {
        $velocity = $view->getStats()['velocityPerManDay'] ?? null;
        if ($projectKey === '' || !is_numeric($velocity)) {
            return;
        }

        try {
            $teamConfig = new TeamConfigRepository();
            if (!$teamConfig->isCorrupted($projectKey)) {
                $teamConfig->setAvgVelocity($projectKey, (float) $velocity);
            }
        } catch (\Throwable) {
            // Silencieux : réglage de confort, les métriques affichées restent justes.
        }
    }

    /**
     * Indique si tous les sprints chargés par l'agrégat sont clôturés (donc immuables).
     */
    private function allSprintsClosed(TeamMetrics $metrics): bool
    {
        $sprints = $metrics->getSprints();
        if ($sprints === []) {
            return false;
        }

        foreach ($sprints as $sprint) {
            if (($sprint['state'] ?? '') !== Sprint::STATE_CLOSED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Décompose la sélection de sprints reçue du formulaire.
     *
     * Le sélecteur propose, pour un sprint doté d'une photo « à date », une entrée supplémentaire dont l'ID porte le
     * suffixe `:asof`. Le suffixe est retiré ICI : le modèle ne doit jamais voir passer un ID composite, qui partirait
     * tel quel dans un appel Jira.
     *
     * @param  mixed $raw Valeur brute de `sprintIds[]` (tableau, valeur unique, ou absente)
     * @return array{raw: list<string>, ids: list<string>, photos: list<string>}
     *         `raw` = sélection telle que soumise (clé de cache des synthèses IA), `ids` = IDs de sprint réels,
     *         `photos` = ceux à charger depuis leur photo
     */
    private function splitSprintSelection(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $rawIds = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $raw
        )));

        $ids    = [];
        $photos = [];
        foreach ($rawIds as $rawId) {
            $isPhoto = str_ends_with($rawId, self::PHOTO_ID_SUFFIX);
            $id      = $isPhoto ? substr($rawId, 0, -strlen(self::PHOTO_ID_SUFFIX)) : $rawId;

            if ($id === '') {
                continue;
            }
            $ids[] = $id;
            if ($isPhoto) {
                $photos[] = $id;
            }
        }

        return ['raw' => $rawIds, 'ids' => $ids, 'photos' => $photos];
    }

    /**
     * Clé de cache d'une synthèse d'équipe, dérivée de la sélection de sprints (triés) : une
     * même sélection réutilise la même synthèse mémorisée, quel que soit l'ordre de sélection.
     *
     * @param list<string> $sprintIds IDs des sprints sélectionnés
     */
    private function analysisCacheKey(array $sprintIds): string
    {
        $sorted = $sprintIds;
        sort($sorted);

        return 'team-metrics_' . md5(implode(',', $sorted));
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
        $base = (new LlmPromptRepository())->load('llm_team_metrics_prompt.md');

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
            . "sans contredire les chiffres ni omettre les signaux clés de santé de l'équipe :\n\n" . $extra;
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
