<?php

namespace App\Model;

use App\Service\JiraService;
use App\Service\JiraVersionService;

/**
 * AbstractIssueCollection
 *
 * Classe de base pour tout agrégat d'issues Jira avec calcul de métriques.
 *
 * Pattern Template Method :
 * Le constructeur orchestre le cycle de vie (chargement → calculs).
 * Chaque sous-classe implémente uniquement le chargement de ses données propres.
 */
abstract class AbstractIssueCollection
{
    protected JiraService $jiraService;
    protected JiraVersionService $jiraVersionService;
    protected Config $config;
    protected Timeline $timeline;

    /** @var Issue[] */
    protected array $issues = [];
    protected int $issuesCount = 0;

    /** @var Issue[] Toutes les issues chargées, avant filtrage par type — affichage exhaustif (page Version) */
    protected array $allIssues = [];

    protected float $totalLeadTime = 0.0;
    protected float $averageLeadTime = 0.0;
    protected float $medianLeadTime = 0.0;
    protected float $totalCycleTime = 0.0;
    protected float $averageCycleTime = 0.0;
    protected float $medianCycleTime = 0.0;
    protected float $totalTimeSpentInRefinement = 0.0;
    protected float $averageTimeSpentInRefinement = 0.0;
    protected float $totalTimeSpentInSprint = 0.0;
    protected float $averageTimeSpentInSprint = 0.0;
    protected float $totalTimeSpentInOther = 0.0;
    protected float $averageTimeSpentInOther = 0.0;
    protected float $totalTimeSpentInWaiting = 0.0;
    protected float $averageTimeSpentInWaiting = 0.0;
    protected float $medianWaitingTime = 0.0;

    protected array $timelineByStatus = [];
    protected array $averageTimeByStatus = [];
    protected array $aggregatedWaitingTimes = [];

    /**
     * Constructeur — orchestre le cycle de vie via Template Method.
     *
     * @param string $id Identifiant numérique Jira (Version ID ou Issue ID)
     */
    public function __construct(string $id)
    {
        $this->bootServices();

        $this->loadCollectionData($id);
        $this->loadIssues($id);
        $this->computeAggregates();
    }

    /**
     * Instancie les services et utilitaires partagés.
     * Isolé du constructeur pour permettre l'hydratation d'une collection sans rejouer les accès Jira.
     */
    protected function bootServices(): void
    {
        $this->jiraService        = new JiraService();
        $this->jiraVersionService = new JiraVersionService();
        $this->config             = new Config();
        $this->timeline           = new Timeline();
    }

    /**
     * Calcule l'ensemble des agrégats (Lead/Cycle/Waiting, timelines) à partir de $this->issues.
     * Étape finale du cycle de vie, réutilisée telle quelle lors d'une hydratation depuis le cache.
     */
    protected function computeAggregates(): void
    {
        $this->filterIssuesForMetrics();
        $this->calculateLeadAndCycleTime();
        $this->calculateTimelineByStatus();
        $this->calculateAverageTimeByStatus();
    }

    /**
     * Charge les données propres à la collection (Version ou Feature).
     * Ne doit PAS charger les issues — c'est le rôle de loadIssues().
     */
    abstract protected function loadCollectionData(string $id): void;

    /**
     * Charge les issues associées et peuple $this->issues + $this->issuesCount.
     */
    abstract protected function loadIssues(string $id): void;

    /**
     * Restreint $this->issues aux seuls types d'issues retenus pour le calcul
     * des métriques (config jira_issues_types.json).
     *
     * Étape du cycle de vie (Template Method) : invoquée par le constructeur
     * juste après loadIssues(), avant les calculs de métriques.
     *
     * Algorithme :
     * 1. Mémorise la liste complète dans $this->allIssues — la page Version
     *    s'en sert pour afficher TOUS les tickets quel que soit leur type.
     * 2. Config vide/absente → aucun filtrage (comportement existant préservé).
     * 3. Sinon, ne conserve que les issues dont le type figure dans la config,
     *    puis recalcule $this->issuesCount sur la liste filtrée.
     *
     * Note : le type « Feature » doit figurer dans la config, sinon la page PI
     * (ProgramIncrement, agrégat de Features) verrait toutes ses issues exclues.
     */
    protected function filterIssuesForMetrics(): void
    {
        $this->allIssues = $this->issues;

        $allowedTypes = $this->config->getMetricIssueTypes();
        if ($allowedTypes === []) {
            return;
        }

        $this->issues = array_values(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => in_array($issue->getIssueTypeName(), $allowedTypes, true)
        ));

        $this->issuesCount = count($this->issues);
    }

    /**
     * Calcule Lead Time, Cycle Time et les temps par phase
     * en agrégeant les métriques de chaque issue.
     */
    protected function calculateLeadAndCycleTime(): void
    {
        if ($this->issuesCount === 0) {
            return;
        }

        $leadTimes = [];
        $cycleTimes = [];
        $waitingTimes = [];
        foreach ($this->issues as $issue) {
            $this->totalLeadTime               += $issue->getLeadTime();
            $this->totalCycleTime              += $issue->getCycleTime();
            $this->totalTimeSpentInRefinement  += $issue->getTimeSpentInRefinement();
            $this->totalTimeSpentInSprint      += $issue->getTimeSpentInSprint();
            $this->totalTimeSpentInOther       += $issue->getTimeSpentInOther();

            $this->calcAndaggregateWaitingTimes($issue);

            // Stocke lead/cycle time de chaque issue pour le calcul de la médiane.
            if ($issue->getLeadTime() !== 0.0) {
                $leadTimes[]  = $issue->getLeadTime();
            }
            if ($issue->getCycleTime() !== 0.0) {
                $cycleTimes[] = $issue->getCycleTime();
            }

            // Temps d'attente total du ticket (union dédupliquée des périodes) pour la médiane.
            $issueWaiting = $issue->getTimeSpentInWaiting();
            if ($issueWaiting !== 0.0) {
                $waitingTimes[] = $issueWaiting;
            }
        }

        $this->averageLeadTime  = round($this->totalLeadTime / $this->issuesCount, 2);
        $this->averageCycleTime = round($this->totalCycleTime / $this->issuesCount, 2);
        $this->averageTimeSpentInWaiting = round($this->totalTimeSpentInWaiting / $this->issuesCount, 2);

        $this->medianLeadTime    = self::calculateMedian($leadTimes);
        $this->medianCycleTime   = self::calculateMedian($cycleTimes);
        $this->medianWaitingTime = self::calculateMedian($waitingTimes);
    }

    /**
     * Agrège les temps d'attente d'une issue dans les totaux de la collection.
     *
     * - Ventilation par cause (aggregatedWaitingTimes) : additive, alimentée par la map
     *   $issue->getWaitingTimes() (statuts + labels + flag).
     * - Total (totalTimeSpentInWaiting) : somme des totaux PAR ISSUE dédupliqués (union des
     *   périodes) via getTimeSpentInWaiting(), pour ne pas compter deux fois un jour recouvert
     *   par plusieurs causes au sein d'une même issue.
     */
    private function calcAndaggregateWaitingTimes(Issue $issue): void
    {
        foreach ($issue->getWaitingTimes() as $label => $days) {
            $this->aggregatedWaitingTimes[$label] = ($this->aggregatedWaitingTimes[$label] ?? 0.0) + $days;
        }
        $this->totalTimeSpentInWaiting += $issue->getTimeSpentInWaiting();
    }

    /**
     * Calcule le temps cumulé par statut Jira, agrégé sur toutes les issues.
     */
    protected function calculateTimelineByStatus(): void
    {
        foreach ($this->issues as $issue) {
            foreach ($issue->getTimeByStatus(false) as $statusName => $timeSpent) {
                $this->timelineByStatus[$statusName] = ($this->timelineByStatus[$statusName] ?? 0) + $timeSpent;
            }
        }
    }

    /**
     * Calcule le temps moyen par statut et par phase (refinement, sprint, other).
     */
    protected function calculateAverageTimeByStatus(): void
    {
        if ($this->issuesCount === 0) {
            return;
        }

        foreach ($this->timelineByStatus as $statusName => $timeSpent) {
            $this->averageTimeByStatus[$statusName] = round($timeSpent / $this->issuesCount, 2);
        }

        $this->averageTimeSpentInRefinement = round($this->totalTimeSpentInRefinement / $this->issuesCount, 2);
        $this->averageTimeSpentInSprint     = round($this->totalTimeSpentInSprint / $this->issuesCount, 2);
        $this->averageTimeSpentInOther      = round($this->totalTimeSpentInOther / $this->issuesCount, 2);
        $this->averageTimeSpentInWaiting    = round($this->totalTimeSpentInWaiting / $this->issuesCount, 2);
    }


    /**
     * Médiane d'un tableau de valeurs numériques ; 0.0 pour un tableau vide (absence de donnée →
     * médiane neutre).
     *
     * Statique publique : également consommée par les calculateurs extraits (SprintMetricsCalculator).
     *
     * @param array<int, float> $values
     */
    public static function calculateMedian(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sorted = $values;
        sort($sorted, SORT_NUMERIC);

        $count = count($sorted);
        $middleIndex = intdiv($count, 2);

        // Si le nombre de valeurs est pair, on retourne la moyenne des deux valeurs centrales.
        // Si impair, on retourne la valeur du milieu
        return ($count % 2 === 0)
            ? ($sorted[$middleIndex - 1] + $sorted[$middleIndex]) / 2
            : $sorted[$middleIndex];
    }

    // ==================== Getters ====================

    /** @return Issue[] */
    public function getIssues(): array
    {
        return $this->issues;
    }
    public function getIssuesCount(): int
    {
        return $this->issuesCount;
    }

    /** LEAD TIME **/
    public function getTotalLeadTime(): float
    {
        return $this->totalLeadTime;
    }
    public function getAverageLeadTime(): float
    {
        return $this->averageLeadTime;
    }
    public function getMedianLeadTime(): float
    {
        return $this->medianLeadTime;
    }

    /** CYCLE TIME **/
    public function getTotalCycleTime(): float
    {
        return $this->totalCycleTime;
    }
    public function getAverageCycleTime(): float
    {
        return $this->averageCycleTime;
    }
    public function getMedianCycleTime(): float
    {
        return $this->medianCycleTime;
    }

    /** WAITING TIME **/
    public function getTotalWaitingTime(): float
    {
        return $this->totalTimeSpentInWaiting;
    }
    public function getAverageWaitingTime(): float
    {
        return $this->averageTimeSpentInWaiting;
    }
    public function getMedianWaitingTime(): float
    {
        return $this->medianWaitingTime;
    }

    /** TIME BREAKDOWN **/
    public function getTotalTimeSpentInRefinement(): float
    {
        return $this->totalTimeSpentInRefinement;
    }
    public function getAverageTimeSpentInRefinement(): float
    {
        return $this->averageTimeSpentInRefinement;
    }
    public function getTotalTimeSpentInSprint(): float
    {
        return $this->totalTimeSpentInSprint;
    }
    public function getAverageTimeSpentInSprint(): float
    {
        return $this->averageTimeSpentInSprint;
    }
    public function getTotalTimeSpentInOther(): float
    {
        return $this->totalTimeSpentInOther;
    }
    public function getAverageTimeSpentInOther(): float
    {
        return $this->averageTimeSpentInOther;
    }
    public function getTotalTimeSpentInWaiting(): float
    {
        return $this->totalTimeSpentInWaiting;
    }
    public function getAverageTimeSpentInWaiting(): float
    {
        return $this->averageTimeSpentInWaiting;
    }

    public function getTimelineByStatus(): array
    {
        return $this->timeline->getSortedTimelineByStatus($this->timelineByStatus);
    }

    public function getAverageTimeByStatus(): array
    {
        return $this->averageTimeByStatus;
    }

    /**
     * @return array<string, float> label => jours ouvrés cumulés, trié desc
     */
    public function getAggregatedWaitingTimes(): array
    {
        arsort($this->aggregatedWaitingTimes);
        return $this->aggregatedWaitingTimes;
    }
}
