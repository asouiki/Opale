<?php

namespace App\Controller;

use App\Model\Config;
use App\Model\Planification;
use App\Repository\BoardRepository;
use App\Service\JiraService;
use App\Service\PlanningCapacitySuggester;
use App\Service\TeamConfigRepository;
use App\View\PlanificationView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PlanificationController
 *
 * Gère la page /planification : pour un projet, sélection du sprint en cours
 * et d'un ou plusieurs sprints à venir → vue agrégée de ce qui reste à planifier
 * (reliquat du sprint en cours + backlog des sprints à venir).
 */
class PlanificationController
{
    /**
     * Affiche la page vide (formulaire de sélection seul).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $view           = null;
        $error          = null;
        $planningConfig = $this->buildPlanningConfig('', '');

        ob_start();
        require __DIR__ . '/../../views/pages/planification.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Traite la soumission : sprint en cours + sprints de backlog → render.
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // getParsedBody() peut renvoyer un objet (corps JSON) ou null : on normalise en tableau.
        $data       = (array) ($request->getParsedBody() ?? []);
        $sprintId   = trim((string) ($data['sprintId'] ?? ''));
        $projectKey = trim((string) ($data['projectKey'] ?? ''));
        $boardId    = trim((string) ($data['boardId'] ?? ''));

        // Active la résolution du workflow propre au projet (sinon fallback global).
        Config::setCurrentProjectKey($projectKey);

        // Cases à cocher : zéro, une ou plusieurs valeurs `backlogSprintIds[]`.
        $backlogIds = $data['backlogSprintIds'] ?? [];
        if (!is_array($backlogIds)) {
            $backlogIds = [$backlogIds];
        }
        $backlogIds = array_values(array_filter(array_map('trim', $backlogIds)));

        $view  = null;
        $error = null;

        try {
            if ($sprintId === '') {
                throw new \Exception('Le paramètre sprintId (sprint en cours) est requis.');
            }

            $planification = new Planification($sprintId, $backlogIds, $projectKey, $boardId);
            $view          = new PlanificationView($planification);
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $planningConfig = $this->buildPlanningConfig($projectKey, $boardId);

        ob_start();
        require __DIR__ . '/../../views/pages/planification.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * POST /api/internal/issues/{issueKey}/planning
     *
     * Met à jour côté Jira les deux champs de planification d'un ticket :
     *   - « Reste à faire »            (customfield_10090)
     *   - « Points rééstimés (final) » (customfield_10313)
     *
     * Consommé en AJAX par la page de planification (aucun rechargement).
     * Corps attendu (form-urlencoded) : `remaining` et `reestimated` numériques.
     * Répond un JSON {success:bool, error?:string}.
     *
     * @param array $args ['issueKey' => 'ABC-123']
     */
    public function updateIssue(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $issueKey = trim($args['issueKey'] ?? '');
            if ($issueKey === '') {
                throw new \Exception('Clé de ticket manquante.');
            }

            $data        = (array) ($request->getParsedBody() ?? []);
            $remaining   = $this->parsePlanningValue($data['remaining'] ?? null);
            $reestimated = $this->parsePlanningValue($data['reestimated'] ?? null);

            $jira = new JiraService();
            $jira->updateIssuePlanningFields($issueKey, $remaining, $reestimated);

            // Indique l'action dans l'historique Jira (l'API attribue la modification au porteur du token).
            // Un échec du commentaire ne doit pas faire échouer la requête, la mise à jour étant déjà persistée.
            try {
                $comment = JiraService::OPALE_COMMENT_PREFIX . $this->buildPlanningCommentSummary($remaining, $reestimated);
                $jira->addCommentToIssue($issueKey, $comment);
            } catch (\Throwable $e) {
                // Silencieux : la modification principale a réussi.
            }

            $response->getBody()->write(json_encode(['success' => true], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * POST /api/internal/issues/assign-sprint
     *
     * Déplace chaque ticket coché vers le sprint cible (API Agile) puis ajoute un commentaire de signature OPALE à titre de trace.
     * Le déplacement est l'action principale (échec → requête en échec)
     * Le commentaire est best-effort (un échec de commentaire n'invalide pas un déplacement déjà réussi), comme pour updateIssue().
     *
     * Corps attendu (form-urlencoded) : `issueKeys[]` (1..n clés), `sprintId`, `sprintName`.
     * Répond un JSON {success:bool, error?:string}.
     */
    public function assignToSprint(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $data = (array) ($request->getParsedBody() ?? []);

            $issueKeys = $data['issueKeys'] ?? [];
            if (!is_array($issueKeys)) {
                $issueKeys = [$issueKeys];
            }
            $issueKeys = array_values(array_unique(array_filter(array_map(
                static fn (mixed $k): string => trim((string) $k),
                $issueKeys
            ))));

            $sprintId   = trim((string) ($data['sprintId'] ?? ''));
            $sprintName = trim((string) ($data['sprintName'] ?? ''));

            if ($issueKeys === [] || $sprintId === '' || $sprintName === '') {
                throw new \Exception('Tickets, sprint cible et nom de sprint requis.');
            }

            $jira = new JiraService();
            $jira->moveIssuesToSprint($sprintId, $issueKeys);

            // Best-effort : signe l'action dans l'historique Jira. Le déplacement principal
            // a déjà réussi à ce stade ; un échec de commentaire ne doit pas faire échouer
            // la requête (même logique que updateIssue()).
            $comment = JiraService::OPALE_COMMENT_PREFIX . 'affectation au sprint ' . $sprintName;
            foreach ($issueKeys as $issueKey) {
                try {
                    $jira->addCommentToIssue($issueKey, $comment);
                } catch (\Throwable $e) {
                    // Silencieux : le déplacement de sprint a réussi.
                }
            }

            $response->getBody()->write(json_encode(['success' => true], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * Réglages de planification injectés au composant Alpine : marge persistée dans la config d'équipe
     * et suggestion de capacité pour le prochain sprint détecté.
     *
     * La détection du prochain sprint est faite ICI, côté serveur, et sert à la fois la suggestion de
     * capacité et le CTA « Ajouter au prochain sprint » : une règle unique, donc aucun risque que la
     * suggestion et le déplacement réel des tickets visent deux sprints différents.
     *
     * Best-effort : cette détection déclenche un appel Agile supplémentaire ; un échec ne doit pas
     * empêcher l'affichage de la page, dont les données principales sont déjà chargées. On dégrade
     * alors en `suggestion: null` — la capacité se saisit à la main et l'IHM le signale.
     *
     * @return array{projectKey:string, margin:int, suggestion:array{sprintId:string, sprintName:string, capaTotal:float|null, avgVelocity:float|null, suggestedCapacity:float|null}|null}
     */
    private function buildPlanningConfig(string $projectKey, string $boardId): array
    {
        $suggestion = null;

        if ($projectKey !== '' && $boardId !== '') {
            try {
                $boardSprints = (new BoardRepository())->getBoardSprints($boardId, $projectKey);
                $suggestion   = (new PlanningCapacitySuggester())->suggest($projectKey, $boardSprints);
            } catch (\Throwable) {
                // Silencieux : dégradation de confort, la page reste pleinement utilisable.
            }
        }

        return [
            'projectKey' => $projectKey,
            'margin'     => (new TeamConfigRepository())->getPlanningMargin($projectKey),
            'suggestion' => $suggestion,
        ];
    }

    /**
     * POST /api/internal/projects/{projectKey}/planning-margin
     *
     * Persiste la marge (%) appliquée à la capacité brute sur la page /planification, dans la config
     * d'équipe (clé racine `planningMargin`). Ce réglage est partagé par toute l'équipe : il n'est plus
     * mémorisé dans le navigateur.
     *
     * Corps attendu (form-urlencoded) : `margin`, entier de 0 à 100.
     * Répond un JSON {success:bool, margin?:int, error?:string}.
     *
     * @param array<string, string> $args ['projectKey' => 'ABC']
     */
    public function savePlanningMargin(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');
            if ($projectKey === '' || preg_match('/^[A-Za-z0-9_]+$/', $projectKey) !== 1) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $repo = new TeamConfigRepository();
            // Garde-fou : ne jamais écraser une config présente mais corrompue par une sauvegarde
            // partielle (qui repartirait d'un load() vide → perte du reste de la config).
            if ($repo->isCorrupted($projectKey)) {
                return $this->json($response, [
                    'success' => false,
                    'error'   => 'Configuration du projet illisible (fichier corrompu) : sauvegarde bloquée '
                        . 'pour éviter toute perte. Restaurez le fichier avant de modifier.',
                ], 409);
            }

            $data = (array) ($request->getParsedBody() ?? []);
            $raw  = $data['margin'] ?? null;
            if (!is_numeric($raw)) {
                return $this->json($response, [
                    'success' => false,
                    'error'   => 'Marge invalide : un entier entre 0 et 100 est attendu.',
                ], 400);
            }

            $margin = (int) round((float) $raw);
            if ($margin < 0 || $margin > 100) {
                return $this->json($response, [
                    'success' => false,
                    'error'   => 'Marge invalide : un entier entre 0 et 100 est attendu.',
                ], 400);
            }

            $repo->setPlanningMargin($projectKey, $margin);

            return $this->json($response, ['success' => true, 'margin' => $margin]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Écrit une réponse JSON et positionne l'en-tête / le code HTTP.
     *
     * @param array<string, mixed> $payload Charge utile à encoder
     *
     * @throws \JsonException Si la charge utile ne peut pas être encodée en JSON
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Normalise une valeur de planification reçue du formulaire.
     *
     * Un champ vidé côté UI (chaîne vide ou paramètre absent) devient null : la valeur Jira
     * correspondante sera effacée (et non remise à 0). Toute autre valeur doit être numérique.
     *
     * @throws \Exception Si la valeur est présente mais non numérique
     */
    private function parsePlanningValue(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new \Exception('Valeur de planification invalide : un nombre ou un champ vide est attendu.');
        }

        return (float) $raw;
    }

    /**
     * Construit le résumé d'action accolé au préfixe de signature OPALE, pour le commentaire poussé
     * sur le ticket après mise à jour.
     *
     * Une valeur null signifie que le champ a été effacé côté Jira (et non mis à 0) : on l'indique
     * explicitement par « effacé ».
     */
    private function buildPlanningCommentSummary(?float $remaining, ?float $reestimated): string
    {
        // Cast en string d'un float entier → "3" (et non "3.0") ; "2.5" reste "2.5".
        $format = static fn (?float $value): string => $value === null ? 'effacé' : (string) $value;

        return 'Reste à faire : ' . $format($remaining) . ' ; '
            . 'Points rééstimés (final) : ' . $format($reestimated) . '.';
    }
}
