<?php

namespace App\View;

use App\Model\StatusCategory;
use App\Service\JiraDate;

/**
 * ViewFormatterTrait
 *
 * Helpers de formatage d'affichage partagés par les View (tableaux de tickets, métriques) qui n'ont
 * pas de parent commun exploitable : SprintView, PlanificationView, TeamMetricsView.
 *
 * NB : BoardRepository garde son propre formatDate (couche Repository + placeholder « ? » distinct).
 */
trait ViewFormatterTrait
{
    /**
     * Classe CSS de badge associée à une catégorie de statut de workflow.
     *
     * @param string $statusCategory Une des constantes StatusCategory::*
     */
    public function getCategoryBadgeClass(string $statusCategory): string
    {
        return match ($statusCategory) {
            StatusCategory::DONE       => 'green',
            StatusCategory::SPRINT     => 'orange',
            StatusCategory::REFINEMENT => 'blue',
            StatusCategory::WAITING    => 'red',
            default                    => 'grey',
        };
    }

    /**
     * Formate une valeur de Story Points pour l'affichage (supprime le « .0 » superflu :
     * 5.0 → « 5 », 2.5 → « 2.5 »). Retourne « — » si la valeur est nulle.
     */
    public function formatSp(float|int|null $storyPoints): string
    {
        if ($storyPoints === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $storyPoints, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Nombre de sprints affectés avec pastille de couleur (🟢 1, 🟠 2, 🔴 ≥ 3), ou « — » si aucun.
     *
     * @param string[] $sprintNames Noms des sprints affectés
     */
    public function formatSprintCount(array $sprintNames): string
    {
        $count = count($sprintNames);

        if ($count === 1) {
            return $count . ' 🟢';
        }
        if ($count === 2) {
            return $count . ' 🟠';
        }
        if ($count >= 3) {
            return $count . ' 🔴';
        }

        return '—';
    }

    /**
     * Formate un nombre de jours ouvrés pour l'affichage (« 3.5 j »), à 2 décimales avec suppression
     * des zéros superflus (3.50 → « 3.5 j », 3.47 → « 3.47 j »). Retourne « — » si nul ou négatif.
     */
    public function formatDays(float|int|null $days): string
    {
        if ($days === null || $days <= 0) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $days, 2, '.', ''), '0'), '.');

        return ($formatted === '' ? '0' : $formatted) . ' j';
    }

    /**
     * Formate une date ISO 8601 Jira au format jj/mm/aaaa (fuseau applicatif), « — » si absente/invalide.
     */
    public function formatDate(?string $isoDate): string
    {
        return JiraDate::formatFr($isoDate);
    }

    /**
     * Formate un nombre (Story Points, compteur moyen…) en supprimant le « .0 »
     * superflu (5.0 → « 5 », 2.5 → « 2.5 »).
     */
    public function formatNumber(float|int|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Formate un pourcentage déjà calculé (« 82.5 % »).
     * Retourne « N/A » si la valeur est nulle (métrique non définie).
     */
    public function formatPercent(float|int|null $percent): string
    {
        if ($percent === null) {
            return 'N/A';
        }

        return $this->formatNumber($percent) . ' %';
    }

    /**
     * Classe CSS de badge associée à un taux de préparation (part de sujets prêts/démarrés dans un
     * bucket de planification) : vert au-delà de 80 %, orange au-delà de 50 %, rouge en dessous,
     * gris si non calculable (bucket vide).
     */
    public function getReadinessBadgeClass(?float $percent): string
    {
        if ($percent === null) {
            return 'grey';
        }
        if ($percent >= 80.0) {
            return 'green';
        }
        if ($percent >= 50.0) {
            return 'orange';
        }

        return 'red';
    }
}
