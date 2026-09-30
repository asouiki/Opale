<?php

namespace App\View;

use App\Model\ProgramIncrement;

/**
 * ProgramIncrementView
 *
 * Expose les données d'un Program Increment (cumul des métriques des Features
 * sélectionnées) au template /pi.phtml.
 *
 * Les totaux (Lead/Cycle/Waiting Times) sont délégués à AbstractCollectionView.
 */
class ProgramIncrementView extends AbstractCollectionView
{
    private ProgramIncrement $pi;

    public function __construct(ProgramIncrement $pi)
    {
        parent::__construct($pi);
        $this->pi = $pi;
    }

    public function getProjectKey(): string
    {
        return $this->pi->getProjectKey();
    }

    public function getPiNumber(): string
    {
        return $this->pi->getPiNumber();
    }

    /**
     * @return array<int, array{key:string, name:string, status:string, url:string, leadTime:float, cycleTime:float, waitingTime:float, excluded:bool}>
     */
    public function getFeaturesDetails(): array
    {
        return $this->pi->getFeaturesDetails();
    }

    /**
     * Détails Features encodés JSON pour consommation Alpine.js (recalcul du Total
     * côté client en fonction des checkboxes).
     */
    public function getFeaturesDetailsAsJson(): string
    {
        return json_encode($this->getFeaturesDetails(), JSON_THROW_ON_ERROR);
    }
}
