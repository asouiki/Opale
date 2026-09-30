<?php

namespace App\View;

use App\Model\Planification;

/**
 * PlanificationView
 *
 * Expose et formate les données d'une Planification pour le template
 * /planification.phtml. Aucune logique métier : uniquement de la présentation.
 *
 * Contrairement à SprintView, ne dérive pas d'AbstractCollectionView : une
 * Planification agrège plusieurs sprints et n'est pas une collection d'issues.
 */
class PlanificationView
{
    use ViewFormatterTrait;

    private Planification $planification;

    public function __construct(Planification $planification)
    {
        $this->planification = $planification;
    }

    // ==================== Données métier (pass-through) ====================

    public function getCurrentSprintName(): string
    {
        return $this->planification->getCurrentSprintName();
    }

    /**
     * Le sprint « en cours » est-il réellement actif (non clôturé) ?
     * Conditionne l'avertissement affiché avant de déplacer son reliquat vers le prochain sprint.
     */
    public function isCurrentSprintActive(): bool
    {
        return $this->planification->isCurrentSprintActive();
    }

    /**
     * @return array{count:int, storyPoints:float, issues:array}
     */
    public function getCurrentSprintReliquat(): array
    {
        return $this->planification->getCurrentSprintReliquat();
    }

    /**
     * @return array<int, array{sprintId:string, name:string, planned:array, readiness:array{total:int, ready:int, percent:?float}}>
     */
    public function getBacklogSprints(): array
    {
        return $this->planification->getBacklogSprints();
    }

    /**
     * @return array{count:int, storyPoints:float}
     */
    public function getBacklogTotals(): array
    {
        return $this->planification->getBacklogTotals();
    }

    /**
     * Part agrégée des sujets prêts à être embarqués sur l'ensemble de la backlog (sur les totaux
     * bruts, pas une moyenne de pourcentages par sprint).
     *
     * @return array{total:int, ready:int, percent:?float}
     */
    public function getBacklogReadiness(): array
    {
        $total = 0;
        $ready = 0;

        foreach ($this->planification->getBacklogSprints() as $sprint) {
            $total += $sprint['readiness']['total'];
            $ready += $sprint['readiness']['ready'];
        }

        return [
            'total'   => $total,
            'ready'   => $ready,
            'percent' => $total > 0 ? round($ready / $total * 100, 1) : null,
        ];
    }

    // ==================== Helpers de formatage ====================

    /**
     * Classe CSS du badge de statut d'un ticket, avec deux surcharges propres à la planification, qui
     * font ressortir l'avancement de l'affinage directement dans la colonne Statut :
     *  - VERT   : dernier statut de la catégorie affinage (ex. « A planifier ») — le sujet est prêt ;
     *  - GRIS   : tout autre statut d'affinage — le sujet est encore en cours de préparation.
     * Les statuts hors affinage gardent la couleur de leur catégorie (sprint, terminé, attente).
     *
     * Le classement s'appuie sur `statusRank` (déjà calculé par SprintTicketBuilder, indexé sur
     * affinage + sprint + terminé) plutôt que sur le libellé du statut : insensible à la casse et aux
     * accents, et cohérent avec le % de préparation (\App\Model\Planification::computeReadiness()).
     *
     * @param array{statusRank?:int, statusCategory:string} $ticket Ticket rendu dans le tableau
     */
    public function getTicketStatusBadgeClass(array $ticket): string
    {
        $rank               = $ticket['statusRank'] ?? PHP_INT_MAX;
        $lastRefinementRank = $this->planification->getLastRefinementRank();

        if ($rank === $lastRefinementRank) {
            return 'green';
        }
        if ($rank < $lastRefinementRank) {
            return 'grey';
        }

        return $this->getCategoryBadgeClass($ticket['statusCategory']);
    }
}
