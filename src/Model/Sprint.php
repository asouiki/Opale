<?php

namespace App\Model;

use App\Dto\SprintTicketDto;
use DateTime;
use Exception;
use Throwable;

/**
 * Sprint
 *
 * Agrégat des issues Jira d'un sprint donné, identifié par son ID Agile.
 *
 * Hérite de AbstractIssueCollection pour réutiliser l'instanciation des Issue
 * (reconstruction de la timeline depuis le changelog) et le calcul de la médiane.
 *
 * Toute la logique propre au sprint (atterrissage par statut, objectifs,
 * prédictibilité, reliquat, changement de périmètre) est calculée à partir d'une source
 * unique : le tableau $tickets, construit une fois après le chargement des issues.
 */
class Sprint extends AbstractIssueCollection
{
    /** États Jira d'un sprint */
    public const STATE_ACTIVE = 'active';
    public const STATE_CLOSED = 'closed';
    public const STATE_FUTURE = 'future';

    /** Étiquettes métier exploitées par les métriques du sprint (« objectif » → SprintObjectiveAnalyzer) */
    public const LABEL_NEW_PRIORITY           = 'nouvelle_prio';
    public const LABEL_CONTINUOUS_IMPROVEMENT = 'amelio_continue';

    /** Types d'issue Jira assimilés à de l'amélioration continue, au même titre que le label ci-dessus. */
    public const TYPES_CONTINUOUS_IMPROVEMENT = ['Innovation', 'Amélioration continue'];

    /** Cible de prédictibilité : % des Story Points présents au démarrage */
    public const PREDICTABILITY_TARGET_PERCENT = 80;

    /** ID Agile du sprint. */
    private string $sprintId;

    /** Clé du projet Jira (pour la construction de l'URL de rapport). */
    private string $projectKey;

    /**
     * ID du board Jira (pour la construction de l'URL de rapport, et pour interroger le
     * "Sprint Report" — cf. fetchPuntedTickets()). Défaut '' : certains appelants historiques
     * ne le connaissent pas (cf. Sprint::fetchPuntedTickets(), dégradation silencieuse).
     */
    private string $boardId = '';

    /**
     * Date d'observation choisie : le sprint est reconstruit tel qu'il était à cet instant, périmètre compris,
     * au lieu de l'être à sa clôture (ou à aujourd'hui). null = comportement nominal.
     *
     * Sert à rattraper un reliquat déplacé vers le sprint suivant AVANT la clôture, qui fausse définitivement
     * les métriques de clôture (cf. resolveSprintEnd() et loadIssues()).
     */
    private ?DateTime $asOfDate = null;

    /**
     * Nombre de tickets réintégrés au périmètre par la reconstruction à date : ils sont sortis du sprint APRÈS la
     * date d'observation, donc invisibles de l'API Agile aujourd'hui. Vaut 0 hors mode « photo à date ».
     *
     * Sert de contrôle à l'utilisateur : ce compte doit correspondre à la section « Issues Removed From Sprint » du
     * rapport de sprint Jira, aux types d'issue exclus des métriques près.
     */
    private int $reintegratedCount = 0;

    /** Métadonnées brutes du sprint (nom, état, dates, objectif). */
    private array $sprintData = [];

    /** Date de démarrage du sprint (pour la détection du changement de périmètre). */
    private ?DateTime $startDateTime = null;

    /**
     * Tickets enrichis du sprint — source unique de vérité pour tous les calculs.
     *
     * Note : status, storyPoints et labels reflètent l'état du ticket À LA DATE
     * DE RÉFÉRENCE du sprint (clôture ou aujourd'hui), pas son état live actuel.
     *
     * @var list<SprintTicketDto>
     */
    private array $tickets = [];

    /**
     * Atterrissage : nb de tickets + total SP par statut (cf. SprintLandingBuilder).
     *
     * @var list<array{status: string, category: string, count: int, storyPoints: float,
     *     totalDays: float, averageDays: float}>
     */
    private array $landingByStatus = [];

    /**
     * Groupes de tickets par étiquette « objectif » (cf. SprintObjectiveAnalyzer).
     *
     * @var list<array{label: string, title: string, issues: list<array<string, mixed>>}>
     */
    private array $objectiveGroups = [];

    /** Liste dédoublonnée des Features (parents) des tickets. */
    private array $features = [];

    /**
     * Statistiques du sprint (SP, changement de périmètre, étiquettes...) — cf. SprintMetricsCalculator.
     *
     * @var array<string, mixed>
     */
    private array $stats = [];

    /**
     * Prédictibilité cible / atteinte — cf. SprintMetricsCalculator::buildPredictability().
     *
     * @var array<string, mixed>
     */
    private array $predictability = [];

    /** Temps cumulé par statut borné à la durée du sprint [status => totalDays]. */
    private array $sprintBoundedTimeByStatus = [];

    /**
     * Agrégats d'attente sur le CYCLE DE VIE COMPLET des tickets, conservés en regard des agrégats
     * bornés au sprint (cf. recomputeWaitingAggregates()) : l'écart entre les deux distingue un blocage
     * survenu pendant le sprint d'un ticket déjà empêché à son arrivée.
     */
    private float $totalTimeSpentInWaitingFull = 0.0;
    private float $averageTimeSpentInWaitingFull = 0.0;
    /** @var array<string, float> */
    private array $aggregatedWaitingTimesFull = [];

    /** Statistiques d'âge des tickets [average => float, median => float]. */
    private array $ticketAgeStats = [];

    /**
     * Charge le sprint et ses issues, puis enchaîne tous les calculs de métriques propres au sprint
     * à partir de la source unique $tickets.
     *
     * @param string        $sprintId   ID Agile du sprint à analyser
     * @param string        $projectKey Clé du projet (pour l'URL de rapport)
     * @param string        $boardId    ID du board (pour l'URL de rapport)
     * @param DateTime|null $asOfDate   Date d'observation : reconstruit le sprint tel qu'il était à cet instant
     *                                  (périmètre compris). null = clôture ou aujourd'hui, selon l'état du sprint.
     */
    public function __construct(string $sprintId, string $projectKey = '', string $boardId = '', ?DateTime $asOfDate = null)
    {
        $this->sprintId   = $sprintId;
        $this->projectKey = $projectKey;
        $this->boardId    = $boardId;
        // Posée AVANT le parent : son constructeur enchaîne immédiatement loadCollectionData() puis loadIssues(),
        // qui s'appuient déjà sur la date d'observation.
        $this->asOfDate = $asOfDate;

        // Le parent orchestre loadCollectionData() puis loadIssues().
        parent::__construct($sprintId);

        // Calculs propres au sprint, une fois les Issue chargées.
        $this->buildTickets();
        // Impérativement APRÈS buildTickets(), qui borne l'attente de chaque Issue à la fenêtre du sprint.
        $this->recomputeWaitingAggregates();
        $this->computeSprintBoundedTimeByStatus();
        $this->computeLandingByStatus();
        $this->computeObjectiveGroups();
        $this->computeFeatures();
        $this->computeStats();
        $this->computePredictability();
        $this->computeTicketAgeStats();
    }

    /**
     * {@inheritdoc}
     *
     * Charge les métadonnées du sprint (nom, état, dates, objectif).
     *
     * @throws Exception Si le sprint est introuvable
     */
    protected function loadCollectionData(string $id): void
    {
        $this->sprintData = $this->jiraService->getSprintById($id);

        if (empty($this->sprintData['id'])) {
            throw new Exception("Sprint introuvable (id: $id).");
        }

        if (!empty($this->sprintData['startDate'])) {
            $this->startDateTime = new DateTime($this->sprintData['startDate']);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Charge les issues du sprint et les instancie en objets Issue.
     *
     * Sans date d'observation, le périmètre est la composition ACTUELLE du sprint (API Agile).
     * Avec une date d'observation, ce périmètre est faux dans les deux sens : il ignore les tickets retirés depuis
     * (ils ne sont plus rattachés au sprint) et inclut ceux ajoutés après la date. Il est donc reconstruit — cf.
     * rebuildScopeAt().
     *
     * @throws Exception Si la récupération des tickets du sprint échoue
     */
    protected function loadIssues(string $id): void
    {
        try {
            $rawIssues = $this->jiraService->getIssuesBySprint($id, $this->config->getMetricIssueTypes());

            foreach ($rawIssues as $raw) {
                $this->issues[] = new Issue($raw);
            }

            if ($this->asOfDate !== null) {
                $this->issues = $this->rebuildScopeAt($this->asOfDate);
            }

            $this->issuesCount = count($this->issues);
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération des tickets du sprint : ' . $e->getMessage());
        }
    }

    /**
     * Reconstruit le périmètre du sprint tel qu'il était à la date d'observation.
     *
     * Deux corrections, appliquées à l'union des tickets encore rattachés au sprint et de ceux qui en ont été
     * retirés :
     *  - RÉINTÈGRE les tickets sortis après la date (invisibles de l'API Agile aujourd'hui) ;
     *  - EXCLUT les tickets entrés après elle.
     *
     * Les tickets retirés sont refetchés avec leur changelog complet (et non repris du "Sprint Report", dont le
     * payload n'en porte pas) afin de traverser le même pipeline que les autres : statut, Story Points et étiquettes
     * sont ainsi reconstruits à la date, au lieu d'être neutralisés comme dans buildPuntedTickets().
     *
     * Best-effort sur la partie "tickets retirés" : le Sprint Report est un endpoint interne non documenté et
     * nécessite un boardId. En cas d'indisponibilité, le périmètre reste celui de l'API Agile — la photo est alors
     * partielle, ce que la vue signale.
     *
     * @param  DateTime $at Date d'observation
     * @return list<Issue>
     */
    private function rebuildScopeAt(DateTime $at): array
    {
        $builder = $this->ticketBuilder();
        $scope   = [];

        foreach ($this->issues as $issue) {
            if ($builder->wasInSprintAt($issue, $at, true)) {
                $scope[] = $issue;
            }
        }

        foreach ($this->fetchRemovedIssues() as $issue) {
            if ($builder->wasInSprintAt($issue, $at, false)) {
                $scope[] = $issue;
                $this->reintegratedCount++;
            }
        }

        return $scope;
    }

    /**
     * Récupère, avec leur changelog, les tickets retirés du sprint et jamais réintégrés.
     *
     * Le "Sprint Report" interne ne fournit que des payloads allégés : on n'en retient que les clés, pour un refetch
     * complet via l'API de recherche. Toute erreur (board inconnu, board Kanban sans rapport, évolution de
     * l'endpoint non documenté...) dégrade silencieusement vers un tableau vide plutôt que de casser la page.
     *
     * @return list<Issue>
     */
    private function fetchRemovedIssues(): array
    {
        if ($this->boardId === '') {
            return [];
        }

        try {
            $report = $this->jiraService->getSprintReport($this->boardId, $this->sprintId);
            $punted = $report['contents']['puntedIssues'] ?? [];
            if (!is_array($punted)) {
                return [];
            }

            $keys = [];
            foreach ($punted as $raw) {
                $key = trim((string) ($raw['key'] ?? ''));
                if ($key !== '') {
                    $keys[] = $key;
                }
            }

            $issues = [];
            foreach ($this->jiraService->getIssuesByKeys($keys, $this->config->getMetricIssueTypes()) as $raw) {
                $issues[] = new Issue($raw);
            }

            return $issues;
        } catch (Throwable $e) {
            error_log('Sprint: tickets retirés indisponibles (board ' . $this->boardId . ') : ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // Construction de la source unique de vérité
    // =========================================================================

    /**
     * Construit la liste $tickets (SprintTicketDto) enrichie à partir des objets Issue.
     *
     * Logique portée par SprintTicketBuilder (collaborateur extrait, lot A2 V2) :
     * reconstruction de l'état à la date de référence + assemblage des DTO.
     */
    private function buildTickets(): void
    {
        $this->tickets = $this->ticketBuilder()->build($this->issues, $this->resolveSprintEnd());
    }

    /**
     * Fabrique le constructeur de tickets du sprint (état courant du sprint injecté).
     */
    private function ticketBuilder(): SprintTicketBuilder
    {
        return new SprintTicketBuilder(
            $this->config,
            $this->timeline,
            $this->sprintId,
            $this->getName(),
            $this->startDateTime
        );
    }

    // =========================================================================
    // Calculs des métriques du sprint
    // =========================================================================

    /**
     * Atterrissage du sprint : nombre de tickets et total de Story Points par statut.
     *
     * Logique portée par SprintLandingBuilder (collaborateur extrait, lot A2 V2).
     */
    private function computeLandingByStatus(): void
    {
        $this->landingByStatus = (new SprintLandingBuilder($this->config))
            ->build($this->tickets, $this->sprintBoundedTimeByStatus, $this->issuesCount);
    }


    /**
     * Regroupe les tickets par étiquette contenant « objectif ».
     *
     * Logique portée par SprintObjectiveAnalyzer (collaborateur extrait, lot A2 V2).
     */
    private function computeObjectiveGroups(): void
    {
        $this->objectiveGroups = (new SprintObjectiveAnalyzer($this->config))
            ->buildGroups($this->tickets, $this->sprintData['goal'] ?? null);
    }

    /**
     * Construit la liste dédoublonnée des Features (parents) des tickets.
     */
    private function computeFeatures(): void
    {
        $features = [];

        foreach ($this->tickets as $ticket) {
            if ($ticket->parentKey === null || $ticket->parentKey === '') {
                continue;
            }

            $features[$ticket->parentKey] = [
                'key'     => $ticket->parentKey,
                'summary' => $ticket->parentSummary ?? '',
            ];
        }

        ksort($features, SORT_NATURAL);

        $this->features = array_values($features);
    }

    /**
     * Calcule les statistiques du sprint (totaux SP, fourchette, changement de périmètre, résumés).
     *
     * Logique portée par SprintMetricsCalculator (collaborateur extrait, lot A2 V2).
     */
    private function computeStats(): void
    {
        $this->stats = (new SprintMetricsCalculator())->buildStats($this->tickets, $this->fetchPuntedTickets());
    }

    /**
     * Récupère les tickets retirés du sprint et jamais réintégrés (invisibles pour
     * analyzeSprintMembership(), cf. SprintTicketBuilder.php), via le "Sprint Report" interne de
     * Jira. Enrichissement best-effort : cet endpoint n'est pas documenté officiellement par
     * Atlassian, donc toute erreur (board inconnu, board Kanban sans rapport, évolution future de
     * l'endpoint...) dégrade silencieusement vers un tableau vide plutôt que de casser la page.
     *
     * Sans objet en mode « date d'observation » : ces tickets sont alors déjà dans $tickets, reconstruits par
     * rebuildScopeAt(). Les repasser ici les compterait deux fois dans le bucket « retirés ».
     *
     * @return list<SprintTicketDto>
     */
    private function fetchPuntedTickets(): array
    {
        if ($this->boardId === '' || $this->asOfDate !== null) {
            return [];
        }

        try {
            $report = $this->jiraService->getSprintReport($this->boardId, $this->sprintId);
        } catch (Throwable $e) {
            error_log('Sprint: rapport sprintreport indisponible (board ' . $this->boardId . ') : ' . $e->getMessage());
            return [];
        }

        $punted = $report['contents']['puntedIssues'] ?? [];
        if (!is_array($punted)) {
            return [];
        }

        $existingKeys = array_map(static fn (SprintTicketDto $t): string => $t->key, $this->tickets);

        return $this->ticketBuilder()->buildPuntedTickets($punted, $existingKeys);
    }

    /**
     * Rejoue l'agrégation des temps d'attente sur les valeurs BORNÉES à la fenêtre du sprint.
     *
     * AbstractIssueCollection::computeAggregates() s'exécute dans le constructeur PARENT, donc avant que
     * SprintTicketBuilder n'ait restreint l'attente de chaque Issue au sprint. Sans ce rejeu, la carte
     * « Temps de mise en attente » et ses causes resteraient sur la vie entière des tickets, alors que le
     * détail par ticket, lui, est borné — deux définitions contradictoires sur la même page, et un écart
     * propagé jusqu'aux causes d'attente agrégées de /team-metrics.
     *
     * Même logique que le parent : total DÉDUPLIQUÉ au niveau de chaque issue, puis somme ; ventilation
     * additive par cause ; moyenne rapportée au nombre d'issues.
     */
    private function recomputeWaitingAggregates(): void
    {
        // Valeurs « cycle de vie complet » produites par le parent, conservées AVANT d'être écrasées :
        // elles sont affichées en second plan à côté des valeurs du sprint.
        $this->totalTimeSpentInWaitingFull   = $this->totalTimeSpentInWaiting;
        $this->averageTimeSpentInWaitingFull = $this->averageTimeSpentInWaiting;
        $this->aggregatedWaitingTimesFull    = $this->aggregatedWaitingTimes;

        $this->aggregatedWaitingTimes  = [];
        $this->totalTimeSpentInWaiting = 0.0;

        foreach ($this->issues as $issue) {
            foreach ($issue->getWaitingTimes() as $cause => $days) {
                $this->aggregatedWaitingTimes[$cause] = ($this->aggregatedWaitingTimes[$cause] ?? 0.0) + $days;
            }
            $this->totalTimeSpentInWaiting += $issue->getTimeSpentInWaiting();
        }

        $this->averageTimeSpentInWaiting = $this->issuesCount > 0
            ? round($this->totalTimeSpentInWaiting / $this->issuesCount, 2)
            : 0.0;
    }

    /**
     * Calcule la prédictibilité (SP) et l'engagement (sujets) du sprint vs la cible commune.
     *
     * Logique portée par SprintMetricsCalculator (collaborateur extrait, lot A2 V2).
     */
    private function computePredictability(): void
    {
        $this->predictability = (new SprintMetricsCalculator())->buildPredictability($this->tickets);
    }

    // =========================================================================
    // Calcul du temps par statut borné au sprint
    // =========================================================================

    /**
     * Agrège, pour toutes les issues, le temps passé dans chaque statut
     * en ne comptabilisant que la période [startDate → endDate du sprint].
     */
    private function computeSprintBoundedTimeByStatus(): void
    {
        if ($this->startDateTime === null) {
            return;
        }

        // Capturé en local : porte l'invariant « date de démarrage connue » jusqu'au calcul par issue.
        $sprintStart = $this->startDateTime;

        foreach ($this->issues as $issue) {
            foreach ($this->getIssueSprintBoundedTime($issue, $sprintStart) as $status => $days) {
                $this->sprintBoundedTimeByStatus[$status] =
                    ($this->sprintBoundedTimeByStatus[$status] ?? 0.0) + $days;
            }
        }

        foreach ($this->sprintBoundedTimeByStatus as $status => $days) {
            $this->sprintBoundedTimeByStatus[$status] = round($days, 1);
        }

        $this->sprintBoundedTimeByStatus = $this->timeline->normalizeNativeStatuses(
            $this->sprintBoundedTimeByStatus
        );
    }

    /**
     * Calcule le temps passé par statut pour une issue donnée,
     * en ne comptant que la période [startDate → sprintEnd].
     *
     * Algorithme en passe unique :
     * - Avant startDate : on met à jour currentStatus sans compter de temps.
     * - Après startDate : on accumule le temps entre deux transitions.
     * - On s'arrête à sprintEnd si une transition la dépasse.
     *
     * @param  DateTime            $sprintStart Démarrage du sprint (non nul, garanti par l'appelant)
     * @return array<string, float>             [status => jours ouvrés]
     */
    private function getIssueSprintBoundedTime(Issue $issue, DateTime $sprintStart): array
    {
        $timeByStatus = [];
        $history      = $issue->getHistory();
        $sprintEnd    = $this->resolveSprintEnd();

        // Statut initial = fromString de la première transition de statut
        $currentStatus = $issue->getStatusName();
        foreach ($history as $historyItem) {
            foreach ($historyItem['items'] ?? [] as $item) {
                if (($item['field'] ?? '') === 'status') {
                    $currentStatus = $item['fromString'] ?? $currentStatus;
                    break 2;
                }
            }
        }

        $currentDate = clone $sprintStart;

        foreach ($history as $historyItem) {
            if (empty($historyItem['created'])) {
                continue;
            }
            $entryDate = new DateTime($historyItem['created']);

            foreach ($historyItem['items'] ?? [] as $item) {
                if (($item['field'] ?? '') !== 'status') {
                    continue;
                }
                $toString = $item['toString'] ?? $currentStatus;

                if ($entryDate <= $sprintStart) {
                    $currentStatus = $toString;
                    continue;
                }

                if ($entryDate >= $sprintEnd) {
                    $days = $this->timeline->calculateBusinessDays($currentDate, $sprintEnd);
                    if ($days > 0) {
                        $timeByStatus[$currentStatus] =
                            ($timeByStatus[$currentStatus] ?? 0.0) + $days;
                    }
                    return $timeByStatus;
                }

                $days = $this->timeline->calculateBusinessDays($currentDate, $entryDate);
                if ($days > 0) {
                    $timeByStatus[$currentStatus] =
                        ($timeByStatus[$currentStatus] ?? 0.0) + $days;
                }
                $currentDate   = $entryDate;
                $currentStatus = $toString;
            }
        }

        // Dernier segment : de la dernière transition à la fin du sprint
        if ($currentDate < $sprintEnd) {
            $days = $this->timeline->calculateBusinessDays($currentDate, $sprintEnd);
            if ($days > 0) {
                $timeByStatus[$currentStatus] =
                    ($timeByStatus[$currentStatus] ?? 0.0) + $days;
            }
        }

        return $timeByStatus;
    }

    /**
     * Calcule les statistiques d'âge des tickets du sprint (moyen et médian).
     *
     * L'âge d'un ticket est le nombre de jours ouvrés entre sa création et la
     * date de référence du sprint (clôture si clôturé, aujourd'hui sinon).
     */
    private function computeTicketAgeStats(): void
    {
        $ages = [];
        $referenceDate = $this->resolveSprintEnd();

        foreach ($this->issues as $issue) {
            $createdDate = $issue->getCreatedDate(false);
            if ($createdDate instanceof DateTime) {
                $age = $this->timeline->calculateBusinessDays($createdDate, $referenceDate);
                if ($age > 0) {
                    $ages[] = $age;
                }
            }
        }

        if (!empty($ages)) {
            $this->ticketAgeStats = [
                'average' => round(array_sum($ages) / count($ages), 1),
                'median'  => self::calculateMedian($ages),
            ];
        } else {
            $this->ticketAgeStats = [
                'average' => 0.0,
                'median'  => 0.0,
            ];
        }
    }

    /**
     * Retourne la date de référence du sprint : borne de fin de tous les calculs (temps par statut, reconstruction
     * de l'état des tickets, âge des tickets).
     *
     * - Date d'observation fournie : elle fait foi, quel que soit l'état du sprint
     * - Sprint clôturé : completeDate, sinon endDate
     * - Sprint actif   : aujourd'hui
     * - Autres         : aujourd'hui
     */
    public function resolveSprintEnd(): DateTime
    {
        if ($this->asOfDate !== null) {
            return $this->asOfDate;
        }

        if (($this->sprintData['state'] ?? '') === self::STATE_CLOSED) {
            $date = $this->sprintData['completeDate']
                ?? $this->sprintData['endDate']
                ?? null;
            if ($date !== null) {
                return new DateTime($date);
            }
        }

        return new DateTime();
    }

    // =========================================================================
    // Helpers de filtrage / agrégation
    // =========================================================================

    // =========================================================================
    // Mise en cache (sprints clôturés, photo immuable)
    // =========================================================================

    /**
     * Sérialise la « photo » calculée du sprint en vue de sa mise en cache.
     *
     * Ne contient QUE les données consommées par SprintView et le template :
     * métadonnées + tableaux de métriques déjà calculés. Aucun objet Issue ni
     * service n'est embarqué (snapshot léger et JSON-sérialisable).
     */
    public function toSnapshot(): array
    {
        return [
            'sprintId'        => $this->sprintId,
            'projectKey'      => $this->projectKey,
            'boardId'         => $this->boardId,
            // Date d'observation (ISO) ou null : SEULE trace expliquant pourquoi les chiffres d'une photo diffèrent
            // de l'état Jira actuel. Consommée par la vue pour l'afficher.
            'asOfDate'          => $this->asOfDate?->format(DateTime::ATOM),
            'reintegratedCount' => $this->reintegratedCount,
            'sprintData'        => $this->sprintData,
            'issuesCount'     => $this->issuesCount,
            'landingByStatus' => $this->landingByStatus,
            'objectiveGroups' => $this->objectiveGroups,
            'features'        => $this->features,
            'stats'           => $this->stats,
            'predictability'  => $this->predictability,
            'ticketAgeStats'  => $this->ticketAgeStats,
            // Agrégats de temps d'attente : calculés au parcours des issues (constructeur), donc
            // absents d'un Sprint réhydraté sans Jira → à embarquer explicitement dans la photo.
            'totalTimeSpentInWaiting'   => $this->totalTimeSpentInWaiting,
            'averageTimeSpentInWaiting' => $this->averageTimeSpentInWaiting,
            'aggregatedWaitingTimes'    => $this->aggregatedWaitingTimes,
            // Pendants « cycle de vie complet », affichés en second plan.
            'totalTimeSpentInWaitingFull'   => $this->totalTimeSpentInWaitingFull,
            'averageTimeSpentInWaitingFull' => $this->averageTimeSpentInWaitingFull,
            'aggregatedWaitingTimesFull'    => $this->aggregatedWaitingTimesFull,
        ];
    }

    /**
     * Reconstruit un Sprint à partir d'un snapshot en cache, SANS appeler Jira.
     *
     * Le constructeur normal déclenche le chargement des issues via l'API ; on
     * l'évite ici (instanciation sans constructeur) car toutes les valeurs
     * affichées sont déjà présentes dans le snapshot. Réservé aux sprints
     * clôturés, dont la photo est immuable.
     *
     * @param array $snapshot Donnée produite par toSnapshot()
     */
    public static function fromSnapshot(array $snapshot): self
    {
        /** @var self $sprint */
        $sprint = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        // Sprint réhydraté sans constructeur : on initialise $config et $timeline (aucun accès Jira)
        // pour que les getters publics qui pourraient les lire (ex. getRemainingBusinessDays lit
        // $timeline) restent sûrs, sans « must not be accessed before initialization ».
        $sprint->config   = new Config();
        $sprint->timeline = new Timeline($sprint->config);

        $sprint->sprintId        = (string) ($snapshot['sprintId'] ?? '');
        $sprint->projectKey      = (string) ($snapshot['projectKey'] ?? '');
        $sprint->boardId         = (string) ($snapshot['boardId'] ?? '');
        // Absentes des snapshots antérieurs à la fonctionnalité « photo à date » : null / 0 = photo nominale.
        $asOf                     = $snapshot['asOfDate'] ?? null;
        $sprint->asOfDate         = is_string($asOf) && $asOf !== '' ? new DateTime($asOf) : null;
        $sprint->reintegratedCount = (int) ($snapshot['reintegratedCount'] ?? 0);
        $sprint->sprintData      = $snapshot['sprintData'] ?? [];
        $sprint->issuesCount     = (int) ($snapshot['issuesCount'] ?? 0);
        $sprint->landingByStatus = $snapshot['landingByStatus'] ?? [];
        $sprint->objectiveGroups = $snapshot['objectiveGroups'] ?? [];
        $sprint->features        = $snapshot['features'] ?? [];
        $sprint->stats           = $snapshot['stats'] ?? [];
        $sprint->predictability  = $snapshot['predictability'] ?? [];
        $sprint->ticketAgeStats  = $snapshot['ticketAgeStats'] ?? [];

        $sprint->totalTimeSpentInWaiting   = (float) ($snapshot['totalTimeSpentInWaiting'] ?? 0.0);
        $sprint->averageTimeSpentInWaiting = (float) ($snapshot['averageTimeSpentInWaiting'] ?? 0.0);
        $sprint->aggregatedWaitingTimes    = $snapshot['aggregatedWaitingTimes'] ?? [];

        // Snapshots antérieurs au double affichage : repli sur la valeur bornée plutôt qu'un faux zéro.
        $sprint->totalTimeSpentInWaitingFull   = (float) ($snapshot['totalTimeSpentInWaitingFull'] ?? $sprint->totalTimeSpentInWaiting);
        $sprint->averageTimeSpentInWaitingFull = (float) ($snapshot['averageTimeSpentInWaitingFull'] ?? $sprint->averageTimeSpentInWaiting);
        $sprint->aggregatedWaitingTimesFull    = $snapshot['aggregatedWaitingTimesFull'] ?? $sprint->aggregatedWaitingTimes;

        return $sprint;
    }

    /**
     * Complétion par objectif « objectif_N » / « sprint_goal_N » : tickets terminés / total et pourcentage.
     *
     * Dérivée de $this->objectiveGroups (déjà calculé par SprintObjectiveAnalyzer à la
     * construction et présent dans le snapshot), donc disponible aussi bien pour un sprint
     * frais que réhydraté via fromSnapshot()
     *
     * L'analyzer regroupe toute étiquette contenant « objectif / goal » ; on ne conserve ici que les objectifs numérotés « objectif_N / goal_N ».
     * « Terminé » = SprintTicketDto::$isDone (état à la date de référence du sprint), exposé dans la forme array via toArray().
     *
     * @return array<string, array{done:int, total:int, percent:float}>
     *         Clé = étiquette d'objectif ; valeur = agrégats. Ordre naturel (hérité du ksort
     *         de SprintObjectiveAnalyzer::buildGroups()).
     */
    public function getSprintGoalsCompletion(): array
    {
        $stats = [];

        foreach ($this->objectiveGroups as $group) {
            $label = $group['label'];

            // On ignore les groupes « objectif / goal » non numérotés (ex. « objectif_transverse »).
            // Séparateur avant le numéro optionnel et libre : objectif1, objectif-1, objectif 1, objectif_1.
            if (preg_match('/(?:objectif|goal)[_\- ]?\d+$/i', $label) !== 1) {
                continue;
            }

            $issues = $group['issues'];
            $total  = count($issues);

            // Compte des tickets terminés à la date de référence (flag figé dans le DTO).
            $done = 0;
            foreach ($issues as $issue) {
                if (($issue['isDone'] ?? false) === true) {
                    $done++;
                }
            }

            $stats[$label] = [
                'done'    => $done,
                'total'   => $total,
                'percent' => $total > 0 ? round($done / $total * 100, 1) : 0.0,
            ];
        }

        return $stats;
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function getSprintId(): string
    {
        return $this->sprintId;
    }

    /**
     * Date d'observation de la photo, ou null si le sprint est affiché nominalement (clôture / aujourd'hui).
     */
    public function getAsOfDate(): ?DateTime
    {
        return $this->asOfDate;
    }

    /**
     * Nombre de tickets réintégrés au périmètre par la reconstruction à date (sortis du sprint après la date
     * d'observation). 0 hors mode « photo à date ».
     */
    public function getReintegratedCount(): int
    {
        return $this->reintegratedCount;
    }

    public function getName(): string
    {
        return $this->sprintData['name'] ?? 'Sprint';
    }

    public function getAnalysisUrl(): string
    {
        return '/sprint?projectKey=' . $this->projectKey
        . '&boardId=' . $this->boardId
        . '&sprintId=' . $this->sprintId;
    }

    public function getState(): string
    {
        return $this->sprintData['state'] ?? '';
    }

    public function getGoal(): string
    {
        return $this->sprintData['goal'] ?? '';
    }

    public function getStartDate(): ?string
    {
        return $this->sprintData['startDate'] ?? null;
    }

    public function getEndDate(): ?string
    {
        return $this->sprintData['endDate'] ?? null;
    }

    public function getCompleteDate(): ?string
    {
        return $this->sprintData['completeDate'] ?? null;
    }

    /**
     * Retourne le nombre de jours ouvrés restants jusqu'à la fin du sprint.
     *
     * Uniquement pertinent pour un sprint actif : retourne null sinon.
     * Le résultat est arrondi au plafond (ceil) pour ne pas afficher « 0 j »
     * quand il reste quelques heures dans la journée en cours.
     */
    public function getRemainingBusinessDays(): ?int
    {
        if ($this->getState() !== self::STATE_ACTIVE) {
            return null;
        }

        $endDate = $this->sprintData['endDate'] ?? null;
        if ($endDate === null) {
            return null;
        }

        $remaining = $this->timeline->calculateBusinessDays(new DateTime(), new DateTime($endDate));

        return (int) ceil($remaining);
    }

    /**
     * Durée du sprint en jours ouvrés : du démarrage à la clôture (à défaut, la date de fin planifiée).
     *
     * Retourne 0 si une borne manque ou est illisible (sprint futur sans dates, snapshot partiel...).
     * Source unique du calcul, réutilisée par SprintView (affichage) et le digest LLM (vélocité).
     */
    public function getBusinessDays(): float
    {
        $start = $this->getStartDate();
        $end   = $this->getCompleteDate() ?: $this->getEndDate();

        if (empty($start) || empty($end)) {
            return 0.0;
        }

        try {
            return $this->timeline->calculateBusinessDays(new DateTime($start), new DateTime($end));
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * @return list<array{status: string, category: string, count: int, storyPoints: float,
     *     totalDays: float, averageDays: float}>
     */
    public function getLandingByStatus(): array
    {
        return $this->landingByStatus;
    }

    /**
     * Total d'attente sur le CYCLE DE VIE COMPLET des tickets — à opposer à getTotalTimeSpentInWaiting(),
     * borné au sprint. L'écart mesure l'attente héritée des sprints précédents.
     */
    public function getTotalTimeSpentInWaitingFull(): float
    {
        return $this->totalTimeSpentInWaitingFull;
    }

    /** Attente moyenne par ticket sur tout le cycle de vie (pendant de getAverageTimeSpentInWaiting()). */
    public function getAverageTimeSpentInWaitingFull(): float
    {
        return $this->averageTimeSpentInWaitingFull;
    }

    /**
     * Ventilation des causes d'attente sur tout le cycle de vie (pendant de getAggregatedWaitingTimes()).
     *
     * @return array<string, float>
     */
    public function getAggregatedWaitingTimesFull(): array
    {
        return $this->aggregatedWaitingTimesFull;
    }

    public function getObjectiveGroups(): array
    {
        return $this->objectiveGroups;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->stats;
    }

    /** @return array<string, mixed> */
    public function getPredictability(): array
    {
        return $this->predictability;
    }

    public function getTicketAgeStats(): array
    {
        return $this->ticketAgeStats;
    }

    public function getProjectKey(): string
    {
        return $this->projectKey;
    }

    public function getBoardId(): string
    {
        return $this->boardId;
    }
}
