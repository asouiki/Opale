<?php

namespace App\Model;

use App\Service\JiraDate;

/**
 * Reconstruit la chronologie d'une issue à partir du changelog Jira : temps passé par statut et par
 * catégorie de workflow, temps d'attente, et calcul des jours ouvrés (week-ends + jours fériés exclus).
 */
class Timeline
{
    public const WAIT_LABEL_KEYWORD = 'attente';

    /** Clé de waiting_times représentant le temps passé sous flag Jira (indicateur d'empêchement). */
    public const FLAGGED_WAITING_KEY = 'Ticket flaggé';

    /**
     * Correspondance des statuts natifs Jira (anglais / forme courte) vers leur
     * libellé d'affichage réel dans l'instance. Source unique utilisée par
     * normalizeNativeStatusName() (scalaire) et normalizeNativeStatuses() (tableau).
     *
     * @var array<string, string>
     */
    public const NATIVE_STATUS_MAP = [
        'To Do'       => 'À faire',
        'In Progress' => 'En cours',
        'Done'        => 'Terminé(e)',
        'Terminé'     => 'Terminé(e)',
    ];

    /**
     * Catégories de statuts portées par un workflow (fichier global, Features, ou config d'équipe).
     * Source unique de la liste, consommée par normalizeWorkflowStatuses().
     *
     * @var list<string>
     */
    private const WORKFLOW_STATUS_CATEGORIES = [
        'refinement_statuses',
        'sprint_statuses',
        'done_statuses',
        'waiting_statuses',
    ];

    private Config $config;

    /**
     * Cache d'instance des jours fériés tombant en semaine, en numéros de jour triés.
     *
     * @var list<int>|null
     */
    private ?array $holidayDayNumbers = null;

    /**
     * Injecte la configuration (jours fériés, workflow) ; en crée une par défaut si absente.
     */
    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    /**
     * Détermine dans quelle tranche horaire se trouve une DateTime.
     *
     * Tranches :
     * - 1 : 00h00 → 09h59 (début matinée)
     * - 2 : 10h00 → 12h59 (fin matinée)
     * - 3 : 13h00 → 15h59 (début après-midi)
     * - 4 : 16h00 → 23h59 (fin après-midi)
     *
     * @return int Numéro de tranche (1-4)
     */
    protected function getTimeSlot(\DateTime $dateTime): int
    {
        $hour = (int) $dateTime->format('H');

        if ($hour < 10) {
            return 1;
        } elseif ($hour < 13) {
            return 2;
        } elseif ($hour < 16) {
            return 3;
        } else {
            return 4;
        }
    }

    /**
     * Vérifie si deux DateTime ont des heures similaires (tolérance en minutes).
     *
     * Exemples :
     * - 11h00 vs 11h00 (diff = 0) → true
     * - 10h30 vs 11h00 (diff = 30) → true
     * - 11h00 vs 11h30 (diff = 30) → true
     * - 10h00 vs 11h00 (diff = 60) → false
     *
     * @param int $toleranceMinutes Tolérance en minutes (défaut 30)
     */
    private function isSimilarTime(\DateTime $start, \DateTime $end, int $toleranceMinutes = 30): bool
    {
        $startMinutes = ((int) $start->format('H') * 60) + (int) $start->format('i');
        $endMinutes = ((int) $end->format('H') * 60) + (int) $end->format('i');

        $diff = abs($endMinutes - $startMinutes);

        return $diff <= $toleranceMinutes;
    }

    /**
     * Compte le nombre de jours ouvrés calendaires entre deux dates.
     *
     * Utilisé pour le cas spécial "même heure ±30min".
     * Compte les jours ouvrés du jour après start au jour de end (inclus).
     *
     * Exemple : Lun → Mar retourne 1 (seulement le Mar, car Lun est le jour de départ)
     *
     * @return int Nombre de jours ouvrés
     */
    private function countBusinessDaysBetween(\DateTime $start, \DateTime $end): int
    {
        // Le jour de départ est exclu, d'où le +1 : il est arithmétique, pas un décalage de DateTime
        // (cloner puis modifier un DateTime coûte plus cher que tout le reste du calcul).
        return $this->countBusinessDaysInRange(self::dayNumber($start) + 1, self::dayNumber($end));
    }

    /**
     * Compte les jours ouvrés (ni week-end, ni férié) d'un intervalle, bornes INCLUSES.
     *
     * Prend des numéros de jour absolus et non des DateTime : les bornes se décalent alors par simple
     * addition, là où un `clone` suivi d'un `modify('+1 day')` coûterait à lui seul plus que le calcul.
     *
     * Calcul arithmétique et non itératif : parcourir l'intervalle jour par jour coûtait ~0,011 ms par
     * jour d'amplitude, soit 8 ms pour un ticket vieux de deux ans — et ce calcul est refait à chaque
     * lecture de durée, sur des milliers de tickets. Mesuré sur une fin de PI, c'était le premier poste
     * de consommation CPU de l'application, devant les appels Jira.
     */
    private function countBusinessDaysInRange(int $firstDay, int $lastDay): int
    {
        if ($lastDay < $firstDay) {
            return 0;
        }

        $totalDays = $lastDay - $firstDay + 1;

        // Toute semaine complète apporte exactement 5 jours ouvrés, quel que soit son point de départ.
        $businessDays = intdiv($totalDays, 7) * 5;

        // Reliquat : on n'examine que les jours restants (6 au maximum), pas tout l'intervalle.
        $firstWeekday = self::weekday($firstDay);
        for ($offset = 0; $offset < $totalDays % 7; $offset++) {
            if ((($firstWeekday + $offset - 1) % 7) + 1 <= 5) {
                $businessDays++;
            }
        }

        return $businessDays - $this->countHolidaysInRange($firstDay, $lastDay);
    }

    /**
     * Numéro de jour absolu d'une date (jours écoulés depuis le 01/01/1970), heure ignorée.
     *
     * Algorithme « days from civil » : conversion exacte à partir du triplet (année, mois, jour), sans
     * dépendre du fuseau, de l'heure d'été ni de l'extension calendar. On ne peut pas diviser un
     * timestamp par 86400 : les jours de changement d'heure ne font pas 24 h, ce qui décalerait le
     * résultat et fausserait le comptage des jours ouvrés deux fois par an.
     */
    private static function dayNumber(\DateTime $date): int
    {
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day   = (int) $date->format('j');

        $year -= $month <= 2 ? 1 : 0;
        $era   = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe   = $year - $era * 400;
        $doy   = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe   = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * Jour de la semaine ISO (1 = lundi … 7 = dimanche) d'un numéro de jour absolu.
     *
     * Le jour 0 (01/01/1970) était un jeudi, d'où le décalage de 3.
     */
    private static function weekday(int $dayNumber): int
    {
        return ((($dayNumber + 3) % 7) + 7) % 7 + 1;
    }

    /**
     * Compte les jours fériés tombant en semaine dans un intervalle de numéros de jour, bornes incluses.
     *
     * Recherche dichotomique sur la liste triée : le coût ne dépend plus de l'amplitude de l'intervalle.
     */
    private function countHolidaysInRange(int $firstDay, int $lastDay): int
    {
        $holidays = $this->holidayDayNumbers();

        return self::lowerBound($holidays, $lastDay + 1) - self::lowerBound($holidays, $firstDay);
    }

    /**
     * Index du premier élément supérieur ou égal à $value dans une liste triée.
     *
     * @param list<int> $sorted
     */
    private static function lowerBound(array $sorted, int $value): int
    {
        $low  = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * Numéros de jour des jours fériés tombant en SEMAINE, triés.
     *
     * Les fériés tombant un week-end sont écartés : ils sont déjà exclus par le calcul des semaines,
     * les soustraire une seconde fois retirerait des jours ouvrés qui n'existent pas.
     *
     * Mémoïsé par instance, même motif que Config::$nonWorkingDaysMap : construit une fois, puis réutilisé
     * par tous les calculs de durée de la collection.
     *
     * @return list<int>
     */
    private function holidayDayNumbers(): array
    {
        if ($this->holidayDayNumbers !== null) {
            return $this->holidayDayNumbers;
        }

        $numbers = [];
        foreach ($this->config->getNonWorkingDays() as $frenchDate) {
            $date = \DateTime::createFromFormat('!d/m/Y', $frenchDate);
            if ($date === false) {
                continue;
            }

            $dayNumber = self::dayNumber($date);
            if (self::weekday($dayNumber) >= 6) {
                continue;
            }

            $numbers[] = $dayNumber;
        }

        sort($numbers);

        return $this->holidayDayNumbers = $numbers;
    }

    /**
     * Calcule le nombre de jours par logique de tranches horaires.
     *
     * Chaque jour ouvré est divisé en 4 tranches de 0.25 jour :
     * - Tranche 1 (00h-10h) : 0.25
     * - Tranche 2 (10h-13h) : 0.25
     * - Tranche 3 (13h-16h) : 0.25
     * - Tranche 4 (16h-24h) : 0.25
     */
    private function calculateByTimeSlots(\DateTime $start, \DateTime $end): float
    {
        $startDate = $start->format('d/m/Y');
        $endDate = $end->format('d/m/Y');

        $totalSlots = 0;

        // Cas 1 : Même jour calendaire
        if ($startDate === $endDate) {
            // Vérifier si jour ouvré
            $dayOfWeek = (int) $start->format('N');
            $isWeekend = ($dayOfWeek >= 6);
            $isHoliday = $this->config->isNonWorkingDay($startDate);

            if ($isWeekend || $isHoliday) {
                return 0;
            }

            $startSlot = $this->getTimeSlot($start);
            $endSlot = $this->getTimeSlot($end);

            // Compter les tranches incluses
            $totalSlots = $endSlot - $startSlot + 1;

            return $totalSlots * 0.25;
        }

        // Cas 2 : Jours différents

        // Jour de départ : compter de la tranche de start jusqu'à la tranche 4
        $startDayOfWeek = (int) $start->format('N');
        $isStartWeekend = ($startDayOfWeek >= 6);
        $isStartHoliday = $this->config->isNonWorkingDay($startDate);

        if (!$isStartWeekend && !$isStartHoliday) {
            $startSlot = $this->getTimeSlot($start);
            $totalSlots += (4 - $startSlot + 1); // De startSlot à 4 inclus
        }

        // Jours intermédiaires (complets), bornes exclues : chacun vaut 4 tranches.
        $totalSlots += 4 * $this->countBusinessDaysInRange(
            self::dayNumber($start) + 1,
            self::dayNumber($end) - 1
        );

        // Jour d'arrivée : compter de la tranche 1 jusqu'à la tranche de end
        $endDayOfWeek = (int) $end->format('N');
        $isEndWeekend = ($endDayOfWeek >= 6);
        $isEndHoliday = $this->config->isNonWorkingDay($endDate);

        if (!$isEndWeekend && !$isEndHoliday) {
            $endSlot = $this->getTimeSlot($end);
            $totalSlots += $endSlot; // De 1 à endSlot inclus
        }

        return $totalSlots * 0.25;
    }

    /**
     * Calcule le nombre de jours ouvrés entre deux dates.
     *
     * Logique hybride :
     * 1. Cas spécial : si jours différents + même heure (±30min)
     *    → Retourne le nombre de jours calendaires ouvrés
     *    Exemple : Lun 11h → Mar 11h = 1 jour ouvré (le mardi)
     *
     * 2. Sinon : logique par tranches horaires
     *    → 4 tranches de 0.25 jour par jour ouvré
     *    Exemple : Lun 8h → Lun 15h30 = 0.75 jour (3 tranches)
     *
     * @return float Nombre de jours ouvrés
     */
    public function calculateBusinessDays(\DateTime $start, \DateTime $end): float
    {
        if ($start > $end) {
            return 0;
        }

        // Raisonnement « au jour » en heure de Paris (jour calendaire, week-end, férié) : les dates
        // Jira arrivent en UTC ; sans conversion, une transition entre minuit et ~2h (Paris) serait
        // comptée la veille, et matchée contre les jours fériés français dans le mauvais référentiel.
        // Les instants comparés restent identiques — seule la frontière de journée change.
        $tz    = new \DateTimeZone(JiraDate::APP_TIMEZONE);
        $start = (clone $start)->setTimezone($tz);
        $end   = (clone $end)->setTimezone($tz);

        $startDate = $start->format('d/m/Y');
        $endDate = $end->format('d/m/Y');

        // Statut/label changé moins d'une heure après (fausse manip ou temps anecdotique) : on
        // considère que le ticket n'est pas resté dans ce statut.
        if ($startDate === $endDate && $this->isSimilarTime($start, $end, 60)) {
            return 0;
        }

        // Cas spécial : jours différents + même heure (tolérance ±30min)
        if ($startDate !== $endDate && $this->isSimilarTime($start, $end, 30)) {
            return $this->countBusinessDaysBetween($start, $end);
        }

        // Logique par tranches
        return $this->calculateByTimeSlots($start, $end);
    }

    /**
     * Ordonnance les statuts Jira suivant le workflow configuré.
     *
     * @param array  $timelineByStatus   Tableau brut [statusName => jours]
     * @param bool   $splitOtherStatuses Séparer workflowStatuses / otherStatuses
     * @param string $issueType          Type Jira (ex: 'Feature') pour sélectionner le bon workflow
     */
    public function getSortedTimelineByStatus(array $timelineByStatus, bool $splitOtherStatuses = true, string $issueType = ''): array
    {
        $orderedStatuses = $this->config->getWorkflowStatusOrder($issueType);

        // Statuts connus d'abord
        $sortedTimeline = [];
        foreach ($orderedStatuses as $status) {
            if (isset($timelineByStatus[$status]) && $timelineByStatus[$status] > 0) {
                $sortedTimeline[$status] = $timelineByStatus[$status];
                unset($timelineByStatus[$status]);
            }
        }

        // Statuts inconnus rejetés en fin de liste
        $otherStatuses = [];
        if (!empty($timelineByStatus)) {
            foreach ($timelineByStatus as $status => $days) {
                if ($days <= 0) {
                    continue;
                }
                $otherStatuses[$status] = $days;
            }
        }

        $sortedTimeline = $this->normalizeNativeStatuses($sortedTimeline);
        $otherStatuses = $this->normalizeNativeStatuses($otherStatuses);

        return $splitOtherStatuses
            ? ['workflowStatuses' => $sortedTimeline, 'otherStatuses' => $otherStatuses]
            : array_merge($sortedTimeline, $otherStatuses);
    }

    /**
     * Fusionne les statuts natifs Jira avec leurs traductions, pour rester cohérent avec leur
     * affichage réel dans Jira.
     */
    public function normalizeNativeStatuses(array $statuses): array
    {
        foreach (self::NATIVE_STATUS_MAP as $from => $to) {
            if (array_key_exists($from, $statuses)) {
                $statuses[$to] = ($statuses[$to] ?? 0) + $statuses[$from];
                unset($statuses[$from]);
            }
        }

        return $statuses;
    }

    /**
     * Normalise un NOM de statut natif Jira vers son libellé d'affichage réel
     * (ex: « Done » → « Terminé(e) »). Version scalaire de normalizeNativeStatuses(),
     * réutilisable partout où l'on manipule un statut isolé (ex: statut reconstruit
     * à une date passée depuis le changelog).
     *
     * @return string Libellé normalisé, ou le nom d'origine si non concerné
     */
    public function normalizeNativeStatusName(string $status): string
    {
        return self::NATIVE_STATUS_MAP[$status] ?? $status;
    }

    /**
     * Normalise les listes de statuts d'un workflow : chaque libellé natif Jira est converti en son
     * libellé d'affichage (cf. NATIVE_STATUS_MAP).
     *
     * Le workflow configuré est exprimé en libellés d'affichage alors que le changelog Jira fournit
     * les libellés natifs bruts : sans cette normalisation des DEUX côtés, les comparaisons échouent
     * (doneDate / firstInProgressDate jamais détectées → cycle time et lead time à 0).
     *
     * Les clés absentes du workflow deviennent des listes vides ; les autres clés sont préservées.
     *
     * @param  array<string, list<string>> $workflow Workflow brut issu de la configuration
     * @return array<string, list<string>>           Même structure, listes de statuts normalisées
     */
    private function normalizeWorkflowStatuses(array $workflow): array
    {
        foreach (self::WORKFLOW_STATUS_CATEGORIES as $category) {
            $workflow[$category] = array_map(
                fn ($status): string => $this->normalizeNativeStatusName((string) $status),
                $workflow[$category] ?? []
            );
        }

        return $workflow;
    }

    /**
     * Extrait du changelog d'une issue la liste chronologique des transitions d'un champ donné.
     *
     * Factorise le motif « double boucle » (histories → items) des reconstructions à une date de Sprint
     * (statut, story points, labels, champ Sprint) : filtre sur le nom du champ (`field`, défaut) ou son
     * identifiant (`fieldId` pour les customfields), ignore les entrées sans date de changement, et joint
     * à chaque transition retenue sa date.
     *
     * @param  Issue  $issue    Issue dont on lit le changelog (trié du plus ancien au plus récent)
     * @param  string $field    Valeur à filtrer (ex: 'status', 'Sprint', 'labels', ou un id customfield)
     * @param  string $matchKey Clé de filtrage dans l'item : 'field' (défaut) ou 'fieldId'
     * @return array<int, array{date: \DateTime, item: array<string, string|null>}>
     */
    public function getChangeHistory(Issue $issue, string $field, string $matchKey = 'field'): array
    {
        $changes = [];

        foreach ($issue->getHistory() as $historyItem) {
            if (empty($historyItem['created'])) {
                continue;
            }

            foreach ($historyItem['items'] ?? [] as $item) {
                if (($item[$matchKey] ?? '') !== $field) {
                    continue;
                }

                $changes[] = ['date' => new \DateTime($historyItem['created']), 'item' => $item];
            }
        }

        return $changes;
    }

    /**
     * Reconstruit la timeline de l'issue à partir du changelog Jira
     * et calcule le temps cumulé par statut et par catégorie de statut.
     *
     * Cette méthode est appelée une seule fois à l'initialisation
     * de l'objet Issue.
     *
     * @todo V2 Backend : god-method (timeline + doneDate + waiting + agrégats mêlés) — éclater en V2.
     * Dégel par lot V2 uniquement (cf. CLAUDE.md).
     *
     * @return void
     */
    public function buildStatusTimeline(Issue $issue)
    {
        $history = $issue->getHistory();
        if (empty($history)) {
            return;
        }

        // Récupère le workflow Jira depuis jira_workflow_issues.json
        $workflow = $this->config->getJiraWorkflow($issue->getIssueTypeName());
        $workflowTimeBreakdown = $issue->getWorkflowTimeBreakdown();

        // Tout le replay du changelog opère en libellés NORMALISÉS (To Do → À faire,
        // In Progress → En cours, Done/Terminé → Terminé(e)) : le changelog Jira fournit les
        // libellés natifs bruts, alors que le workflow configuré est exprimé en libellés
        // d'affichage. On normalise les DEUX côtés pour qu'ils concordent (sinon doneDate /
        // firstInProgressDate ne sont jamais détectées → cycle time & lead time à 0).
        $norm     = fn (string $s): string => $this->normalizeNativeStatusName($s);
        $workflow = $this->normalizeWorkflowStatuses($workflow);

        // 1er statut sprint = entrée « non démarré » (le plus haut dans l'IHM workflow) ;
        // toute entrée dans un AUTRE statut sprint date la prise en charge (firstInProgressDate).
        $notStartedStatus = $workflow['sprint_statuses'][0] ?? null;

        // Statut initial
        $currentStatus = $norm($this->getInitialStatus($issue, $history));
        $currentStatusCreatedDate = $issue->getCreatedDate(false);

        foreach ($history as $historyItem) {
            foreach ($historyItem['items'] as $status) {

                // Définit la première date d'ajout à un sprint
                if ($status['field'] === 'Sprint' && (!$issue->getFirstAddToSprintDate() && $historyItem['created'])) {
                    $issue->setFirstAddToSprintDate(
                        new \DateTime($historyItem['created'])
                    );
                    $issue->setFirstSprint($status['toString']);
                    continue;
                }

                // Ignore les éléments d'historique ne concernant pas les status
                if ($status['field'] !== 'status') {
                    continue;
                }

                //Date du changement de status
                if (empty($historyItem['created'])) {
                    throw new \RuntimeException('Transition date cannot be null');
                }
                $transitionDate = new \DateTime($historyItem['created']);
                $daysInStatus = $this->calculateBusinessDays($currentStatusCreatedDate, $transitionDate);

                // Libellés de transition normalisés (cf. $norm ci-dessus), comparés au workflow.
                $toStatus   = $norm($status['toString']);
                $fromStatus = $norm($status['fromString'] ?? '');

                /**
                 *** Agrégation du temps par statut et catégorie de statut ***
                 */
                if (!isset($timeByStatus[$currentStatus])) {
                    //Si le statut n'a jamais été rencontré, on l'ajoute au tableau
                    $timeByStatus[$currentStatus] = 0;
                } else {
                    //Sinon on incremente le nombre de retours à ce statut
                    $issue->addRevertCount($currentStatus);
                }
                $timeByStatus[$currentStatus] += $daysInStatus;

                // Mise à jour du temps par catégorie de statut
                $this->updateWorkflowTimeBreakdown($currentStatus, $daysInStatus, $workflow, $workflowTimeBreakdown);

                // Si le statut est catégorisé « waiting_statuses » dans le workflow, on l'ajoute
                // _en plus_ à la catégorie « waiting » et à ses waiting_times.
                if (in_array($currentStatus, $workflow['waiting_statuses'], true)) {
                    $workflowTimeBreakdown['waiting'] += $daysInStatus;
                    $issue->addWaitingTime($currentStatus, $daysInStatus);
                }

                /**
                 * Met à jour les firstInProgressDate et doneDate
                 */
                // Prise en charge = 1er passage dans un statut sprint AUTRE que le statut de
                // tête (« non démarré »).
                if (
                    $issue->getFirstInProgressDate(false) === null
                    && $toStatus !== $notStartedStatus
                    && in_array($toStatus, $workflow['sprint_statuses'], true)
                ) {
                    $issue->setFirstInProgressDate($transitionDate);
                }
                // Met à jour doneDate uniquement si on entre dans done depuis un statut non-done.
                // Cas ignoré : transition done→done (ex. "Done" → "Terminé") qui ne change pas
                // la date réelle de complétion mais écraserait la bonne valeur avec une date tardive.
                // Cas préservé : non-done→done après réouverture (aller-retour légitime) → doneDate mise à jour.
                if (
                    in_array($toStatus, $workflow['done_statuses'], true) &&
                    !in_array($fromStatus, $workflow['done_statuses'], true)
                ) {
                    $issue->setDoneDate($transitionDate);
                }

                // Mise à jour du statut courant pour la prochaine boucle
                $currentStatus = $toStatus;
                $currentStatusCreatedDate = $transitionDate;
            }
        }

        /**
         * On traite le statut actuel (jusqu'à date du Done, ou date du jour si ticket pas encore résolu)
         */
        $endDate = $issue->getResolutionDateTime();
        $daysInStatus = $this->calculateBusinessDays($currentStatusCreatedDate, $endDate);

        if (!isset($timeByStatus[$currentStatus])) {
            $timeByStatus[$currentStatus] = 0;
        }
        $timeByStatus[$currentStatus] += $daysInStatus;

        $this->updateWorkflowTimeBreakdown($currentStatus, $daysInStatus, $workflow, $workflowTimeBreakdown);

        // Suppression des statuts Done
        foreach ($workflow['done_statuses'] as $doneStatus) {
            unset($timeByStatus[$doneStatus]);
        }

        // Définit le résultat
        $issue->setTimeByStatus($timeByStatus);
        $issue->setWorkflowTimeBreakdown($workflowTimeBreakdown);
    }

    /**
     * Retourne le statut initial d'une issue à partir du premier changement
     * de statut trouvé dans l'historique.
     *
     * Si aucun changement de statut n'existe, fallback sur le statut courant
     * de l'issue.
     */
    private function getInitialStatus(Issue $issue, array $history): string
    {
        foreach ($history as $historyItem) {
            foreach ($historyItem['items'] as $item) {
                if (($item['field'] ?? '') === 'status') {
                    return $item['fromString'] ?? $issue->getStatusName();
                }
            }
        }

        return $issue->getStatusName();
    }

    /**
     * Ajoute le temps passé dans un statut à la bonne catégorie de workflow (refinement, sprint ou
     * other) ; les statuts « done » sont ignorés. L'accumulateur $workflowTimeBreakdown est modifié
     * par référence.
     *
     * @param array<string, string[]> $workflow               Workflow issu de jira_workflow_issues.json
     * @param array<string, float>    $workflowTimeBreakdown  Accumulateur catégorie => jours ouvrés
     */
    public function updateWorkflowTimeBreakdown(string $currentStatus, float $daysInStatus, array $workflow, array &$workflowTimeBreakdown): void
    {
        switch ($currentStatus) {
            case in_array($currentStatus, $workflow['refinement_statuses'], true):
                $workflowTimeBreakdown[StatusCategory::REFINEMENT] += $daysInStatus;
                break;
            case in_array($currentStatus, $workflow['sprint_statuses'], true):
                $workflowTimeBreakdown[StatusCategory::SPRINT] += $daysInStatus;
                break;
            case in_array($currentStatus, $workflow['done_statuses'], true):
                //Ignore Done statuses
                break;
            default:
                $workflowTimeBreakdown[StatusCategory::OTHER] += $daysInStatus;
                break;
        }
    }

    /**
     * Calcule le temps passé en attente pour une Issue via son changelog, indiquant un blocage temporaire.
     *
     * Alimente DEUX résultats sur l'issue, dans une même passe de changelog :
     * 1. La VENTILATION par cause (waiting_times, cf. setWaitingTimes) — additive :
     *    - Statuts d'attente : déjà posés par buildStatusTimeline()
     *    - Labels « attente » (mot-clé WAIT_LABEL_KEYWORD)
     *    - Flag Jira (customfield « Flagged », valeur « Impediment ») → clé FLAGGED_WAITING_KEY
     * 2. Le TOTAL dédupliqué (cf. setWaitingTimeTotal) = UNION des périodes d'attente
     *    (statuts + labels + flag) : un jour recouvert par plusieurs causes n'est compté
     *    qu'une seule fois (cf. mergeWaitingIntervals).
     *
     * Pour chaque cause, l'intervalle [entrée, sortie] est collecté (sortie = date de
     * résolution si la cause est encore active en fin d'historique). Les intervalles de
     * statut d'attente sont re-dérivés ici à partir du changelog, car buildStatusTimeline
     * n'en conserve que les durées cumulées, pas les bornes.
     *
     * FENÊTRE OPTIONNELLE (page Sprint) : bornée, chaque intervalle est rogné sur [windowStart,
     * windowEnd] avant tout calcul — une attente débutée avant le sprint, ou courant après sa
     * clôture, ne pèse que pour sa part réellement écoulée PENDANT le sprint.
     * La ventilation est alors intégralement recalculée ici, statuts compris : celle produite par
     * buildStatusTimeline porte sur la vie entière du ticket et ne peut pas être restreinte.
     * Sans fenêtre (VSM Release/Feature/PI), le comportement est strictement inchangé : la part
     * « statuts » reste celle de buildStatusTimeline, complétée par labels et flag.
     *
     * @param Issue          $issue
     * @param \DateTime|null $windowStart Borne basse (début du sprint), null = depuis toujours
     * @param \DateTime|null $windowEnd   Borne haute (date de référence du sprint), null = jusqu'à la fin
     */
    public function buildWaitingTimes(Issue $issue, ?\DateTime $windowStart = null, ?\DateTime $windowEnd = null): void
    {
        $history = $issue->getHistory();
        if (empty($history)) {
            return;
        }

        // Borne haute des intervalles encore ouverts en fin d'historique (résolution/clôture
        // ou aujourd'hui). Le garde-fou instanceof fige le type \DateTime pour PHPStan.
        $endDate = $issue->getResolutionDateTime();
        if (!$endDate instanceof \DateTime) {
            $endDate = new \DateTime();
        }

        /** @var array<string, \DateTime> $activeWaitingLabels label => date d'ajout */
        $activeWaitingLabels = [];

        // Intervalles collectés PAR CAUSE : les durées n'en sont dérivées qu'en fin de méthode, une
        // fois le rognage éventuel appliqué (sans bornes, la somme est identique à un cumul direct).
        /** @var array<string, array<int, array{0: \DateTime, 1: \DateTime}>> $labelIntervals */
        $labelIntervals = [];
        /** @var array<string, array<int, array{0: \DateTime, 1: \DateTime}>> $statusIntervals */
        $statusIntervals = [];
        /** @var array<int, array{0: \DateTime, 1: \DateTime}> $flagIntervals */
        $flagIntervals = [];

        // Flag Jira (customfield « Flagged ») : date de pose du flag actif, null si non flaggé.
        $flagStart = null;

        // Suivi des statuts pour re-dériver les intervalles passés en statut d'attente,
        // en réutilisant les mêmes briques que buildStatusTimeline (cohérence garantie).
        $workflow        = $this->normalizeWorkflowStatuses($this->config->getJiraWorkflow($issue->getIssueTypeName()));
        $waitingStatuses = $workflow['waiting_statuses'];
        $currentStatus      = $this->normalizeNativeStatusName($this->getInitialStatus($issue, $history));
        $createdDate        = $issue->getCreatedDate(false);
        $currentStatusStart = $createdDate instanceof \DateTime ? $createdDate : $endDate;

        foreach ($history as $historyItem) {
            $transitionDate = new \DateTime($historyItem['created']);

            foreach ($historyItem['items'] as $item) {
                // Flag Jira : pose (toString = « Impediment ») / retrait (toString vide) → temps d'attente.
                if (($item['fieldId'] ?? '') === JiraFields::FLAGGED) {
                    if (!empty($item['toString'])) {
                        $flagStart = $transitionDate;
                    } elseif ($flagStart !== null) {
                        $flagIntervals[] = [$flagStart, $transitionDate];
                        $flagStart = null;
                    }
                    continue;
                }

                // Statut : clôt l'intervalle courant s'il s'agissait d'un statut d'attente.
                if ($item['field'] === 'status') {
                    if (in_array($currentStatus, $waitingStatuses, true)) {
                        $statusIntervals[$currentStatus][] = [$currentStatusStart, $transitionDate];
                    }
                    $currentStatus      = $this->normalizeNativeStatusName((string) ($item['toString'] ?? ''));
                    $currentStatusStart = $transitionDate;
                    continue;
                }

                // Ignore les éléments d'historique ne concernant pas les étiquettes (champ "labels")
                if ($item['field'] !== 'labels') {
                    continue;
                }

                $previousLabels = $this->splitLabels($item['fromString'] ?? '');
                $newLabels      = $this->splitLabels($item['toString'] ?? '');

                // Labels ajoutés lors de cette transition
                foreach (array_diff($newLabels, $previousLabels) as $label) {
                    if ($this->isWaitingLabel($label)) {
                        $activeWaitingLabels[$label] = $transitionDate;
                    }
                }

                // Labels retirés lors de cette transition → on clôt leur intervalle
                foreach (array_diff($previousLabels, $newLabels) as $label) {
                    if ($this->isWaitingLabel($label) && isset($activeWaitingLabels[$label])) {
                        $labelIntervals[$label][] = [$activeWaitingLabels[$label], $transitionDate];
                        unset($activeWaitingLabels[$label]);
                    }
                }
            }
        }

        // Labels encore actifs (jamais retirés) → jusqu'à la date de résolution
        foreach ($activeWaitingLabels as $label => $addedDate) {
            $labelIntervals[$label][] = [$addedDate, $endDate];
        }

        // Flag encore posé (jamais retiré) → jusqu'à la date de résolution
        if ($flagStart !== null) {
            $flagIntervals[] = [$flagStart, $endDate];
        }

        // NB : le statut courant encore « en attente » en fin d'historique n'est
        // VOLONTAIREMENT pas compté ici. buildStatusTimeline (god-method, source de la
        // ventilation) ne l'ajoute pas non plus à waiting_times pour son segment final ;
        // l'inclure gonflerait le total au-delà de la somme des causes affichées.
        // (Labels/flag encore actifs, eux, SONT dans la ventilation → déjà comptés ci-dessus.)

        $bounded = $windowStart !== null || $windowEnd !== null;

        /** @var array<string, float> $labelWaitingTimes */
        $labelWaitingTimes = [];
        foreach ($labelIntervals as $label => $intervals) {
            $days = $this->sumIntervalDays($intervals, $windowStart, $windowEnd);
            // Hors fenêtre, une cause à 0 jour reste listée (comportement historique) ; bornée, une
            // cause entièrement hors période n'est PAS une cause d'attente du sprint observé.
            if (!$bounded || $days > 0) {
                $labelWaitingTimes[$label] = ($labelWaitingTimes[$label] ?? 0.0) + $days;
            }
        }

        $flaggedDays = $this->sumIntervalDays($flagIntervals, $windowStart, $windowEnd);
        if ($flaggedDays > 0) {
            $labelWaitingTimes[self::FLAGGED_WAITING_KEY] =
                ($labelWaitingTimes[self::FLAGGED_WAITING_KEY] ?? 0.0) + $flaggedDays;
        }

        // 1. Ventilation par cause.
        if ($bounded) {
            // Recalculée de bout en bout : la part « statuts » de buildStatusTimeline couvre la vie
            // entière du ticket, elle est donc remplacée par sa version rognée sur la fenêtre.
            $merged = $labelWaitingTimes;
            foreach ($statusIntervals as $status => $intervals) {
                $days = $this->sumIntervalDays($intervals, $windowStart, $windowEnd);
                if ($days > 0) {
                    $merged[$status] = ($merged[$status] ?? 0.0) + $days;
                }
            }
        } else {
            // Statuts déjà posés par buildStatusTimeline : on ne fait que compléter.
            $merged = $issue->getWaitingTimes();
            foreach ($labelWaitingTimes as $label => $days) {
                $merged[$label] = ($merged[$label] ?? 0.0) + $days;
            }
        }
        $issue->setWaitingTimes($merged);

        // 2. Total dédupliqué : union des intervalles (un jour compté une seule fois).
        $allIntervals = $flagIntervals;
        foreach ([...array_values($labelIntervals), ...array_values($statusIntervals)] as $intervals) {
            array_push($allIntervals, ...$intervals);
        }
        $issue->setWaitingTimeTotal(
            $this->mergeWaitingIntervals($this->clipIntervals($allIntervals, $windowStart, $windowEnd))
        );
    }

    /**
     * Restreint des intervalles à la fenêtre [from, to] : chaque borne est ramenée dans la fenêtre, et
     * tout intervalle qui n'en recoupe rien est écarté.
     *
     * Sans fenêtre, la liste est renvoyée telle quelle — garantie que le calcul non borné (VSM
     * Release/Feature/PI) reste rigoureusement identique.
     *
     * @param  array<int, array{0: \DateTime, 1: \DateTime}> $intervals
     * @return array<int, array{0: \DateTime, 1: \DateTime}>
     */
    private function clipIntervals(array $intervals, ?\DateTime $from, ?\DateTime $to): array
    {
        if ($from === null && $to === null) {
            return $intervals;
        }

        $clipped = [];
        foreach ($intervals as [$start, $end]) {
            if ($from !== null && $start < $from) {
                $start = clone $from;
            }
            if ($to !== null && $end > $to) {
                $end = clone $to;
            }
            if ($start < $end) {
                $clipped[] = [$start, $end];
            }
        }

        return $clipped;
    }

    /**
     * Jours ouvrés cumulés d'une liste d'intervalles d'une MÊME cause, éventuellement rognés sur une
     * fenêtre. Les intervalles d'une même cause ne se chevauchant pas, la somme directe suffit ici —
     * la déduplication INTER-causes relève de {@see mergeWaitingIntervals()}.
     *
     * @param array<int, array{0: \DateTime, 1: \DateTime}> $intervals
     */
    private function sumIntervalDays(array $intervals, ?\DateTime $from, ?\DateTime $to): float
    {
        $total = 0.0;
        foreach ($this->clipIntervals($intervals, $from, $to) as [$start, $end]) {
            $total += $this->calculateBusinessDays($start, $end);
        }

        return $total;
    }

    /**
     * Total de jours ouvrés couvert par une liste d'intervalles d'attente, en fusionnant
     * les périodes qui se chevauchent : chaque jour n'est compté qu'une fois même si
     * plusieurs causes (statut, label, flag) le recouvrent (déduplication).
     *
     * @param array<int, array{0: \DateTime, 1: \DateTime}> $intervals Paires [début, fin]
     */
    private function mergeWaitingIntervals(array $intervals): float
    {
        if ($intervals === []) {
            return 0.0;
        }

        // Tri chronologique par date de début pour fusionner en une seule passe.
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total        = 0.0;
        $currentStart = $intervals[0][0];
        $currentEnd   = $intervals[0][1];

        foreach (array_slice($intervals, 1) as $interval) {
            [$start, $end] = $interval;
            if ($start <= $currentEnd) {
                // Chevauchement (ou contiguïté) → on étend la période fusionnée courante.
                if ($end > $currentEnd) {
                    $currentEnd = $end;
                }
            } else {
                // Période disjointe → on clôt la période fusionnée et on en démarre une nouvelle.
                $total += $this->calculateBusinessDays($currentStart, $currentEnd);
                $currentStart = $start;
                $currentEnd   = $end;
            }
        }
        $total += $this->calculateBusinessDays($currentStart, $currentEnd);

        return $total;
    }

    /**
     * Découpe une chaîne de labels Jira (séparés par des espaces) en tableau normalisé.
     *
     * @param string $labelsString Chaîne brute issue du changelog Jira (fromString / toString)
     * @return string[]
     */
    private function splitLabels(string $labelsString): array
    {
        if (empty($labelsString)) {
            return [];
        }
        return array_values(array_filter(explode(' ', strtolower($labelsString))));
    }

    /**
     * Indique si un label est un label « attente ».
     */
    private function isWaitingLabel(string $label): bool
    {
        return str_contains($label, self::WAIT_LABEL_KEYWORD);
    }
}
