<?php

namespace App\Controller;

use App\Model\Config;
use App\Model\Sprint;
use App\Model\TeamMetrics;
use App\Service\LlmPromptRepository;
use App\Service\MetricsDigestBuilder;
use App\Service\SprintCacheService;
use App\Service\SprintConfigRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PromptExportController
 *
 * Exporte, en téléchargement .txt, un prompt d'analyse prêt à coller dans une IA Web (Claude,
 * ChatGPT, Gemini, Copilot...) : le prompt versionné config_files/llm_export_prompt.md suivi du
 * digest chiffré des métriques de la page (/sprint ou /team-metrics).
 *
 * Complète la synthèse IA locale (LlmService) sans en dépendre : l'export reste disponible même
 * quand LLM_ENABLED est à false, et permet de soumettre les mêmes chiffres à un modèle plus capable.
 */
class PromptExportController
{
    /** Nom du prompt versionné injecté en tête de l'export. */
    private const PROMPT_FILE = 'llm_export_prompt.md';

    /** Longueur maximale du nom de fichier généré (extension comprise), pour rester lisible et portable. */
    private const MAX_FILENAME_LENGTH = 120;

    /**
     * GET /api/internal/projects/{projectKey}/sprints/{sprintId}/export-prompt?boardId=...
     *
     * Exporte le prompt d'analyse d'un sprint unique.
     *
     * @param array<string, string> $args Paramètres de route (projectKey, sprintId)
     */
    public function sprint(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $sprintId   = trim((string) ($args['sprintId'] ?? ''));
        $boardId    = trim((string) ($request->getQueryParams()['boardId'] ?? ''));

        try {
            if ($sprintId === '') {
                throw new \InvalidArgumentException('Le paramètre sprintId est requis.');
            }

            // Active la résolution du workflow propre au projet (statuts done/attente...).
            Config::setCurrentProjectKey($projectKey);

            $sprint = $this->loadSprint($sprintId, $projectKey, $boardId);

            // Capacité (jours/homme + points) : hors snapshot du sprint, stockée dans la config d'équipe.
            $config         = new SprintConfigRepository();
            $manDays        = $config->getManDays($projectKey, $sprintId);
            $capacityPoints = $config->getCapacityPoints($projectKey, $sprintId);

            $digest   = (new MetricsDigestBuilder())->forSprint($sprint, $manDays, $capacityPoints);
            $content  = $this->render('un sprint', $digest);
            $fileName = $this->buildFileName($projectKey, 'sprint', [$sprint->getName()]);

            return $this->download($response, $content, $fileName);
        } catch (\Throwable $e) {
            return $this->failure($response, $e->getMessage());
        }
    }

    /**
     * GET /api/internal/projects/{projectKey}/team-metrics/export-prompt?sprintIds[]=...&boardId=...
     *
     * Exporte le prompt d'analyse d'une sélection de sprints (mêmes agrégats que la page).
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     */
    public function teamMetrics(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectKey = trim((string) ($args['projectKey'] ?? ''));
        $query      = $request->getQueryParams();
        $boardId    = trim((string) ($query['boardId'] ?? ''));

        $sprintIds = $query['sprintIds'] ?? [];
        if (!is_array($sprintIds)) {
            $sprintIds = [$sprintIds];
        }
        $sprintIds = array_values(array_filter(array_map('trim', array_map('strval', $sprintIds))));

        try {
            if ($sprintIds === []) {
                throw new \InvalidArgumentException('Sélectionnez au moins un sprint clôturé.');
            }

            // Active la résolution du workflow propre au projet (statuts done/attente...).
            Config::setCurrentProjectKey($projectKey);

            // Agrégat d'équipe : TeamMetrics recharge chaque sprint cache-first, comme la page.
            $metrics = new TeamMetrics($sprintIds, $projectKey, $boardId);

            $scope   = 'une sélection de ' . count($sprintIds) . ' sprints';
            $content = $this->render($scope, (new MetricsDigestBuilder())->forTeam($metrics));

            $names = array_values(array_map(
                static fn (array $sprint): string => (string) ($sprint['name'] ?? ''),
                $metrics->getSprints()
            ));

            return $this->download($response, $content, $this->buildFileName($projectKey, 'sprints', $names));
        } catch (\Throwable $e) {
            return $this->failure($response, $e->getMessage());
        }
    }

    /**
     * Charge un sprint en réutilisant la stratégie cache-first de la page /sprint : un sprint clôturé
     * est immuable, son snapshot évite un aller-retour Jira ; un sprint en cours est toujours relu.
     */
    private function loadSprint(string $sprintId, string $projectKey, string $boardId): Sprint
    {
        $cache  = new SprintCacheService();
        $cached = $cache->get($sprintId, $projectKey);

        if ($cached !== null) {
            return Sprint::fromSnapshot($cached['data']);
        }

        $sprint = new Sprint($sprintId, $projectKey, $boardId);

        if ($sprint->getState() === Sprint::STATE_CLOSED) {
            $cache->put($sprintId, $sprint->toSnapshot(), $projectKey);
        }

        return $sprint;
    }

    /**
     * Assemble le contenu exporté : prompt versionné (dont le marqueur {{SCOPE}} est substitué par la
     * nature de la sélection) puis le digest chiffré, séparés par une règle Markdown.
     *
     * @param  string $scopeLabel Nature des données analysées (« un sprint », « une sélection de 6 sprints »)
     * @param  string $digest     Digest produit par MetricsDigestBuilder
     *
     * @throws \RuntimeException Si le prompt versionné est absent ou vide
     */
    private function render(string $scopeLabel, string $digest): string
    {
        $prompt = (new LlmPromptRepository())->load(self::PROMPT_FILE);

        return str_replace('{{SCOPE}}', $scopeLabel, $prompt)
            . "\n---\n\n## Métriques\n\n" . $digest . "\n";
    }

    /**
     * Construit le nom du fichier exporté : prompt_<projet>_<sprint|sprints>_<noms>.txt.
     *
     * Les noms sont translittérés en ASCII et débarrassés de tout caractère susceptible de casser
     * l'en-tête Content-Disposition (guillemets, retours ligne) ou le système de fichiers. Au-delà de
     * MAX_FILENAME_LENGTH, la liste est tronquée et suffixée du nombre de sprints restants.
     *
     * @param list<string> $sprintNames Noms des sprints concernés, dans l'ordre d'affichage
     */
    private function buildFileName(string $projectKey, string $kind, array $sprintNames): string
    {
        $prefix = 'prompt_' . ($this->slugify($projectKey) ?: 'projet') . '_' . $kind . '_';

        $slugs = array_values(array_filter(array_map(fn (string $name): string => $this->slugify($name), $sprintNames)));

        if ($slugs === []) {
            return rtrim($prefix, '_') . '.txt';
        }

        $kept = [];
        foreach ($slugs as $index => $slug) {
            $remaining = count($slugs) - ($index + 1);
            // Le suffixe « _et-N-autres » n'est réservé que s'il reste effectivement des sprints à écarter.
            $reserve   = $remaining > 0 ? strlen('_et-' . $remaining . '-autres') : 0;
            $candidate = $prefix . implode('_', [...$kept, $slug]) . '.txt';

            if (strlen($candidate) + $reserve > self::MAX_FILENAME_LENGTH) {
                break;
            }

            $kept[] = $slug;
        }

        // Aucun nom ne tient (nom unique très long) : on tronque le premier plutôt que rendre un nom vide.
        if ($kept === []) {
            $kept[] = substr($slugs[0], 0, max(1, self::MAX_FILENAME_LENGTH - strlen($prefix) - 4));
        }

        $dropped = count($slugs) - count($kept);

        return $prefix . implode('_', $kept) . ($dropped > 0 ? '_et-' . $dropped . '-autres' : '') . '.txt';
    }

    /**
     * Réduit une chaîne libre (nom de sprint Jira, clé projet) à un fragment de nom de fichier sûr :
     * accents translittérés, tout caractère hors [A-Za-z0-9._-] remplacé par un tiret, tirets compactés.
     */
    private function slugify(string $value): string
    {
        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];

        $slug = strtr(mb_strtolower($value, 'UTF-8'), $accents);
        $slug = (string) preg_replace('/[^a-z0-9._-]+/u', '-', $slug);
        $slug = (string) preg_replace('/-{2,}/', '-', $slug);

        return trim($slug, '-._');
    }

    /**
     * Renvoie le contenu en téléchargement de fichier texte.
     */
    private function download(ResponseInterface $response, string $content, string $fileName): ResponseInterface
    {
        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Réponse d'erreur lisible : l'export est déclenché par un lien, l'utilisateur doit voir la cause
     * en clair plutôt qu'une page blanche.
     */
    private function failure(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write('Export impossible : ' . $message);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus(400);
    }
}
