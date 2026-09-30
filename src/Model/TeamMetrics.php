<?php

namespace App\Model;

use App\Service\SprintCacheService;
use App\Service\SprintConfigRepository;
use DateTime;
use Throwable;

/**
 * TeamMetrics
 *
 * Agrégat de plusieurs sprints CLÔTURÉS d'un même board, destiné à la page
 * « Métriques d'équipe ».
 *
 * Principe (KISS, réutilisation maximale) : chaque sprint sélectionné est chargé
 * via le modèle {@see Sprint} (depuis le cache immuable si disponible, sinon
 * calcul live puis mise en cache). TeamMetrics ne fait qu'AGRÉGER les données
 * déjà calculées par Sprint (atterrissage, prédictibilité, changement de périmètre,
 * tickets terminés...) — aucune logique de reconstruction d'historique ici.
 *
 * Les métriques produites :
 *  - moyennes par sprint : tickets terminés, Story Points terminés, sujets
 *    ajoutés, prédictibilité, reliquat, changement de périmètre ;
 *  - vélocité : Story Points terminés par jour ouvré (sur l'ensemble des
 *    sprints) ;
 *  - lead time / cycle time moyens & médians des tickets terminés ;
 *  - distribution du cycle time par valeur de Story Points (combien de jours
 *    « vaut » chaque estimation) — base d'un futur graphique à points.
 */
class TeamMetrics
{
    /** @var string[] IDs des sprints sélectionnés */
    private array $sprintIds;

    /** Clé du projet Jira (transmise aux Sprint, optionnelle). */
    private string $projectKey;

    /** ID du board Jira (transmise aux Sprint, optionnelle). */
    private string $boardId;

    /** Force le recalcul live en ignorant le cache (bouton « Recalculer »). */
    private bool $forceRefresh;

    private SprintCacheService $cache;
    private Timeline $timeline;

    /**
     * Map des réglages par sprint saisis manuellement (jours/homme...), chargée
     * une fois pour le projet : sprintId → ['manDays' => float, ...].
     *
     * @var array<string, array>
     */
    private array $sprintConfig = [];

    /**
     * Résumé par sprint (une ligne par sprint chargé), source des moyennes.
     *
     * @var array<int, array{
     *     id:string, name:string, state:string,
     *     startDate:?string, endDate:?string, completeDate:?string,
     *     businessDays:float,
     *     doneCount:int, doneStoryPoints:float, totalCount:int,
     *     waitingCauses:array<string, float>,
     *     totalStoryPoints:float, totalRemainingStoryPoints:float,
     *     addedCount:int, addedStoryPoints:float,
     *     predictabilityPercent:float, engagementPercent:float,
     *     reliquatCount:int, reliquatRemainingSp:float,
     *     scopeGrowthPercent:float|null, newPrioPercent:float|null,
     *     manDays:float|null, manDaysFromCapacity:bool, capacityPoints:float|null
     * }>
     *
     * @todo PHPStan(offsetAccess.notFound/nullCoalesce.offset) : shape incomplète.
     *       Ajouter les clés produites par summarizeSprint() et lues plus bas :
     *       reliquatStoryPoints, doneIssues, analysisUrl, avgWaitingTime, velocityPerManDay, fromCache.
     */
    private array $sprints = [];

    /** Moyennes et totaux agrégés (cf. computeAggregates()). */
    private array $stats = [];

    /**
     * Distribution du cycle time par valeur de Story Points.
     *
     * @var array<int, array{
     *     storyPoints:float, count:int, values:float[],
     *     average:float, median:float, min:float, max:float
     * }>
     */
    private array $cycleTimeBySp = [];

    /**
     * Nuage de points « points d'effort × temps de cycle », groupé par type de
     * ticket Jira (Story, Tâche, Bug...). Alimente le graphique segmentable.
     *
     * @var array<string, array<int, array{x:float, y:float, key:string}>>
     */
    private array $cycleTimeScatterByType = [];

    /**
     * Temps d'attente cumulé par cause sur l'ensemble des sprints sélectionnés, en jours ouvrés,
     * trié par durée décroissante.
     *
     * @var array<string, float>
     */
    private array $aggregatedWaitingTimes = [];

    /** @var array<int, string> Messages d'erreur par sprint non chargé */
    private array $errors = [];

    /**
     * Sprints à charger depuis leur photo « à date » plutôt que depuis leur état de clôture.
     *
     * Rattrape un sprint dont le reliquat a été déplacé vers le sprint suivant AVANT la clôture : son état de
     * clôture ne montre alors que les tickets terminés, ce qui fausse la vélocité et la prédictibilité moyennes.
     * La photo est choisie explicitement par l'utilisateur dans le sélecteur de sprints.
     *
     * @var list<string>
     */
    private array $photoSprintIds = [];

    /**
     * Charge chaque sprint sélectionné (cache-first) puis calcule les agrégats d'équipe, la
     * distribution du cycle time par estimation et le nuage de points par type de ticket.
     *
     * @param string[] $sprintIds       IDs des sprints clôturés à agréger
     * @param string   $projectKey      Clé du projet (optionnelle, transmise aux Sprint)
     * @param string   $boardId         ID du board (optionnel, transmis aux Sprint)
     * @param bool     $forceRefresh    Ignore le cache et recalcule depuis Jira (puis re-cache)
     * @param string[] $photoSprintIds  IDs des sprints à charger depuis leur photo « à date » plutôt que depuis
     *                                  leur état de clôture (cf. loadSprints())
     *
     * @throws \Exception Si aucun sprint n'est sélectionné ou si aucun n'est exploitable
     */
    public function __construct(
        array $sprintIds,
        string $projectKey = '',
        string $boardId = '',
        bool $forceRefresh = false,
        array $photoSprintIds = []
    ) {
        $this->sprintIds    = array_values(array_filter(array_map('trim', $sprintIds)));
        $this->projectKey   = $projectKey;
        $this->boardId      = $boardId;
        $this->forceRefresh = $forceRefresh;
        $this->photoSprintIds = array_values(array_filter(array_map('trim', $photoSprintIds)));
        $this->cache        = new SprintCacheService();
        $this->timeline     = new Timeline();

        // Jours/homme saisis par l'utilisateur (hors Jira), pour la vélocité SP/j-h.
        $this->sprintConfig = (new SprintConfigRepository())->getAll($projectKey);

        if ($this->sprintIds === []) {
            throw new \Exception('Aucun sprint sélectionné.');
        }

        $this->loadSprints();

        if ($this->sprints === []) {
            throw new \Exception(
                'Aucun sprint exploitable. ' . implode(' ', $this->errors)
            );
        }

        $this->computeAggregates();
        $this->computeAggregatedWaitingTimes();
        $this->computeCycleTimeBySp();
        $this->computeCycleTimeScatterByType();
    }

    // =========================================================================
    // Chargement des sprints
    // =========================================================================

    /**
     * Charge chaque sprint sélectionné et en extrait le résumé exploitable.
     *
     * Stratégie de cache identique à SprintController : photo en cache utilisée
     * en priorité (sprint clôturé = immuable) ; sinon calcul live, puis mise en
     * cache si le sprint est bien clôturé. En mode `forceRefresh`, le cache est
     * ignoré en lecture (recalcul live forcé) mais le snapshot frais est tout de
     * même réécrit. Un sprint en échec est ignoré et son erreur mémorisée, pour
     * ne pas faire échouer toute la page.
     *
     * Un sprint listé dans $photoSprintIds est servi depuis sa photo « à date », qui fait alors foi — y compris
     * en mode `forceRefresh` : elle est le fruit d'une correction manuelle, la recalculer depuis Jira la
     * remplacerait par l'état de clôture erroné qu'elle corrige précisément. Repli silencieux sur le comportement
     * nominal si la photo a disparu (cache purgé).
     *
     * Le flag `fromCache` est tracké ici (une lecture du cache, pas deux).
     */
    private function loadSprints(): void
    {
        foreach ($this->sprintIds as $sprintId) {
            try {
                $photo = in_array($sprintId, $this->photoSprintIds, true)
                    ? $this->cache->getAsOf($sprintId, $this->projectKey)
                    : null;

                // Le cache nominal n'est lu que si aucune photo ne prend la main sur ce sprint.
                $cached    = ($photo !== null || $this->forceRefresh) ? null : $this->cache->get($sprintId, $this->projectKey);
                $fromCache = $photo !== null || $cached !== null;

                if ($photo !== null) {
                    $sprint = Sprint::fromSnapshot($photo['data']);
                } elseif ($cached !== null) {
                    $sprint = Sprint::fromSnapshot($cached['data']);
                } else {
                    $sprint = new Sprint($sprintId, $this->projectKey, $this->boardId);
                    if ($sprint->getState() === Sprint::STATE_CLOSED) {
                        $this->cache->put($sprintId, $sprint->toSnapshot(), $this->projectKey);
                    }
                }

                $summary               = $this->summarizeSprint($sprint);
                $summary['fromCache']  = $fromCache;
                $this->sprints[]       = $summary;
            } catch (Throwable $e) {
                $this->errors[] = "Sprint $sprintId : " . $e->getMessage();
            }
        }
    }

    /**
     * Extrait d'un Sprint le résumé chiffré nécessaire aux agrégats.
     *
     * @return array Ligne de résumé (cf. propriété $sprints)
     */
    private function summarizeSprint(Sprint $sprint): array
    {
        $stats          = $sprint->getStats();
        $predictability = $sprint->getPredictability();
        $scope          = $stats['scopeChange'] ?? [];
        $doneIssues     = $stats['doneTickets']['issues'] ?? [];
        $waitingValues  = array_filter(
            array_map(static fn (array $t): float => (float) ($t['waitingTime'] ?? 0), $doneIssues),
            static fn (float $v): bool => $v > 0
        );
        $avgWaitingTime = $waitingValues
            ? round(array_sum($waitingValues) / count($waitingValues), 1)
            : 0.0;

        // Jours/homme effectifs de ce sprint (null si aucune source) et vélocité SP/jours-homme dérivée.
        // La valeur provient de la grille de l'onglet Capacité quand elle existe (elle fait alors foi et
        // le champ est en lecture seule), sinon de la saisie manuelle.
        $doneSp  = (float) ($stats['doneTickets']['storyPoints'] ?? 0);
        $manDays = isset($this->sprintConfig[$sprint->getSprintId()]['manDays'])
            ? (float) $this->sprintConfig[$sprint->getSprintId()]['manDays']
            : null;
        $manDaysFromCapacity = !empty($this->sprintConfig[$sprint->getSprintId()]['manDaysFromCapacity']);
        $velocityPerManDay   = ($manDays !== null && $manDays > 0)
            ? round($doneSp / $manDays, 2)
            : null;

        // Capacité en points saisie pour ce sprint (null si non renseignée).
        $capacityPoints = isset($this->sprintConfig[$sprint->getSprintId()]['capacityPoints'])
            ? (float) $this->sprintConfig[$sprint->getSprintId()]['capacityPoints']
            : null;

        return [
            'id'                    => $sprint->getSprintId(),
            'name'                  => $sprint->getName(),
            'analysisUrl'          => $sprint->getAnalysisUrl(),
            'state'                 => $sprint->getState(),
            'startDate'             => $sprint->getStartDate(),
            'endDate'               => $sprint->getEndDate(),
            'completeDate'          => $sprint->getCompleteDate(),
            'businessDays'          => $this->resolveBusinessDays($sprint),
            'doneCount'             => (int) ($stats['doneTickets']['count'] ?? 0),
            'doneStoryPoints'       => (float) ($stats['doneTickets']['storyPoints'] ?? 0),
            // Volume total de tickets du sprint (périmètre complet, terminés + non terminés) — dénominateur
            // de la part du reliquat. Présent dans le snapshot, donc fiable sur un sprint réhydraté.
            'totalCount'            => $sprint->getIssuesCount(),
            // Ventilation du temps d'attente par cause (statut d'attente ou étiquette), en jours ouvrés.
            // Embarquée dans le snapshot, donc disponible sans appel Jira sur un sprint réhydraté.
            'waitingCauses'         => $sprint->getAggregatedWaitingTimes(),
            // Total des Story Points du sprint (périmètre complet) et total « Reste
            // à faire » (champ dédié, repli SP) — base du delta de capacité.
            'totalStoryPoints'      => (float) ($stats['totalStoryPoints'] ?? 0),
            'totalRemainingStoryPoints' => (float) ($stats['totalRemainingStoryPoints'] ?? 0),
            'addedCount'            => (int) ($stats['addedDuringSprint']['count'] ?? 0),
            'addedStoryPoints'      => (float) ($stats['addedDuringSprint']['storyPoints'] ?? 0),
            'predictabilityPercent' => (float) ($predictability['achievedPercent'] ?? 0),
            // Engagement : sujets du périmètre initial terminés / sujets au démarrage
            // (en %), repris tel quel du calcul Sprint::computePredictability().
            'engagementPercent'     => (float) ($predictability['engagementPercent'] ?? 0),
            'reliquatCount'         => (int) ($stats['reliquat']['count'] ?? 0),
            // Story Points des tickets non terminés (base principale du reliquat).
            'reliquatStoryPoints'   => (float) ($stats['reliquat']['storyPoints'] ?? 0),
            // Champ « Reste à faire » (spRemaining) — non renseigné par toutes les
            // équipes ; affiché en complément seulement si son total est non nul.
            'reliquatRemainingSp'   => (float) ($stats['reliquat']['remainingStoryPoints'] ?? 0),
            'scopeGrowthPercent'    => $scope['storyPointsGrowthPercent'] ?? null,
            'newPrioPercent'        => $scope['newPrioStoryPointsPercent'] ?? null,
            'avgWaitingTime'        => $avgWaitingTime,
            // Jours/homme effectifs (null si aucune source), leur provenance, et la vélocité SP/jours-homme.
            'manDays'               => $manDays,
            'manDaysFromCapacity'   => $manDaysFromCapacity,
            'velocityPerManDay'     => $velocityPerManDay,
            // Capacité en points saisie (null si non renseignée).
            'capacityPoints'        => $capacityPoints,
            // Tickets terminés (avec SP, cycleTime, leadTime, waitingTime) pour
            // les calculs de temps et la distribution par estimation.
            'doneIssues'            => $doneIssues,
        ];
    }

    /**
     * Durée du sprint en jours ouvrés : du démarrage à la clôture (à défaut, à
     * la date de fin théorique). Retourne 0 si les dates sont absentes/invalides.
     */
    private function resolveBusinessDays(Sprint $sprint): float
    {
        $start = $sprint->getStartDate();
        $end   = $sprint->getCompleteDate() ?: $sprint->getEndDate();

        if (empty($start) || empty($end)) {
            return 0.0;
        }

        try {
            return $this->timeline->calculateBusinessDays(new DateTime($start), new DateTime($end));
        } catch (Throwable) {
            return 0.0;
        }
    }

    // =========================================================================
    // Agrégation des moyennes
    // =========================================================================

    /**
     * Calcule les totaux, moyennes par sprint et la vélocité.
     *
     * - Moyennes « par sprint » : total / nombre de sprints chargés.
     * - Vélocité : total des Story Points terminés / total des jours ouvrés des
     *   sprints (Story Points livrés par jour de travail).
     * - Lead/Cycle time : moyenne et médiane sur l'ensemble des tickets terminés
     *   de tous les sprints (seules les valeurs strictement positives comptent,
     *   un temps nul signalant une donnée non mesurable).
     * - Changement de périmètre : moyenne sur les seuls sprints où le pourcentage est
     *   défini (un sprint sans périmètre planifié renvoie null et est exclu).
     */
    private function computeAggregates(): void
    {
        $count = count($this->sprints);

        $totalDone        = 0;
        $totalDoneSp      = 0.0;
        $totalAdded       = 0;
        $totalAddedSp     = 0.0;
        $totalReliquat        = 0;
        $totalReliquatSp      = 0.0; // Story Points des tickets non terminés
        $totalReliquatRemaining = 0.0; // champ « Reste à faire » cumulé

        // Part du reliquat DANS CHAQUE sprint (en %), moyennée ensuite : chaque sprint pèse pareil,
        // quelle que soit sa taille. Un sprint au volume nul n'a pas de part définie et est exclu.
        $reliquatTicketShares = [];
        $reliquatSpShares     = [];
        $totalBusinessDays = 0.0;
        $totalPredict     = 0.0;
        $totalEngagement  = 0.0;

        // Vélocité jours-homme : cumuls restreints aux sprints réellement renseignés.
        $totalManDays          = 0.0;
        $totalDoneSpWithManDays = 0.0;

        // SP par personne : valeurs par sprint (effectif moyen estimé = jours-homme /
        // jours ouvrés), moyennées ensuite sur les sprints réellement renseignés.
        $spPerPersonValues     = [];

        // Jours-homme saisis par sprint (> 0), pour la moyenne et le min/max.
        $manDaysValues         = [];

        $totalRemainingStoryPoints = 0.0; // total « Reste à faire » cumulé (tous sprints)
        $capacityValues            = []; // capacités en points saisies (> 0)
        $capacityDeltaValues       = []; // (total SP − capacité) des sprints avec capa saisie

        $growthValues       = []; // % changement de périmètre définis
        $newPrioValues      = []; // % nouvelle_prio définis
        $cycleTimes         = []; // cycle times > 0 des tickets terminés
        $leadTimes          = []; // lead times > 0 des tickets terminés
        $waitingTimes       = []; // waiting times > 0 des tickets terminés

        foreach ($this->sprints as $sprint) {
            $totalDone         += $sprint['doneCount'];
            $totalDoneSp       += $sprint['doneStoryPoints'];
            $totalAdded        += $sprint['addedCount'];
            $totalAddedSp      += $sprint['addedStoryPoints'];
            $reliquatSp = $sprint['reliquatStoryPoints'];
            $totalReliquat         += $sprint['reliquatCount'];
            $totalReliquatSp       += $reliquatSp;
            $totalReliquatRemaining += $sprint['reliquatRemainingSp'];
            if ($sprint['totalCount'] > 0) {
                $reliquatTicketShares[] = $sprint['reliquatCount'] / $sprint['totalCount'] * 100;
            }
            if ($sprint['totalStoryPoints'] > 0) {
                $reliquatSpShares[] = $reliquatSp / $sprint['totalStoryPoints'] * 100;
            }
            $totalBusinessDays += $sprint['businessDays'];
            $totalPredict      += $sprint['predictabilityPercent'];
            $totalEngagement   += $sprint['engagementPercent'];
            $totalRemainingStoryPoints += (float) ($sprint['totalRemainingStoryPoints'] ?? 0);

            // Delta de capacité : seuls les sprints avec une capacité saisie (> 0)
            // entrent dans la moyenne (total SP du sprint − capacité en points).
            $capacityPoints = $sprint['capacityPoints'] ?? null;
            if ($capacityPoints !== null && $capacityPoints > 0) {
                $capacityValues[]      = (float) $capacityPoints;
                $capacityDeltaValues[] = (float) ($sprint['totalStoryPoints'] ?? 0) - (float) $capacityPoints;
            }

            // Seuls les sprints avec des jours/homme saisis (> 0) entrent dans la
            // vélocité SP/jours-homme, au numérateur comme au dénominateur.
            $manDays = $sprint['manDays'] ?? null;
            if ($manDays !== null && $manDays > 0) {
                $totalManDays           += $manDays;
                $totalDoneSpWithManDays += $sprint['doneStoryPoints'];
                $manDaysValues[]         = (float) $manDays;

                // SP par personne du sprint = SP terminés / effectif moyen estimé,
                // avec effectif = jours-homme / jours ouvrés (donc SP × jours / j-h).
                if ($sprint['businessDays'] > 0) {
                    $spPerPersonValues[] =
                        $sprint['doneStoryPoints'] * $sprint['businessDays'] / $manDays;
                }
            }

            if ($sprint['scopeGrowthPercent'] !== null) {
                $growthValues[] = (float) $sprint['scopeGrowthPercent'];
            }
            if ($sprint['newPrioPercent'] !== null) {
                $newPrioValues[] = (float) $sprint['newPrioPercent'];
            }

            foreach ($sprint['doneIssues'] as $ticket) {
                $cycle = (float) ($ticket['cycleTime'] ?? 0);
                $lead  = (float) ($ticket['leadTime'] ?? 0);
                $wait  = (float) ($ticket['waitingTime'] ?? 0);
                if ($cycle > 0) {
                    $cycleTimes[] = $cycle;
                }
                if ($lead > 0) {
                    $leadTimes[] = $lead;
                }
                if ($wait > 0) {
                    $waitingTimes[] = $wait;
                }
            }
        }

        $this->stats = [
            'sprintCount'            => $count,
            'totalDoneStoryPoints'   => $totalDoneSp,
            'totalBusinessDays'      => round($totalBusinessDays, 1),

            'avgDoneTickets'         => round($totalDone / $count, 1),
            'avgDoneStoryPoints'     => round($totalDoneSp / $count, 1),
            'avgAddedTickets'        => round($totalAdded / $count, 1),
            'avgAddedStoryPoints'    => round($totalAddedSp / $count, 1),
            'avgBusinessDays'        => round($totalBusinessDays / $count, 1),
            // Cible de prédictibilité du référentiel sprint (ligne sur le graphique).
            'predictabilityTarget'   => Sprint::PREDICTABILITY_TARGET_PERCENT,
            'avgReliquatTickets'     => round($totalReliquat / $count, 1),
            'avgReliquatStoryPoints' => round($totalReliquatSp / $count, 1),
            'avgReliquatRemainingSp' => round($totalReliquatRemaining / $count, 1),

            // Part moyenne du volume d'un sprint restée non terminée à sa clôture : on calcule le taux
            // de reliquat DE CHAQUE sprint (reliquat / volume du sprint), puis on moyenne ces taux —
            // chaque sprint pèse donc pareil, indépendamment de sa taille. Les sprints au volume nul
            // (aucun ticket, ou aucun point estimé pour la part SP) sont exclus de la moyenne.
            // Null si aucun sprint n'a de part définie.
            'reliquatTicketSharePercent' => $reliquatTicketShares !== []
                ? round(array_sum($reliquatTicketShares) / count($reliquatTicketShares), 1)
                : null,
            'reliquatSpSharePercent'     => $reliquatSpShares !== []
                ? round(array_sum($reliquatSpShares) / count($reliquatSpShares), 1)
                : null,

            // « Reste à faire » moyen par sprint (champ dédié, repli SP) — colonne
            // du tableau de détail.
            'avgRemainingStoryPoints' => round($totalRemainingStoryPoints / $count, 1),

            // Capacité en points : moyenne sur les sprints où elle est saisie, et
            // delta moyen (total SP − capacité) sur ces mêmes sprints (null si aucun).
            'avgCapacityPoints'      => $capacityValues !== []
                ? round(array_sum($capacityValues) / count($capacityValues), 1)
                : null,
            'avgCapacityDelta'       => $capacityDeltaValues !== []
                ? round(array_sum($capacityDeltaValues) / count($capacityDeltaValues), 1)
                : null,
            // Vrai si au moins une équipe renseigne le champ « Reste à faire ».
            'hasReliquatRemaining'   => $totalReliquatRemaining > 0,
            'avgPredictability'      => round($totalPredict / $count, 1),
            // Engagement moyen (tickets planifiés terminés / planifiés) ; même cible
            // que la prédictibilité (predictabilityTarget).
            'avgEngagement'          => round($totalEngagement / $count, 1),

            // Vélocité : Story Points terminés par jour ouvré.
            'velocityPerDay'         => $totalBusinessDays > 0
                ? round($totalDoneSp / $totalBusinessDays, 2)
                : 0.0,

            // Vélocité : Story Points terminés par jour-homme saisi (null si aucune saisie).
            'totalManDays'           => round($totalManDays, 1),
            'velocityPerManDay'      => $totalManDays > 0
                ? round($totalDoneSpWithManDays / $totalManDays, 2)
                : null,

            // Durée pour terminer 1 Story Point (inverses des vélocités ci-dessus).
            // Jours ouvrés / SP terminé.
            'daysPerSp'              => $totalDoneSp > 0
                ? round($totalBusinessDays / $totalDoneSp, 2)
                : 0.0,
            // Jours-homme / SP terminé (null si aucune saisie de jours-homme).
            'manDaysPerSp'           => $totalDoneSpWithManDays > 0
                ? round($totalManDays / $totalDoneSpWithManDays, 2)
                : null,

            // SP terminés par personne et par sprint (moyenne ; effectif moyen estimé
            // = jours-homme / jours ouvrés). Null si aucun sprint renseigné.
            'avgSpPerPerson'         => $spPerPersonValues !== []
                ? round(array_sum($spPerPersonValues) / count($spPerPersonValues), 2)
                : null,

            // Jours-homme par sprint : moyenne et fourchette (null si aucune saisie).
            'avgManDays'             => $manDaysValues !== []
                ? round(array_sum($manDaysValues) / count($manDaysValues), 1)
                : null,
            'minManDays'             => $manDaysValues !== [] ? round(min($manDaysValues), 1) : null,
            'maxManDays'             => $manDaysValues !== [] ? round(max($manDaysValues), 1) : null,

            // Changement de périmètre : moyenne sur les sprints où la valeur est définie.
            'avgScopeGrowthPercent'  => $growthValues !== []
                ? round(array_sum($growthValues) / count($growthValues), 1)
                : null,
            'avgnewPrioPercent' => $newPrioValues !== []
                ? round(array_sum($newPrioValues) / count($newPrioValues), 1)
                : null,

            // Temps de traversée des tickets terminés (jours ouvrés).
            'doneTicketCount'        => count($cycleTimes),
            'avgCycleTime'           => $cycleTimes !== []
                ? round(array_sum($cycleTimes) / count($cycleTimes), 1) : 0.0,
            'medianCycleTime'        => $this->median($cycleTimes),
            'avgLeadTime'            => $leadTimes !== []
                ? round(array_sum($leadTimes) / count($leadTimes), 1) : 0.0,
            'medianLeadTime'         => $this->median($leadTimes),
            'avgWaitingTime'         => $waitingTimes !== []
                ? round(array_sum($waitingTimes) / count($waitingTimes), 1) : 0.0,
            'medianWaitingTime'      => $this->median($waitingTimes),
        ];
    }

    // =========================================================================
    // Causes d'attente cumulées
    // =========================================================================

    /**
     * Cumule la ventilation du temps d'attente par cause sur tous les sprints sélectionnés.
     *
     * Chaque sprint fournit déjà sa propre ventilation (statut d'attente ou étiquette → jours ouvrés) ;
     * on additionne cause par cause, puis on trie par durée décroissante pour afficher les causes les
     * plus coûteuses en premier — même présentation que la page /sprint, à l'échelle de la sélection.
     *
     * Note : au sein d'un sprint, un jour couvert par plusieurs causes n'est compté qu'une fois, mais
     * la somme inter-sprints reste additive (les sprints ne se recouvrent pas dans le temps).
     */
    private function computeAggregatedWaitingTimes(): void
    {
        $totals = [];

        foreach ($this->sprints as $sprint) {
            foreach ($sprint['waitingCauses'] as $cause => $days) {
                $totals[$cause] = ($totals[$cause] ?? 0.0) + (float) $days;
            }
        }

        foreach ($totals as $cause => $days) {
            $totals[$cause] = round($days, 1);
        }

        arsort($totals);

        $this->aggregatedWaitingTimes = $totals;
    }

    // =========================================================================
    // Distribution du cycle time par estimation (Story Points)
    // =========================================================================

    /**
     * Construit la distribution du cycle time par valeur de Story Points.
     *
     * Pour chaque ticket terminé estimé (Story Points renseigné et > 0) avec un
     * cycle time mesurable (> 0), on range son cycle time dans le « seau » de son
     * estimation. On en déduit, par estimation, le nombre de tickets et les
     * statistiques de durée (moyenne, médiane, min, max).
     *
     * Objectif : visualiser à combien de jours de cycle time équivaut réellement
     * chaque estimation (ex : « les tickets estimés à 3 ont duré en moyenne N
     * jours »). Le tableau `values` conserve les valeurs brutes pour alimenter un
     * futur graphique à points.
     */
    private function computeCycleTimeBySp(): void
    {
        $buckets = []; // [spValue => float[] cycle times]

        foreach ($this->sprints as $sprint) {
            foreach ($sprint['doneIssues'] as $ticket) {
                $sp    = $ticket['storyPoints'] ?? null;
                $cycle = (float) ($ticket['cycleTime'] ?? 0);

                if ($sp === null || $sp <= 0 || $cycle <= 0) {
                    continue;
                }

                $key             = (string) $sp;
                $buckets[$key][] = $cycle;
            }
        }

        // Tri par valeur d'estimation croissante (1, 2, 3, 5, 8...).
        uksort($buckets, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

        foreach ($buckets as $spKey => $values) {
            $this->cycleTimeBySp[] = [
                'storyPoints' => (float) $spKey,
                'count'       => count($values),
                'values'      => $values,
                'average'     => round(array_sum($values) / count($values), 1),
                'median'      => $this->median($values),
                'min'         => min($values),
                'max'         => max($values),
            ];
        }
    }

    /**
     * Construit le nuage de points « points d'effort × temps de cycle », groupé
     * par type de ticket Jira.
     *
     * Pour chaque ticket terminé estimé (Story Points > 0) avec un cycle time
     * mesurable (> 0), on produit un point {x: estimation, y: cycle time} rangé
     * sous son type (Story, Tâche, Bug...). Le graphe segmenté en aval permet de
     * voir si un type donné dérive (ex : les Bugs estimés à 3 prennent plus de
     * temps que les Stories).
     *
     * Les types sont triés par nombre de points décroissant (le plus représenté
     * en premier), pour un affichage stable.
     */
    private function computeCycleTimeScatterByType(): void
    {
        $byType = []; // [typeName => array<{x, y, key}>]

        foreach ($this->sprints as $sprint) {
            foreach ($sprint['doneIssues'] as $ticket) {
                $sp    = $ticket['storyPoints'] ?? null;
                $cycle = (float) ($ticket['cycleTime'] ?? 0);

                if ($sp === null || $sp <= 0 || $cycle <= 0) {
                    continue;
                }

                // Type absent (anciens snapshots) → repli neutre « Autre ».
                $type = (string) ($ticket['issueType'] ?? '') ?: 'Autre';

                $byType[$type][] = [
                    'x'   => (float) $sp,
                    'y'   => $cycle,
                    'key' => (string) ($ticket['key'] ?? ''),
                ];
            }
        }

        // Types les plus représentés d'abord (affichage stable et lisible).
        uasort($byType, static fn (array $a, array $b): int => count($b) <=> count($a));

        $this->cycleTimeScatterByType = $byType;
    }

    /**
     * Calcule la médiane d'une liste de valeurs numériques.
     *
     * @param  float[] $values
     * @return float   0.0 si la liste est vide
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $mid   = intdiv($count, 2);

        return ($count % 2 === 0)
            ? round(($values[$mid - 1] + $values[$mid]) / 2, 1)
            : round($values[$mid], 1);
    }

    // =========================================================================
    // Getters
    // =========================================================================

    /** Moyennes et totaux agrégés. */
    public function getStats(): array
    {
        return $this->stats;
    }

    /** Résumé par sprint (pour le tableau de détail). */
    public function getSprints(): array
    {
        return $this->sprints;
    }

    /** Distribution du cycle time par estimation. */
    public function getCycleTimeBySp(): array
    {
        return $this->cycleTimeBySp;
    }

    /**
     * @return array<string, array<int, array{x:float, y:float, key:string}>>
     *         Nuage de points (effort × cycle time) par type de ticket
     */
    public function getCycleTimeScatterByType(): array
    {
        return $this->cycleTimeScatterByType;
    }

    /**
     * Distribution du cycle time par estimation ET par type de ticket.
     *
     * Même agrégation que getCycleTimeBySp() (min/max/médiane/moyenne du cycle time observé
     * pour chaque valeur de Story Points), mais ventilée par type (Story, Bug, Spike...).
     * Dérivée de getCycleTimeScatterByType(), dont chaque point porte déjà {x: story points, y: cycle time}.
     *
     * Algorithme, pour chaque type :
     *  1. Regroupe les cycle times (y) par estimation (x).
     *  2. Agrège chaque groupe : nb, moyenne, médiane, min, max.
     *  3. Trie par estimation croissante (0.5, 1, 2, 3, 5...).
     *
     * @return array<string, list<array{storyPoints: float, count: int,
     *     average: float, median: float, min: float, max: float}>>
     *     Clé = type de ticket ; valeur = distribution triée par estimation croissante.
     */
    public function getCycleTimeBySpByType(): array
    {
        $result = [];

        foreach ($this->getCycleTimeScatterByType() as $type => $points) {
            // 1. Cycle times regroupés par estimation. Clé string pour éviter que 0.5 ne soit tronqué en index de tableau ; la valeur float est conservée pour le tri.
            $cycleTimesBySp = [];
            foreach ($points as $point) {
                $cycleTimesBySp[(string) $point['x']][] = (float) $point['y'];
            }

            // 2. Agrégation par estimation.
            $rows = [];
            foreach ($cycleTimesBySp as $sp => $cycleTimes) {
                $rows[] = [
                    'storyPoints' => (float) $sp,
                    'count'       => count($cycleTimes),
                    'average'     => round(array_sum($cycleTimes) / count($cycleTimes), 2),
                    'median'      => AbstractIssueCollection::calculateMedian($cycleTimes),
                    'min'         => min($cycleTimes),
                    'max'         => max($cycleTimes),
                ];
            }

            // 3. Tri par estimation croissante.
            usort($rows, static fn (array $a, array $b): int => $a['storyPoints'] <=> $b['storyPoints']);

            $result[$type] = $rows;
        }

        return $result;
    }

    /**
     * Temps d'attente cumulé par cause sur les sprints sélectionnés (jours ouvrés), du plus coûteux
     * au moins coûteux.
     *
     * @return array<string, float>
     */
    public function getAggregatedWaitingTimes(): array
    {
        return $this->aggregatedWaitingTimes;
    }

    /** @return string[] Erreurs des sprints non chargés (sprints partiels ignorés) */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** Clé du projet Jira (pour la persistance des jours/homme). */
    public function getProjectKey(): string
    {
        return $this->projectKey;
    }

    /** ID du board analysé ('' si non transmis) — pour les liens profonds vers /sprint. */
    public function getBoardId(): string
    {
        return $this->boardId;
    }
}
