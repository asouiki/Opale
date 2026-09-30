<?php

namespace App\View;

use App\Model\AbstractIssueCollection;
use App\Model\Config;
use App\Model\Timeline;

/**
 * AbstractCollectionView
 *
 * Formate et expose au front les données communes à toute collection d'issues
 * (métriques, timelines, VSM).
 */
abstract class AbstractCollectionView
{
    // Icônes de catégorie, libellés de statut et résolution de catégorie (partagés avec TeamMetricsView).
    use StatusPresenterTrait;

    protected Config $config;
    protected AbstractIssueCollection $collection;

    public function __construct(AbstractIssueCollection $collection)
    {
        $this->config     = new Config();
        $this->collection = $collection;
    }

    // ==================== Issues ====================

    public function getIssues(): array
    {
        return $this->collection->getIssues();
    }
    public function getIssuesCount(): int
    {
        return $this->collection->getIssuesCount();
    }

    // ==================== Métriques ====================

    /** LEAD TIME **/
    public function getTotalLeadTime(): float
    {
        return $this->collection->getTotalLeadTime();
    }
    public function getAverageLeadTime(): float
    {
        return $this->collection->getAverageLeadTime();
    }
    public function getMedianLeadTime(): float
    {
        return $this->collection->getMedianLeadTime();
    }

    /** CYCLE TIME **/
    public function getTotalCycleTime(): float
    {
        return $this->collection->getTotalCycleTime();
    }
    public function getAverageCycleTime(): float
    {
        return $this->collection->getAverageCycleTime();
    }
    public function getMedianCycleTime(): float
    {
        return $this->collection->getMedianCycleTime();
    }

    /** WAITING TIME **/
    public function getTotalWaitingTime(): float
    {
        return $this->collection->getTotalWaitingTime();
    }
    public function getAverageWaitingTime(): float
    {
        return $this->collection->getAverageWaitingTime();
    }

    /** TIME BREAKDOWN **/
    public function getTotalTimeSpentInRefinement(): float
    {
        return $this->collection->getTotalTimeSpentInRefinement();
    }
    public function getAverageTimeSpentInRefinement(): float
    {
        return $this->collection->getAverageTimeSpentInRefinement();
    }
    public function getTotalTimeSpentInSprint(): float
    {
        return $this->collection->getTotalTimeSpentInSprint();
    }
    public function getAverageTimeSpentInSprint(): float
    {
        return $this->collection->getAverageTimeSpentInSprint();
    }
    public function getTotalTimeSpentInOther(): float
    {
        return $this->collection->getTotalTimeSpentInOther();
    }
    public function getAverageTimeSpentInOther(): float
    {
        return $this->collection->getAverageTimeSpentInOther();
    }
    public function getTotalTimeSpentInWaiting(): float
    {
        return $this->collection->getTotalTimeSpentInWaiting();
    }
    public function getAverageTimeSpentInWaiting(): float
    {
        return $this->collection->getAverageTimeSpentInWaiting();
    }

    /** FLOW EFFICIENCY **/
    public function getTotalProductionTime(): float
    {
        $cycleTime   = $this->collection->getTotalCycleTime();
        $waitingTime = $this->collection->getTotalTimeSpentInWaiting();
        return round(max(0.0, $cycleTime - $waitingTime), 2);
    }

    public function getFlowEfficiency(): string
    {
        $cycleTime = $this->collection->getTotalCycleTime();
        if ($cycleTime <= 0) {
            return 'N/A';
        }
        $productionTime = max(0.0, $cycleTime - $this->collection->getTotalTimeSpentInWaiting());
        return number_format(($productionTime / $cycleTime) * 100, 1, '.', '') . '%';
    }

    // ==================== Timeline ====================

    public function getTimelineByStatus(): array
    {
        return $this->collection->getTimelineByStatus();
    }
    public function getAverageTimeByStatus(): array
    {
        return $this->collection->getAverageTimeByStatus();
    }
    public function getAggregatedWaitingTimes(): array
    {
        return $this->collection->getAggregatedWaitingTimes();
    }

    // ==================== VSM ====================

    /**
     * Retourne les étapes du Value Stream Map prêtes à être affichées.
     *
     * @return array<int, array{key: string, label: string, average_days: float, category: string}>
     */
    public function getVsmSteps(): array
    {
        $timeline            = $this->collection->getTimelineByStatus();
        $averageTimeByStatus = $this->collection->getAverageTimeByStatus();

        $steps = [];
        foreach ($timeline['workflowStatuses'] as $statusName => $metrics) {
            $steps[] = [
                'key'          => $statusName,
                'label'        => $this->normalizeStatusName($statusName),
                'average_days' => $averageTimeByStatus[$statusName] ?? '?',
                'category'     => $this->getStatusCategory($statusName),
            ];
        }

        return $steps;
    }
}
