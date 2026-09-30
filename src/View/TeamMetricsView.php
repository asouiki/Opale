<?php

namespace App\View;

use App\Model\Config;
use App\Model\JiraUrl;
use App\Model\TeamMetrics;

/**
 * TeamMetricsView
 *
 * Expose et formate les données d'un TeamMetrics pour le template
 * /views/team-metrics.phtml.
 *
 * Toute la logique de calcul reste dans le modèle TeamMetrics : cette vue ne
 * fait que de la présentation (formatage des nombres, dates, libellés).
 */
class TeamMetricsView
{
    use ViewFormatterTrait;
    // Icônes/catégories de statut : nécessaire à l'affichage des causes d'attente cumulées.
    use StatusPresenterTrait;

    private TeamMetrics $metrics;

    /** Workflow du projet — requis par StatusPresenterTrait (catégorie et icône d'une cause d'attente). */
    protected Config $config;

    public function __construct(TeamMetrics $metrics)
    {
        $this->metrics = $metrics;
        $this->config  = new Config();
    }

    // ==================== Données métier (pass-through) ====================

    public function getStats(): array
    {
        return $this->metrics->getStats();
    }

    public function getSprints(): array
    {
        return $this->metrics->getSprints();
    }

    /** Clé du projet Jira — pour construire l'URL de saisie des jours/homme. */
    public function getProjectKey(): string
    {
        return $this->metrics->getProjectKey();
    }

    public function getCycleTimeBySp(): array
    {
        return $this->metrics->getCycleTimeBySp();
    }

    /**
     * Temps d'attente cumulé par cause sur les sprints sélectionnés (jours ouvrés), du plus coûteux
     * au moins coûteux — alimente la section « Causes d'attente ».
     *
     * @return array<string, float>
     */
    public function getAggregatedWaitingTimes(): array
    {
        return $this->metrics->getAggregatedWaitingTimes();
    }

    /**
     * Base d'URL « browse » Jira (avec slash final), pour construire côté client
     * le lien d'un ticket à partir de sa clé : baseUrl + key.
     *
     * @return string Ex. « https://exemple.atlassian.net/browse/ » ; chaîne vide
     *                si JIRA_BASE_URL n'est pas configuré (clic désactivé).
     */
    public function getJiraBrowseBaseUrl(): string
    {
        $base = rtrim(Config::jiraBaseUrl(), '/');

        // Préfixe (sans clé) : la clé du ticket est concaténée côté client (JS).
        return $base === '' ? '' : JiraUrl::browse('');
    }

    /**
     * Nuage de points (effort × cycle time) groupé par type de ticket, prêt pour
     * Chart.js : une entrée par type, chacune portant son libellé, sa couleur et
     * ses points {x, y, key}.
     *
     * Seuls les types réellement présents dans les tickets terminés produisent
     * une entrée (pas de filtre vide). L'ordre suit la position du type dans la
     * config (config_files/jira_issues_types.json) ; la couleur est fixée par un
     * mapping explicite par nom de type (les types non mappés retombent sur une
     * palette de repli). « Story » garde ainsi la même couleur d'une sélection à
     * l'autre. Les types hors config sont placés en fin de liste (déjà triés par
     * fréquence par le modèle).
     *
     * @return array<int, array{type:string, color:string, points:array}>
     */
    public function getCycleTimeScatterDatasets(): array
    {
        // Couleur explicite par type de ticket (demande métier).
        $colorByType = [
            'Story'         => '#94C748', // vert
            'Tâche'         => '#2563eb', // bleu
            'Bug'           => '#ef4444', // rouge
            'Enabler Story' => '#f59e0b', // orange
            'Spike'         => '#111827', // noir
            'Release'       => '#8b5cf6', // violet
        ];

        // Couleur de repli (gris) pour les types non mappés (Task, Feature, Innovation...).
        $fallbackColor = '#6b7280';

        $order   = (new Config())->getMetricIssueTypes(); // ordre canonique des types
        $scatter = $this->metrics->getCycleTimeScatterByType(); // [type => points]

        // Couleur du type : mapping explicite, sinon gris de repli.
        $colorFor = static fn (string $type): string => $colorByType[$type] ?? $fallbackColor;

        $datasets = [];

        // 1) Types de la config présents dans les données, dans l'ordre de la config.
        foreach ($order as $type) {
            if (isset($scatter[$type])) {
                $datasets[] = ['type' => $type, 'color' => $colorFor($type), 'points' => $scatter[$type]];
            }
        }

        // 2) Types hors config éventuels (fallback « Autre », types non listés).
        foreach ($scatter as $type => $points) {
            if (!in_array($type, $order, true)) {
                $datasets[] = ['type' => $type, 'color' => $colorFor($type), 'points' => $points];
            }
        }

        return $datasets;
    }

    /**
     * Série chronologique de la prédictibilité, pour le graphique d'évolution.
     *
     * Les sprints sont triés du plus ancien au plus récent (par date de clôture,
     * à défaut date de fin) afin que la courbe se lise de gauche à droite dans
     * l'ordre du temps — l'ordre de sélection du formulaire (plus récent d'abord)
     * n'est pas pertinent pour une tendance.
     *
     * `urls` : page /sprint de chaque point (même ordre), ouverte au clic sur le point du graphique.
     *
     * @return array{labels: array<int, string>, values: array<int, float>, urls: array<int, string>}
     */
    public function getPredictabilityTrend(): array
    {
        $sprints = $this->sprintsChronological();

        return [
            'labels' => array_map(static fn (array $s): string => $s['name'], $sprints),
            'values' => array_map(static fn (array $s): float => (float) $s['predictabilityPercent'], $sprints),
            'urls'   => array_map(fn (array $s): string => $this->getSprintPageUrl((string) $s['id']), $sprints),
        ];
    }

    /**
     * URL de la page /sprint d'un sprint, via son lien profond (?projectKey=&boardId=&sprintId=) qui lance l'analyse
     * automatiquement. Ouvre toujours l'analyse nominale : une photo « à date » ne s'y rouvre pas par lien.
     */
    public function getSprintPageUrl(string $sprintId): string
    {
        return '/sprint?' . http_build_query([
            'projectKey' => $this->metrics->getProjectKey(),
            'boardId'    => $this->metrics->getBoardId(),
            'sprintId'   => $sprintId,
        ]);
    }

    /**
     * Série chronologique de l'engagement (tickets planifiés terminés / planifiés,
     * en %), pour le graphique d'évolution iso-prédictibilité.
     *
     * Même tri et même structure que {@see self::getPredictabilityTrend()} : on
     * lit la courbe de gauche à droite dans l'ordre du temps.
     *
     * @return array{labels: array<int, string>, values: array<int, float>, urls: array<int, string>}
     */
    public function getEngagementTrend(): array
    {
        $sprints = $this->sprintsChronological();

        return [
            'labels' => array_map(static fn (array $s): string => $s['name'], $sprints),
            'values' => array_map(static fn (array $s): float => (float) $s['engagementPercent'], $sprints),
            'urls'   => array_map(fn (array $s): string => $this->getSprintPageUrl((string) $s['id']), $sprints),
        ];
    }

    /**
     * Sprints triés du plus ancien au plus récent (par date de clôture, à défaut
     * date de fin). Base commune des graphiques d'évolution (prédictibilité,
     * engagement…), pour que les courbes se lisent dans l'ordre du temps.
     *
     * @return array Liste des résumés de sprint triés chronologiquement
     */
    private function sprintsChronological(): array
    {
        $sprints = $this->metrics->getSprints();

        usort($sprints, static function (array $a, array $b): int {
            $keyA = $a['completeDate'] ?: ($a['endDate'] ?: '');
            $keyB = $b['completeDate'] ?: ($b['endDate'] ?: '');
            return strcmp((string) $keyA, (string) $keyB);
        });

        return $sprints;
    }

    public function getErrors(): array
    {
        return $this->metrics->getErrors();
    }

    public function getSprintCount(): int
    {
        return $this->metrics->getStats()['sprintCount'] ?? 0;
    }

    // ==================== Helpers de formatage ====================
    // formatNumber() et formatPercent() vivent maintenant dans ViewFormatterTrait (partagées avec
    // PlanificationView, pour le % de préparation du backlog).

    /**
     * Export d'un prompt d'analyse à coller dans une IA Web : indépendant de la synthèse IA
     * locale, donc affiché même quand LLM_ENABLED est à false.
     */
    public function getExportPromptUrl(): string
    {
        return '/api/internal/projects/' . rawurlencode($this->getProjectKey())
            . '/team-metrics/export-prompt?'
            . http_build_query(['sprintIds' => array_map(
                static fn (array $s): string => (string) $s['id'],
                $this->getSprints()
            )]);
    }
}
