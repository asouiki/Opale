<?php

namespace App\Model;

use DateTime;

/**
 * Issue
 *
 * Représente une Issue Jira.
 * - Hydratée directement depuis l’API Jira
 * - Encapsule les données brutes
 * - Centralise la logique métier (timeline, métriques)
 */
class Issue
{
    protected Config $config;
    protected Timeline $timeline;

    /** Données brutes issues de l’API Jira. */
    protected array $data = [];

    /**
     * Données clés du cycle de vie
     */
    protected ?\DateTime $createdDate = null;
    protected ?\DateTime $firstInProgressDate = null;
    protected ?\DateTime $doneDate = null;
    protected DateTime|null $firstAddToSprintDate = null;
    protected string $firstSprint;
    protected float $leadTime = 0.0;
    protected float $cycleTime = 0.0;
    protected array $timeByStatus = [];
    /** @var array<string, float> Jours ouvrés par catégorie de workflow (cf. StatusCategory) */
    protected array $workflowTimeBreakdown = [
        StatusCategory::REFINEMENT => 0.0,
        StatusCategory::SPRINT => 0.0,
        StatusCategory::OTHER => 0.0,
        StatusCategory::WAITING => 0.0
    ];
    protected array $waitingTimes = [];
    // Total d'attente DÉDUPLIQUÉ (union des périodes) : un jour en attente n'est compté
    // qu'une fois même si plusieurs causes (statut, label, flag) le recouvrent.
    protected float $waitingTimeTotal = 0.0;
    protected array $revertCount = [];

    /**
     * Hydrate l'Issue depuis les données Jira, construit sa timeline de statuts et ses temps
     * d'attente, puis calcule lead time et cycle time (en jours ouvrés).
     *
     * @param array         $data     Données brutes issues de l'API Jira
     * @param Config|null   $config   Optionnel, injecté pour les tests (défaut : new Config())
     * @param Timeline|null $timeline Optionnel, injecté pour les tests (défaut : new Timeline())
     */
    public function __construct(array $data, ?Config $config = null, ?Timeline $timeline = null)
    {
        $this->config   = $config   ?? new Config();
        $this->timeline = $timeline ?? new Timeline($this->config);

        $this->initializeData($data);
        $this->timeline->buildStatusTimeline($this);
        $this->timeline->buildWaitingTimes($this);
        $this->setLeadTime();
        $this->setCycleTime();
    }

    /**
     * Initialise l’objet Issue à partir des données Jira.
     */
    private function initializeData(array $data): void
    {
        foreach ($data as $index => $value) {
            $this->setData($index, $value);
        }

        if (!empty($this->data['fields']['created'])) {
            $this->createdDate = new \DateTime($this->data['fields']['created']);
        }
    }

    /**
     * Affecte une donnée brute à l’Issue.
     */
    protected function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }


    /* =======================
     * ====== GETTERS ========
     * =======================
     */
    public function getData(string|null $data = null): array
    {
        if ($data !== null && isset($this->data[$data])) {
            return $this->data[$data];
        }
        return $this->data;
    }

    public function getKey(): string
    {
        return $this->data['key'] ?? '';
    }

    /**
     * Clé du projet Jira portant le ticket (ex. « ABC »), chaîne vide si le champ n'a pas été demandé.
     *
     * Sert à distinguer la contribution d'une équipe au sein des tickets enfants d'une Feature : ceux-ci
     * peuvent appartenir à plusieurs projets quand la Feature est portée par le projet parent.
     */
    public function getProjectKey(): string
    {
        return $this->data['fields']['project']['key'] ?? '';
    }

    public function getSummary(): string
    {
        return $this->data['fields']['summary'] ?? '';
    }

    public function getIssueType(): array
    {
        return $this->data['fields']['issuetype'] ?? [];
    }

    public function getIssueTypeName(): string
    {
        return $this->data['fields']['issuetype']['name'] ?? '';
    }

    public function getIssueTypeIcon(): string
    {
        return $this->data['fields']['issuetype']['iconUrl'] ?? '';
    }

    public function getPriority(): string
    {
        return $this->data['fields']['priority'] ?? '';
    }

    public function getPriorityName(): string
    {
        return $this->data['fields']['priority']['name'] ?? '';
    }

    public function getPriorityIcon(): string
    {
        return $this->data['fields']['priority']['iconUrl'] ?? '';
    }

    public function getPriorityId(): string
    {
        return (string) ($this->data['fields']['priority']['id'] ?? '');
    }

    public function getStatusName(): string
    {
        return $this->data['fields']['status']['name'] ?? '';
    }

    /**
     * Nom de la couleur de la catégorie de statut de l'issue, ou chaîne vide si absente.
     */
    public function getStatusCategoryColor(): string
    {
        return $this->data['fields']['status']['statusCategory']['colorName'] ?? '';
    }

    /**
     * Date de création formatée « d/m/Y » si $format, sinon l'objet DateTime ; null si absente.
     *
     * @return \DateTime|string|null
     */
    public function getCreatedDate(bool $format = true)
    {
        if ($this->createdDate === null) {
            return null;
        }

        return $format
            ? $this->createdDate->format('d/m/Y')
            : $this->createdDate;
    }

    /**
     * Date de résolution : la date de clôture si connue, sinon le champ resolutiondate Jira,
     * à défaut la date courante.
     * Formatée « d/m/Y » si $format.
     */
    public function getResolutionDateTime($format = false): \DateTime|string
    {
        if ($this->doneDate) {
            $result =  $this->doneDate;
        } else {
            $result = !empty($this->data['fields']['resolutiondate'])
                ? new \DateTime($this->data['fields']['resolutiondate'])
                : new \DateTime();
        }

        return $format
            ? $result->format('d/m/Y')
            : $result;
    }

    /**
     * Historique (changelog) de l'issue.
     * Si $sorted vaut true, il est trié par date croissante (du plus ancien au plus récent).
     */
    public function getHistory($sorted = true): array
    {
        $history = $this->data['changelog']['histories'] ?? [];
        if (empty($history) || !$sorted) {
            return $history;
        }

        // Tri chronologique du changelog
        usort(
            $history,
            fn ($a, $b) => strtotime($a['created']) <=> strtotime($b['created'])
        );

        return $history;
    }

    /**
     * Première date de passage « en cours », formatée « d/m/Y » si $format ; null si absente.
     *
     * @return \DateTime|string|null
     */
    public function getFirstInProgressDate(bool $format = true)
    {
        if ($this->firstInProgressDate === null) {
            return null;
        }

        return $format
            ? $this->firstInProgressDate->format('d/m/Y')
            : $this->firstInProgressDate;
    }

    /**
     * Définit la première date de passage « en cours » de l'issue.
     */
    public function setFirstInProgressDate(\DateTime $date): void
    {
        $this->firstInProgressDate = $date;
    }

    /**
     * Date de clôture (Done) formatée « d/m/Y » si $format, sinon l'objet DateTime ; null si absente.
     *
     * @return \DateTime|string|null
     */
    public function getDoneDate(bool $format = true)
    {
        if ($this->doneDate === null) {
            return null;
        }

        return $format
            ? $this->doneDate->format('d/m/Y')
            : $this->doneDate;
    }

    /**
     * Définit la date de clôture (Done) de l'issue.
     */
    public function setDoneDate(\DateTime $date): void
    {
        $this->doneDate = $date;
    }

    /**
     * Calcule le lead time (création → clôture) en jours ouvrés ; 0 si l'une des deux dates manque.
     */
    private function setLeadTime(): void
    {
        if (!$this->createdDate || !$this->doneDate) {
            $this->leadTime = 0;
            return;
        }

        //Calcul en jours ouvrés (excluant week-ends et jours fériés)
        $this->leadTime = $this->timeline->calculateBusinessDays(
            $this->createdDate,
            $this->doneDate
        );
    }

    /**
     * Lead time de l'issue, en jours ouvrés.
     *
     * Ticket terminé (statut catégorie Done atteint) : durée figée création → clôture.
     * Ticket encore ouvert : âge courant création → aujourd'hui, recalculé à chaque appel (métrique
     * « en cours » live, jamais figée dans le cache — le snapshot ne stocke que les dates d'ancrage).
     * Retourne 0.0 si la date de création est absente.
     */
    public function getLeadTime(): float
    {
        if ($this->createdDate === null) {
            return 0.0;
        }
        if ($this->doneDate !== null) {
            return $this->leadTime;
        }

        return $this->timeline->calculateBusinessDays($this->createdDate, $this->now());
    }

    /**
     * Calcule le cycle time (premier passage « en cours » → clôture) en jours ouvrés.
     * Vaut 0 si l'une des deux dates manque.
     */
    private function setCycleTime(): void
    {
        if (!$this->firstInProgressDate || !$this->doneDate) {
            $this->cycleTime = 0;
            return;
        }

        //Calcul en jours ouvrés (excluant week-ends et jours fériés)
        $this->cycleTime = $this->timeline->calculateBusinessDays(
            $this->firstInProgressDate,
            $this->doneDate
        );
    }

    /**
     * Cycle time de l'issue, en jours ouvrés.
     *
     * Ticket terminé : durée figée 1er passage « en cours » → clôture.
     * Ticket démarré mais pas terminé : âge courant 1er passage « en cours » → aujourd'hui, recalculé à
     * chaque appel (live). Un ticket jamais passé « en cours » (Affinage / To Do) n'a pas de cycle → 0.0.
     */
    public function getCycleTime(): float
    {
        if ($this->firstInProgressDate === null) {
            return 0.0;
        }
        if ($this->doneDate !== null) {
            return $this->cycleTime;
        }

        return $this->timeline->calculateBusinessDays($this->firstInProgressDate, $this->now());
    }

    /**
     * Date de référence « maintenant » pour le calcul des durées « en cours » (lead/cycle des tickets
     * non terminés). Isolée dans une méthode pour être figée dans les tests (déterminisme).
     */
    protected function now(): \DateTime
    {
        return new \DateTime();
    }


    /**
     * Définit le temps cumulé passé dans chaque statut Jira (clé = nom du statut, valeur = jours).
     *
     * @param array<string, float> $timeByStatus
     */
    public function setTimeByStatus(array $timeByStatus): void
    {
        $this->timeByStatus = $timeByStatus;
    }

    /**
     * Temps cumulé passé par statut Jira, trié selon le workflow du type d'issue.
     */
    public function getTimeByStatus(bool $splitOtherStatuses): array
    {
        return $this->timeline->getSortedTimelineByStatus(
            $this->timeByStatus,
            $splitOtherStatuses,
            $this->getIssueTypeName()
        );
    }

    /**
     * Définit la répartition du temps par catégorie de workflow (clé = catégorie, valeur = jours).
     *
     * @param  array<string, float> $workflowTimeBreakdown
     * @return void
     */
    public function setWorkflowTimeBreakdown(array $workflowTimeBreakdown)
    {
        $this->workflowTimeBreakdown = $workflowTimeBreakdown;
    }

    public function getWorkflowTimeBreakdown(): array
    {
        return $this->workflowTimeBreakdown;
    }

    public function getTimeSpentInRefinement(): float
    {
        return $this->workflowTimeBreakdown[StatusCategory::REFINEMENT];
    }

    public function getTimeSpentInSprint(): float
    {
        return $this->workflowTimeBreakdown[StatusCategory::SPRINT];
    }

    public function getTimeSpentInOther(): float
    {
        return $this->workflowTimeBreakdown[StatusCategory::OTHER];
    }

    /**
     * Total des jours d'attente de l'issue, DÉDUPLIQUÉ (union des périodes).
     *
     * Contrairement à la somme de $this->waitingTimes (ventilation par cause, qui peut
     * additionner deux fois une même journée si plusieurs causes se chevauchent), cette
     * valeur ne compte chaque jour ouvré en attente qu'une seule fois.
     * Calculée par Timeline::buildWaitingTimes() (union des intervalles statuts+labels+flag).
     */
    public function getTimeSpentInWaiting(): float
    {
        return $this->waitingTimeTotal;
    }

    /**
     * Définit le total d'attente dédupliqué (union des périodes).
     */
    public function setWaitingTimeTotal(float $waitingTimeTotal): void
    {
        $this->waitingTimeTotal = $waitingTimeTotal;
    }

    public function getIssueUrl(): string
    {
        return JiraUrl::browse($this->getKey());
    }

    /**
     * Définit les temps d'attente calculés par label « attente ».
     *
     * @param array<string, float> $waitingTimes Tableau associatif label => jours ouvrés
     */
    public function setWaitingTimes(array $waitingTimes): void
    {
        $this->waitingTimes = $waitingTimes;
    }

    /**
     * Ajoute un temps d'attente pour un statut donné de catégorie « waiting_statuses ».
     *
     * @see \config_files\jira_workflow_issues.json
     */
    public function addWaitingTime(string $waitingStatus, float $daysInStatus): void
    {
        if ($daysInStatus <= 0) {
            return;
        }

        if (isset($this->waitingTimes[$waitingStatus])) {
            $this->waitingTimes[$waitingStatus] += $daysInStatus;
        } else {
            $this->waitingTimes[$waitingStatus] = $daysInStatus;
        }
    }

    /**
     * Retourne les temps d'attente par label "attente".
     *
     * @return array<string, float> Tableau associatif label => jours ouvrés
     */
    public function getWaitingTimes(): array
    {
        return $this->waitingTimes;
    }

    /**
     * Définit la première date d'ajout de l'issue à un Sprint.
     */
    public function setFirstAddToSprintDate(DateTime $firstAddToSprintDate)
    {
        $this->firstAddToSprintDate = $firstAddToSprintDate;
    }

    /**
     * Première date d'ajout de l'issue à un Sprint, formatée « d/m/Y » ; null si jamais ajoutée.
     */
    public function getFirstAddToSprintDate(): string|null
    {
        return ($this->firstAddToSprintDate instanceof DateTime)
            ? $this->firstAddToSprintDate->format('d/m/Y')
            : $this->firstAddToSprintDate;
    }

    public function setFirstSprint($sprint)
    {
        $this->firstSprint = $sprint;
    }

    public function getFirstSprint(): string
    {
        return $this->firstSprint ?? 'N/A';
    }

    /**
     * Incrémente le compteur de « reverts » pour un statut.
     *
     * Un statut déjà rencontré signifie qu'on y est revenu après être passé par un autre statut
     * (ex. To Do → In Progress → To Do) : ce retour en arrière est compté comme un revert.
     */
    public function addRevertCount(string $status): void
    {
        $this->revertCount[$status] = ($this->revertCount[$status] ?? 0) + 1;
    }

    public function getRevertCount(): array
    {
        return $this->revertCount;
    }

    /**
     * Retourne les étiquettes (labels) Jira de l'issue.
     *
     * @return string[]
     */
    public function getLabels(): array
    {
        return $this->data['fields']['labels'] ?? [];
    }

    /**
     * Clé du ticket parent (Feature, Epic...) si l'issue en a un, sinon null.
     */
    public function getParentKey(): ?string
    {
        return $this->data['fields']['parent']['key'] ?? null;
    }

    /**
     * Résumé (summary) du ticket parent si l'issue en a un, sinon null.
     */
    public function getParentSummary(): ?string
    {
        return $this->data['fields']['parent']['fields']['summary'] ?? null;
    }

    /**
     * Valeur du champ « Story Points » de l'issue, ou null si le champ n'est pas renseigné.
     *
     * L'ID du customfield est fourni par l'appelant (constante
     * JiraService::STORY_POINTS_CUSTOM_FIELD) afin de garder ce modèle
     * découplé de la couche Service.
     */
    public function getStoryPoints(string $fieldId): ?float
    {
        $value = $this->data['fields'][$fieldId] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /* =======================
     * == CACHE (projection) =
     * =======================
     */

    /**
     * Sérialise l'Issue en une projection légère réutilisable pour l'affichage et les agrégats,
     * SANS le changelog (volumineux et inutile une fois la timeline calculée).
     */
    public function toProjection(): array
    {
        // Champs bruts d'affichage uniquement (on retire le changelog, déjà digéré en timeByStatus).
        $data = $this->data;
        unset($data['changelog']);

        return [
            'data'                  => $data,
            'leadTime'              => $this->leadTime,
            'cycleTime'             => $this->cycleTime,
            'timeByStatus'          => $this->timeByStatus,
            'workflowTimeBreakdown' => $this->workflowTimeBreakdown,
            'waitingTimes'          => $this->waitingTimes,
            'waitingTimeTotal'      => $this->waitingTimeTotal,
            'revertCount'           => $this->revertCount,
            'createdDate'           => $this->createdDate?->format(DateTime::ATOM),
            'firstInProgressDate'   => $this->firstInProgressDate?->format(DateTime::ATOM),
            'doneDate'              => $this->doneDate?->format(DateTime::ATOM),
            'firstAddToSprintDate'  => $this->firstAddToSprintDate?->format(DateTime::ATOM),
            'firstSprint'           => $this->firstSprint ?? null,
        ];
    }

    /**
     * Reconstruit une Issue depuis une projection (cf. toProjection()) sans rejouer le changelog :
     * les valeurs calculées sont posées directement, la timeline n'est pas recalculée.
     *
     * @param array         $p        Projection produite par toProjection()
     * @param Config|null   $config   Optionnel (réutilisé depuis la collection hôte)
     * @param Timeline|null $timeline Optionnel (réutilisé depuis la collection hôte)
     */
    public static function fromProjection(array $p, ?Config $config = null, ?Timeline $timeline = null): self
    {
        $issue = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $issue->config   = $config   ?? new Config();
        $issue->timeline = $timeline ?? new Timeline($issue->config);

        $issue->data                  = $p['data'] ?? [];
        $issue->leadTime              = (float) ($p['leadTime'] ?? 0.0);
        $issue->cycleTime             = (float) ($p['cycleTime'] ?? 0.0);
        $issue->timeByStatus          = $p['timeByStatus'] ?? [];
        $issue->workflowTimeBreakdown = $p['workflowTimeBreakdown']
            ?? [
                StatusCategory::REFINEMENT => 0.0,
                StatusCategory::SPRINT => 0.0,
                StatusCategory::OTHER => 0.0,
                StatusCategory::WAITING => 0.0,
            ];
        $issue->waitingTimes          = $p['waitingTimes'] ?? [];
        // Repli sur l'ancienne somme (non dédupliquée) pour les projections antérieures au champ.
        $issue->waitingTimeTotal      = (float) ($p['waitingTimeTotal'] ?? array_sum($p['waitingTimes'] ?? []));
        $issue->revertCount           = $p['revertCount'] ?? [];
        $issue->createdDate           = !empty($p['createdDate']) ? new DateTime($p['createdDate']) : null;
        $issue->firstInProgressDate   = !empty($p['firstInProgressDate']) ? new DateTime($p['firstInProgressDate']) : null;
        $issue->doneDate              = !empty($p['doneDate']) ? new DateTime($p['doneDate']) : null;
        $issue->firstAddToSprintDate  = !empty($p['firstAddToSprintDate']) ? new DateTime($p['firstAddToSprintDate']) : null;

        if (!empty($p['firstSprint'])) {
            $issue->firstSprint = $p['firstSprint'];
        }

        return $issue;
    }
}
