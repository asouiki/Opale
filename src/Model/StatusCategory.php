<?php

namespace App\Model;

/**
 * StatusCategory
 *
 * Vocabulaire unique des catégories de statut workflow (refinement → sprint → done, plus waiting et other).
 * Source de vérité centrale, calquée sur JiraFields. Consommée par la View et le domaine.
 *
 * NB : ne pas confondre avec la `statusCategory.key` de l'API Jira ('new'/'indeterminate'/'done'),
 * vocabulaire distinct utilisé tel quel par Feature/CurrentPi*.
 *
 * @todo V2 Backend : dernier littéral du domaine restant ('waiting', Timeline::buildStatusTimeline l.433),
 * à migrer si/quand la god-method est ouverte (décision §12 : ne pas l'ouvrir sans besoin).
 */
final class StatusCategory
{
    public const REFINEMENT = 'refinement';
    public const SPRINT     = 'sprint';
    public const DONE       = 'done';
    public const WAITING    = 'waiting';
    public const OTHER      = 'other';

    /**
     * Détermine la catégorie de workflow d'un statut Jira.
     *
     * L'ordre de test gère les statuts présents dans plusieurs listes
     * (ex: « Bloqué » est à la fois « sprint » et « waiting »).
     *
     * @param  string                  $status   Libellé du statut (forme affichée)
     * @param  array<string, string[]> $workflow Workflow issu de jira_workflow_issues.json
     * @return string                            self::DONE|WAITING|SPRINT|REFINEMENT|OTHER
     */
    public static function resolveFromWorkflow(string $status, array $workflow): string
    {
        return match (true) {
            in_array($status, $workflow['done_statuses'] ?? [], true)       => self::DONE,
            in_array($status, $workflow['waiting_statuses'] ?? [], true)    => self::WAITING,
            in_array($status, $workflow['sprint_statuses'] ?? [], true)     => self::SPRINT,
            in_array($status, $workflow['refinement_statuses'] ?? [], true) => self::REFINEMENT,
            default                                                         => self::OTHER,
        };
    }
}
