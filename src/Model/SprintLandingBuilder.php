<?php

declare(strict_types=1);

namespace App\Model;

use App\Dto\SprintTicketDto;

/**
 * Constructeur de l'atterrissage du sprint — collaborateur extrait de Sprint (lot A2 du chantier V2).
 *
 * Agrège, par statut Jira ordonné selon le workflow configuré : le nombre de tickets, le total de
 * Story Points et le temps passé (borné à la fenêtre du sprint). Les statuts traversés pendant le
 * sprint mais sans ticket à la date de référence apparaissent avec un compteur à zéro.
 * Sans état : reçoit les tickets et les temps par statut, retourne les lignes prêtes pour la vue.
 */
final class SprintLandingBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Atterrissage du sprint : nombre de tickets et total de Story Points
     * par statut Jira, ordonné selon le workflow configuré.
     *
     * @param  list<SprintTicketDto> $tickets                   Tickets du sprint (état à la date de référence)
     * @param  array<string, float>  $sprintBoundedTimeByStatus Temps cumulé par statut borné au sprint
     * @param  int                   $issuesCount               Nombre total d'issues (dénominateur du temps moyen)
     * @return list<array{status: string, category: string, count: int, storyPoints: float,
     *     totalDays: float, averageDays: float}>
     */
    public function build(array $tickets, array $sprintBoundedTimeByStatus, int $issuesCount): array
    {
        $byStatus = [];
        $workflow = $this->config->getJiraWorkflow();

        foreach ($tickets as $ticket) {
            $status    = $ticket->status;
            $totalDays = $sprintBoundedTimeByStatus[$status] ?? 0.0;

            if (!isset($byStatus[$status])) {
                $byStatus[$status] = [
                    'status'      => $status,
                    'category'    => $ticket->statusCategory,
                    'count'       => 0,
                    'storyPoints' => 0.0,
                    'totalDays'   => $totalDays,
                    'averageDays' => $issuesCount > 0
                        ? round($totalDays / $issuesCount, 1) : 0.0,
                ];
            }

            $byStatus[$status]['count']++;
            $byStatus[$status]['storyPoints'] += $ticket->storyPoints ?? 0.0;
        }

        // Statuts historiques : traversés pendant le sprint mais sans ticket à date
        foreach ($sprintBoundedTimeByStatus as $status => $totalDays) {
            if (isset($byStatus[$status]) || $totalDays <= 0) {
                continue;
            }
            $byStatus[$status] = [
                'status'      => $status,
                'category'    => StatusCategory::resolveFromWorkflow($status, $workflow),
                'count'       => 0,
                'storyPoints' => 0.0,
                'totalDays'   => $totalDays,
                'averageDays' => $issuesCount > 0
                    ? round($totalDays / $issuesCount, 1) : 0.0,
            ];
        }

        // Tri selon l'ordre du workflow (statuts inconnus en fin de liste)
        $order = $this->config->getWorkflowStatusOrder();
        uksort($byStatus, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = ($ia === false) ? PHP_INT_MAX : $ia;
            $ib = ($ib === false) ? PHP_INT_MAX : $ib;
            return $ia <=> $ib;
        });

        return array_values($byStatus);
    }
}
