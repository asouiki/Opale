<?php

namespace App\View;

use App\Model\Config;
use App\Model\Feature;
use App\Model\StatusCategory;
use App\Service\JiraDate;

/**
 * FeatureView
 *
 * Formate et expose les données spécifiques à une Feature Jira.
 * Les données communes (métriques, timeline) sont héritées d'AbstractCollectionView.
 */
class FeatureView extends AbstractCollectionView
{
    private Feature $feature;

    public function __construct(Feature $feature)
    {
        parent::__construct($feature);
        $this->feature = $feature;
    }

    public function getFeatureId(): string
    {
        return $this->feature->getId() ?? '?';
    }
    public function getFeatureKey(): string
    {
        return $this->feature->getKey() ?? '?';
    }

    public function getFeatureName(): string
    {
        return $this->feature->getName() ?? '<i>Nom de feature non renseigné.</i>';
    }

    public function getFeatureUrl(): string
    {
        return $this->feature->getUrl();
    }
    public function getStatusName(): string
    {
        return $this->feature->getStatusName() ?? '—';
    }
    public function getPlanningIntervals(): string
    {
        $pis = $this->feature->getPlanningIntervals();
        return $pis ? implode(', ', $pis) : 'N/C';
    }

    /**
     * Retourne le Planning Interval formaté pour l'affichage.
     * Ex : "PI 7" ou null si non renseigné.
     */
    public function getPlanningIntervalLabel(): ?string
    {
        $lastPI = $this->feature->getPlanningInterval();
        return $lastPI !== null ? 'PI ' . $lastPI : null;
    }

    /**
     * Retourne le nom de la priorité de la Feature, ou '—' si absent.
     */
    public function getPriorityName(): string
    {
        return $this->feature->getPriorityName() ?? '—';
    }

    /**
     * Retourne l'URL de l'icône de priorité, ou null si absente.
     */
    public function getPriorityIconUrl(): ?string
    {
        return $this->feature->getPriorityIconUrl();
    }

    /**
     * Retourne le Jalon formaté (d/m/Y) pour l'affichage, ou '—' si absent.
     *
     * Le Jalon (customfield_10075) est une date ; on l'aligne sur le format
     * d'affichage de l'échéance pour la cohérence visuelle.
     */
    public function getJalon(): string
    {
        $raw = $this->feature->getJalon();

        return JiraDate::formatFr($raw);
    }

    /**
     * Retourne le nom de l'Epic, ou '—' si absent.
     */
    public function getEpicName(): string
    {
        return $this->feature->getEpicName() ?? '—';
    }

    /**
     * Retourne les équipes sous forme de chaîne lisible, ou '—' si aucune.
     */
    public function getContributingTeams(): string
    {
        $teams = $this->feature->getContributingTeams();
        return $teams ? implode(', ', $teams) : '—';
    }

    /**
     * Retourne la description HTML rendue par Jira, ou null si absente.
     *
     * HTML volontairement NON échappé :
     * Jira renvoie ici son `renderedFields.description`, déjà rendu ET pré-sanitisé côté serveur Jira (le formatage doit être préservé).
     * Appli est interne (VPN + LDAP, utilisateurs Jira authentifiés) : risque XSS résiduel accepté.
     * À reconsidérer (sanitizer) si l'appli est un jour exposée hors du périmètre interne.
     */
    public function getDescriptionHtml(): ?string
    {
        return $this->feature->getDescriptionHtml() ?? '<i>Aucune description renseignée.</i>';
    }

    // ==================== Métriques du ticket Feature lui-même ====================

    public function getSelfCreatedDate(): \DateTime|string
    {
        return $this->feature->getSelfAsIssue()?->getCreatedDate() ?? '-';
    }

    public function getSelfStartDate(): \DateTime|string
    {
        return $this->feature->getSelfAsIssue()?->getFirstInProgressDate() ?? '-';
    }

    /**
     * Retourne la date de fin de la Feature (résolution), formatée, ou '—'.
     *
     * Important : la date de fin n'a de sens que si la Feature est réellement
     * Terminée. Sinon Issue::getResolutionDateTime() retombe sur la date du jour,
     * ce qui induit l'utilisateur en erreur. On renvoie donc '—' tant que la
     * Feature n'est pas dans la catégorie de statut "Terminé".
     */
    public function getSelfEndDate(): \DateTime|string
    {
        if (!$this->isDone()) {
            return '—';
        }

        // Date de complétion réelle (doneDate, issue des done_statuses Features).
        // On évite getResolutionDateTime() qui retomberait sur la date du jour
        // si la doneDate est inconnue (cf. getSelfEndDate / isTargetDateOverdue).
        return $this->feature->getSelfAsIssue()?->getDoneDate(true) ?? '—';
    }

    /**
     * Retourne la date d'échéance (champ natif Jira duedate), formatée (d/m/Y),
     * ou '—' si non renseignée.
     */
    public function getDueDate(): string
    {
        $raw = $this->feature->getDueDate();

        return JiraDate::formatFr($raw);
    }

    /**
     * Indique si l'échéance (champ natif duedate) a été dépassée.
     */
    public function isDueDateOverdue(): bool
    {
        return $this->isTargetDateOverdue($this->feature->getDueDate());
    }

    /**
     * Indique si le Jalon (customfield_10075) a été dépassé.
     */
    public function isJalonOverdue(): bool
    {
        return $this->isTargetDateOverdue($this->feature->getJalon());
    }

    /**
     * Indique si au moins une des dates objectif (échéance ou Jalon) est dépassée.
     *
     * Sert à n'afficher qu'un seul badge "Échéance dépassée" même si les deux
     * dates sont renseignées.
     */
    public function isAnyTargetOverdue(): bool
    {
        return $this->isDueDateOverdue() || $this->isJalonOverdue();
    }

    /**
     * Indique si une date objectif (échéance ou Jalon) a été dépassée.
     *
     * La date doit d'abord être renseignée. Règle commune aux deux champs :
     *  - Feature non Terminée : dépassée si la date est antérieure à aujourd'hui.
     *  - Feature Terminée      : dépassée si la résolution est postérieure à la
     *    date. Une résolution le jour même (ou avant) reste OK.
     *
     * La comparaison se fait au jour près (heures ignorées).
     *
     * @param string|null $rawDate Date brute Jira au format Y-m-d, ou null.
     */
    private function isTargetDateOverdue(?string $rawDate): bool
    {
        if ($rawDate === null) {
            return false;
        }

        $target = (new \DateTime($rawDate))->setTime(0, 0);

        if (!$this->isDone()) {
            return $target < new \DateTime('today');
        }

        // Feature Terminée : on compare la date objectif à la date de complétion
        // RÉELLE issue du workflow Features (doneDate, calculée par Timeline à
        // partir des done_statuses de jira_workflow_features.json).
        //
        // On n'utilise volontairement PAS getResolutionDateTime() : faute de
        // doneDate (ex. Feature passée en Done sans transition de statut dans le
        // changelog), elle retombe sur la date du jour et provoque un faux
        // dépassement. Sans date de complétion connue, on ne peut pas conclure.
        $end = $this->feature->getSelfAsIssue()?->getDoneDate(false);

        if (!$end instanceof \DateTime) {
            return false;
        }

        return (clone $end)->setTime(0, 0) > $target;
    }

    /**
     * Indique si la Feature est dans la catégorie de statut "Terminé".
     */
    private function isDone(): bool
    {
        return $this->getSelfStatusCategory() === StatusCategory::DONE;
    }

    public function getSelfLeadTime(): float
    {
        return $this->feature->getSelfAsIssue()?->getLeadTime() ?? 0.0;
    }

    public function getSelfCycleTime(): float
    {
        return $this->feature->getSelfAsIssue()?->getCycleTime() ?? 0.0;
    }

    public function getSelfTimeSpentInRefinement(): float
    {
        return $this->feature->getSelfAsIssue()?->getTimeSpentInRefinement() ?? 0.0;
    }

    public function getSelfTimeSpentInSprint(): float
    {
        return $this->feature->getSelfAsIssue()?->getTimeSpentInSprint() ?? 0.0;
    }

    public function getSelfTimeSpentInOther(): float
    {
        return $this->feature->getSelfAsIssue()?->getTimeSpentInOther() ?? 0.0;
    }

    public function getSelfTimeSpentInWaiting(): float
    {
        return $this->feature->getSelfAsIssue()?->getTimeSpentInWaiting() ?? 0.0;
    }

    public function getSelfFirstInProgressDate(): ?string
    {
        // getFirstInProgressDate() renvoie une chaîne formatée par défaut, mais un DateTime si on lui
        // passe false : on ne retient que la forme chaîne, seule attendue à l'affichage.
        $date = $this->feature->getSelfAsIssue()?->getFirstInProgressDate();

        return is_string($date) ? $date : null;
    }

    public function getSelfDoneDate(): ?string
    {
        $date = $this->feature->getSelfAsIssue()?->getDoneDate();

        return is_string($date) ? $date : null;
    }

    /**
     * Retourne la timeline du ticket Feature lui-même, triée selon jira_workflow_features.json.
     * Structure : ['workflowStatuses' => [...], 'otherStatuses' => [...]]
     */
    public function getSelfTimelineByStatus(): array
    {
        return $this->feature->getSelfAsIssue()?->getTimeByStatus(true)
            ?? ['workflowStatuses' => [], 'otherStatuses' => []];
    }

    /**
     * Retourne les étapes VSM du ticket Feature lui-même, selon jira_workflow_features.json.
     * Pour un ticket unique, "average_days" = jours réels passés dans le statut.
     *
     * @return array<int, array{key: string, label: string, days: float, category: string}>
     */
    public function getSelfVsmSteps(): array
    {
        $timeline = $this->getSelfTimelineByStatus();
        $steps = [];

        foreach ($timeline['workflowStatuses'] as $statusName => $days) {
            if ($days <= 0) {
                continue;
            }
            $steps[] = [
                'key'      => $statusName,
                'label'    => $this->normalizeSelfStatusName($statusName),
                'days'     => round($days, 2),
                'category' => $this->getSelfStatusCategory($statusName),
            ];
        }

        return $steps;
    }

    /**
     * Préfixe un nom de statut Feature avec l'icône de sa catégorie (workflow Features).
     *
     * @return string Ex: "🧠 Funnel"
     */
    public function normalizeSelfStatusName(string $statusName): string
    {
        return $this->getSelfCategoryIcon($statusName) . ' ' . $statusName;
    }

    /**
     * Retourne la catégorie d'un statut selon jira_workflow_features.json
     *
     * @return string 'refinement'|'sprint'|'done'|'other'
     */
    public function getSelfStatusCategory(string $statusName = ''): string
    {
        $workflow = $this->config->getJiraWorkflow(Config::ISSUE_TYPE_FEATURE);

        if ($statusName === '') {
            $statusName = $this->getStatusName();
        }

        return match (true) {
            in_array($statusName, $workflow['waiting_statuses'] ?? [], true)    => StatusCategory::WAITING,
            in_array($statusName, $workflow['refinement_statuses'] ?? [], true) => StatusCategory::REFINEMENT,
            in_array($statusName, $workflow['sprint_statuses'] ?? [], true)     => StatusCategory::SPRINT,
            in_array($statusName, $workflow['done_statuses'] ?? [], true)       => StatusCategory::DONE,
            default                                                             => StatusCategory::OTHER,
        };
    }

    public function getSelfStatusCategoryColor(): string
    {
        return match ($this->getSelfStatusCategory()) {
            StatusCategory::REFINEMENT => 'grey',
            StatusCategory::SPRINT     => 'blue',
            StatusCategory::DONE       => 'green',
            StatusCategory::OTHER      => 'orange',
            default                          => 'orange',
        };
    }

    /**
     * Retourne l'icône correspondant à la catégorie d'un statut Feature.
     */
    private function getSelfCategoryIcon(string $statusName): string
    {
        $workflow = $this->config->getJiraWorkflow(Config::ISSUE_TYPE_FEATURE);

        return match (true) {
            in_array($statusName, $workflow['waiting_statuses'] ?? [], true)    => self::WAITING_ICON,
            in_array($statusName, $workflow['refinement_statuses'] ?? [], true) => self::REFINEMENT_ICON,
            in_array($statusName, $workflow['sprint_statuses'] ?? [], true)     => self::SPRINT_ICON,
            in_array($statusName, $workflow['done_statuses'] ?? [], true)       => self::DONE_ICON,
            default                                                             => self::OTHER_ICON,
        };
    }

    public function getStatusLabel()
    {
        return $this->feature->getStatusLabel();
    }
}
