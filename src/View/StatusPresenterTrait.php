<?php

namespace App\View;

use App\Model\StatusCategory;
use App\Model\Timeline;

/**
 * StatusPresenterTrait
 *
 * Présentation des statuts Jira et des causes d'attente : icône de catégorie, libellé normalisé et
 * résolution de la catégorie (refinement / sprint / done / waiting / other) d'après le workflow du projet.
 *
 * Extrait de {@see AbstractCollectionView} pour être partagé avec les vues qui ne portent pas de
 * collection d'issues — notamment {@see TeamMetricsView}, qui affiche les causes d'attente cumulées
 * des sprints sélectionnés sans être adossée à une AbstractIssueCollection.
 *
 * Prérequis : la classe utilisatrice doit exposer une propriété `$config` de type {@see \App\Model\Config}
 * (le workflow du projet en est la seule dépendance).
 */
trait StatusPresenterTrait
{
    public const REFINEMENT_ICON = '🧠';
    public const SPRINT_ICON     = '⚙️';
    public const DONE_ICON       = '✅';
    public const WAITING_ICON    = '⏳';
    public const OTHER_ICON      = '❓';

    public function normalizeStatusName(string $statusName): string
    {
        return $this->getCategoryStatusIcon($statusName) . ' ' . $statusName;
    }

    /**
     * Icône à préfixer devant une cause d'attente.
     *
     * Retourne l'image officielle du flag Jira pour la cause « Ticket flaggé »
     * (cf. Timeline::FLAGGED_WAITING_KEY), une chaîne vide pour toute autre cause.
     */
    public function getWaitingCauseIcon(string $cause): string
    {
        if ($cause === Timeline::FLAGGED_WAITING_KEY) {
            return '<img src="/img/jira_flagged_icon.png" alt="Ticket flaggé" class="waiting-flag-icon"> ';
        }
        return '';
    }

    public function getCategoryStatusIcon(string $statusOrLabel): string
    {
        // Le flag Jira porte sa propre image (cf. getWaitingCauseIcon) : pas d'emoji de catégorie ici.
        if ($statusOrLabel === Timeline::FLAGGED_WAITING_KEY) {
            return '';
        }

        $workflow = $this->config->getJiraWorkflow();

        // Vérifie d'abord si c'est un statut Jira
        if (in_array($statusOrLabel, $workflow['waiting_statuses'] ?? [], true)) {
            return self::WAITING_ICON;
        }
        if (in_array($statusOrLabel, $workflow['refinement_statuses'] ?? [], true)) {
            return self::REFINEMENT_ICON;
        }
        if (in_array($statusOrLabel, $workflow['sprint_statuses'] ?? [], true)) {
            return self::SPRINT_ICON;
        }
        if (in_array($statusOrLabel, $workflow['done_statuses'] ?? [], true)) {
            return self::DONE_ICON;
        }

        // Vérifie si c'est une étiquette d'attente (contient "attente", insensible à la casse)
        if (stripos($statusOrLabel, 'attente') !== false) {
            return self::WAITING_ICON;
        }

        return self::OTHER_ICON;
    }

    /**
     * Retourne la catégorie d'un statut ou d'une étiquette d'attente.
     *
     * Les causes de waiting peuvent être :
     * - Un statut Jira (ex: "En Attente")
     * - Une étiquette contenant "attente" (ex: "attente-externe")
     *
     * @param  string $statusOrLabel Nom du statut ou de l'étiquette
     * @return string 'refinement'|'sprint'|'done'|'waiting'|'other'
     */
    public function getStatusCategory(string $statusOrLabel): string
    {
        // Le flag Jira est une cause d'attente à part entière.
        if ($statusOrLabel === Timeline::FLAGGED_WAITING_KEY) {
            return StatusCategory::WAITING;
        }

        $workflow = $this->config->getJiraWorkflow();

        // Vérifie d'abord si c'est un statut Jira
        if (in_array($statusOrLabel, $workflow['waiting_statuses'] ?? [], true)) {
            return StatusCategory::WAITING;
        }
        if (in_array($statusOrLabel, $workflow['refinement_statuses'] ?? [], true)) {
            return StatusCategory::REFINEMENT;
        }
        if (in_array($statusOrLabel, $workflow['sprint_statuses'] ?? [], true)) {
            return StatusCategory::SPRINT;
        }
        if (in_array($statusOrLabel, $workflow['done_statuses'] ?? [], true)) {
            return StatusCategory::DONE;
        }

        // Vérifie si c'est une étiquette d'attente (contient "attente", insensible à la casse)
        if (stripos($statusOrLabel, 'attente') !== false) {
            return StatusCategory::WAITING;
        }

        return StatusCategory::OTHER;
    }
}
