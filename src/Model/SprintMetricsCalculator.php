<?php

declare(strict_types=1);

namespace App\Model;

use App\Dto\SprintTicketDto;

/**
 * Calculateur des statistiques du sprint — collaborateur extrait de Sprint (lot A2 du chantier V2).
 *
 * Produit, à partir de la liste des tickets (état à la date de référence) :
 * - buildStats()          : totaux SP / « Reste à faire », fourchette, non-estimés, changement de
 *   périmètre (planifié → à date), résumés ajoutés/retirés/« nouvelle_prio »/done/reliquat ;
 * - buildPredictability() : prédictibilité (SP) et engagement (sujets) vs la cible commune.
 *
 * Sans état : reçoit les tickets, retourne les tableaux prêts pour la vue et le snapshot JSON.
 */
final class SprintMetricsCalculator
{
    /**
     * Calcule les statistiques du sprint :
     * tickets sans SP, fourchette SP, total SP, total « Reste à faire »,
     * évolution du périmètre (planifié au démarrage → à date), changement de
     * périmètre (+/-), « nouvelle_prio », amélioration continue (cf. continuousImprovementTickets()), et reliquat.
     *
     * @param  list<SprintTicketDto> $tickets           Tickets du sprint (état à la date de référence)
     * @param  list<SprintTicketDto> $additionalRemoved Tickets retirés supplémentaires, détectés hors changelog
     *                                                   (cf. Sprint::fetchPuntedTickets()) — n'alimentent QUE le
     *                                                   bucket removedDuringSprint, pas les totaux SP globaux ni
     *                                                   les autres buckets (added/done/reliquat) : un ticket ponté
     *                                                   a quitté le périmètre du sprint.
     * @return array<string, mixed>
     */
    public function buildStats(array $tickets, array $additionalRemoved = []): array
    {
        $totalStoryPoints       = 0.0;
        $totalRemaining         = 0.0; // Total « Reste à faire » : somme des seules valeurs renseignées du champ dédié
        $unestimatedAtDate      = 0;   // Tickets sans estimation (champ vide) à la date de référence
        $estimatedValues        = []; // SP strictement positifs (pour la fourchette)
        $plannedStoryPoints     = 0.0; // SP des tickets présents au démarrage du sprint
        $plannedTicketCount     = 0;   // Nb de tickets présents au démarrage du sprint

        foreach ($tickets as $ticket) {
            $sp    = $ticket->storyPoints;
            $spVal = $sp ?? 0.0;
            $totalStoryPoints += $spVal;

            // « Reste à faire » du ticket : uniquement les valeurs renseignées (0 compris) du
            // champ dédié. Un ticket sans valeur ne contribue pas — pas de repli sur les Story
            // Points (cohérent avec summarize()).
            $totalRemaining += $ticket->spRemaining ?? 0.0;

            // Tickets « planifiés » = présents avant la date de démarrage
            // (= non ajoutés en cours de sprint).
            if (!$ticket->addedDuringSprint) {
                $plannedStoryPoints += $spVal;
                $plannedTicketCount++;
            }

            // Fourchette : on ne retient que les SP strictement positifs.
            if ($sp !== null && $sp > 0) {
                $estimatedValues[] = $sp;
            }

            // #1 — Non estimé « à date » : champ vide (null) à la date de référence.
            // $sp est déjà la valeur à date (cf. buildTickets). 0 n'est PAS compté
            // ici car c'est une estimation valide.
            if ($sp === null) {
                $unestimatedAtDate++;
            }
        }

        $added            = $this->filterTickets($tickets, static fn (SprintTicketDto $t): bool => $t->addedDuringSprint);
        $removed          = array_merge(
            $this->filterTickets($tickets, static fn (SprintTicketDto $t): bool => $t->removedDuringSprint),
            $additionalRemoved
        );
        $estimatedAfterAdd = $this->filterTickets($tickets, static fn (SprintTicketDto $t): bool => $t->estimatedAfterAdd);

        $done = $this->filterTickets($tickets, static fn (SprintTicketDto $t): bool => $t->isDone);
        usort($done, static function (SprintTicketDto $a, SprintTicketDto $b): int {
            $aKey = $a->parentKey ?? '';
            $bKey = $b->parentKey ?? '';
            if ($aKey === '' && $bKey === '') {
                return strnatcmp($a->key, $b->key);
            }
            if ($aKey === '') {
                return 1;
            }
            if ($bKey === '') {
                return -1;
            }
            $cmp = strnatcmp($aKey, $bKey);
            return $cmp !== 0 ? $cmp : strnatcmp($a->key, $b->key);
        });

        // « nouvelle_prio » = étiquette nouvelle_prio ET ajouté pendant ce sprint.
        // Un ticket reconduit depuis le sprint précédent (non terminé, embarqué
        // dès le démarrage) n'est pas une nouvelle priorisation de CE sprint :
        // cette règle s'applique partout sur la page (badges, section dédiée, %).
        $newPrio = $this->filterTickets(
            $tickets,
            static fn (SprintTicketDto $t): bool => $t->addedDuringSprint
                && in_array(Sprint::LABEL_NEW_PRIORITY, $t->labels, true)
        );
        $reliquat = $this->filterTickets($tickets, static fn (SprintTicketDto $t): bool => !$t->isDone);

        usort($reliquat, static function (SprintTicketDto $a, SprintTicketDto $b): int {
            $aKey = $a->parentKey ?? '';
            $bKey = $b->parentKey ?? '';
            if ($aKey === '' && $bKey === '') {
                return 0;
            }
            if ($aKey === '') {
                return 1;
            }
            if ($bKey === '') {
                return -1;
            }
            return strnatcmp($aKey, $bKey);
        });

        $addedSummary   = $this->summarize($added);
        $newPrioSummary = $this->summarize($newPrio);

        // Évolution du périmètre en Story Points, exprimée en % du planifié au
        // démarrage — sert à dimensionner la « bande passante » à réserver lors
        // de la planification. Ex : 10 → 14 pts = +40%.
        // Détail : part due à l'ensemble des sujets ajoutés, et part due aux
        // seuls sujets « nouvelle_prio » (déjà filtrés : ajoutés ce sprint).
        // Null si aucun point planifié (division impossible).
        $storyPointsDelta     = $totalStoryPoints - $plannedStoryPoints;
        $growthPercent        = $this->percentOf($storyPointsDelta, $plannedStoryPoints);
        $addedPercent         = $this->percentOf($addedSummary['storyPoints'], $plannedStoryPoints);
        $newPrioPercent       = $this->percentOf($newPrioSummary['storyPoints'], $plannedStoryPoints);
        // Part des « nouvelle_prio » rapportée au TOTAL de points à date/clôture
        // (et non au planifié) : indique le poids de ces sujets dans le périmètre
        // final. Ex : 9 / 32.5 = 27.7 %.
        $newPrioTotalPercent  = $this->percentOf($newPrioSummary['storyPoints'], $totalStoryPoints);
        $newPrioIssuesPercent = $this->percentOf($newPrioSummary['count'], $plannedTicketCount);
        // Part des « nouvelle_prio » (en nombre de tickets) rapportée au TOTAL de
        // tickets à date/clôture (et non au planifié) : poids de ces sujets dans
        // le périmètre final en effectif.
        $newPrioTotalIssuesPercent = $this->percentOf($newPrioSummary['count'], (float) count($tickets));

        return [
            'issuesWithoutStoryPoints' => $unestimatedAtDate,
            'issuesWithoutStoryPointsByType' => $this->issuesWithoutStoryPointsByType($tickets),
            'estimatedAfterAdd'        => $this->summarize($estimatedAfterAdd),
            'totalStoryPoints'         => $totalStoryPoints,
            'totalRemainingStoryPoints' => $totalRemaining,
            'storyPointsRange'         => [
                'min'     => $estimatedValues ? min($estimatedValues) : 0.0,
                'max'     => $estimatedValues ? max($estimatedValues) : 0.0,
                'median'  => $estimatedValues ? AbstractIssueCollection::calculateMedian($estimatedValues) : 0.0,
                'average' => $estimatedValues
                    ? round(array_sum($estimatedValues) / count($estimatedValues), 2)
                    : 0.0,
            ],
            'scopeChange'              => [
                'plannedTicketCount'             => $plannedTicketCount,
                'currentTicketCount'             => count($tickets),
                'ticketDelta'                    => count($tickets) - $plannedTicketCount,
                // % d'augmentation du nombre de tickets par rapport au planifié.
                // Null si aucun ticket planifié (division impossible).
                'ticketGrowthPercent'            => $this->percentOf(
                    (float) (count($tickets) - $plannedTicketCount),
                    (float) $plannedTicketCount
                ),
                'plannedStoryPoints' => $plannedStoryPoints,
                'currentStoryPoints' => $totalStoryPoints,
                'storyPointsDelta'   => $storyPointsDelta,
                // % d'augmentation du périmètre (points) par rapport au planifié.
                'storyPointsGrowthPercent' => $growthPercent,
                // Détail : part des sujets ajoutés / des sujets « nouvelle_prio ».
                'addedStoryPointsPercent'        => $addedPercent,
                'newPrioStoryPointsPercent'      => $newPrioPercent,
                'newPrioTotalStoryPointsPercent' => $newPrioTotalPercent,
                'newPrioIssuesPercent'           => $newPrioIssuesPercent,
                'newPrioTotalIssuesPercent'      => $newPrioTotalIssuesPercent
            ],
            'addedDuringSprint'     => $addedSummary,
            'removedDuringSprint'   => $this->summarize($removed),
            'newPrio'               => $newPrioSummary,
            // Forme array : $stats part tel quel vers la vue et le snapshot JSON.
            'continuousImprovement' => array_map(
                static fn (SprintTicketDto $t): array => $t->toArray(),
                $this->continuousImprovementTickets($tickets)
            ),
            'doneTickets'           => $this->summarize($done),
            'reliquat'              => $this->summarize($reliquat),
        ];
    }

    /**
     * Calcule la prédictibilité et l'engagement du sprint.
     *
     * - SP au démarrage         = SP des tickets présents avant la date de démarrage
     *                             (= tickets non ajoutés en cours de sprint).
     * - SP terminés             = SP de tous les tickets en statut « done ».
     * - Prédictibilité (en SP)  = SP terminés / SP au démarrage.
     * - Engagement (en sujets)  = sujets du périmètre initial terminés
     *                             / sujets au démarrage.
     *   On ignore volontairement les sujets ajoutés en cours de sprint
     *   pour ne mesurer que le respect du périmètre planifié.
     * - Cible commune           = Sprint::PREDICTABILITY_TARGET_PERCENT %.
     *
     * @param  list<SprintTicketDto> $tickets Tickets du sprint (état à la date de référence)
     * @return array{startStoryPoints: float, completedStoryPoints: float, startTicketCount: int,
     *     completedTicketCount: int, completedFromStartTicketCount: int, targetPercent: int,
     *     targetStoryPoints: float, achievedPercent: float, isReached: bool,
     *     engagementPercent: float, isEngagementReached: bool}
     */
    public function buildPredictability(array $tickets): array
    {
        $startStoryPoints              = 0.0;
        $completedStoryPoints          = 0.0;
        $startTicketCount              = 0;
        $completedTicketCount          = 0;
        $completedFromStartTicketCount = 0; // sujets du périmètre initial terminés

        foreach ($tickets as $ticket) {
            $sp = $ticket->storyPoints ?? 0.0;

            if (!$ticket->addedDuringSprint) {
                $startStoryPoints += $sp;
                $startTicketCount++;
                if ($ticket->isDone) {
                    $completedFromStartTicketCount++;
                }
            }
            if ($ticket->isDone) {
                $completedStoryPoints += $sp;
                $completedTicketCount++;
            }
        }

        $achievedPercent = $startStoryPoints > 0
            ? round($completedStoryPoints / $startStoryPoints * 100, 1)
            : 0.0;

        $engagementPercent = $startTicketCount > 0
            ? round($completedFromStartTicketCount / $startTicketCount * 100, 1)
            : 0.0;

        return [
            'startStoryPoints'              => $startStoryPoints,
            'completedStoryPoints'          => $completedStoryPoints,
            'startTicketCount'              => $startTicketCount,
            'completedTicketCount'          => $completedTicketCount,
            'completedFromStartTicketCount' => $completedFromStartTicketCount,
            'targetPercent'                 => Sprint::PREDICTABILITY_TARGET_PERCENT,
            'targetStoryPoints'             => round($startStoryPoints * Sprint::PREDICTABILITY_TARGET_PERCENT / 100, 1),
            'achievedPercent'               => $achievedPercent,
            'isReached'                     => $achievedPercent > Sprint::PREDICTABILITY_TARGET_PERCENT,
            'engagementPercent'             => $engagementPercent,
            'isEngagementReached'           => $engagementPercent > Sprint::PREDICTABILITY_TARGET_PERCENT,
        ];
    }

    /**
     * Calcule un pourcentage `value / base * 100`, arrondi à 1 décimale.
     *
     * @param  float      $value Numérateur (ex: points ajoutés)
     * @param  float      $base  Dénominateur (ex: points planifiés)
     * @return float|null        Pourcentage, ou null si la base est nulle/négative
     */
    private function percentOf(float $value, float $base): ?float
    {
        if ($base <= 0) {
            return null;
        }

        return round($value / $base * 100, 1);
    }

    /**
     * Filtre une liste de tickets selon un prédicat.
     *
     * @param  list<SprintTicketDto>     $tickets
     * @param  callable(SprintTicketDto): bool $predicate
     * @return list<SprintTicketDto>
     */
    private function filterTickets(array $tickets, callable $predicate): array
    {
        return array_values(array_filter($tickets, $predicate));
    }

    /**
     * Retourne les tickets « amélioration continue » : ceux portant l'étiquette amelio_continue,
     * OU dont le type d'issue figure dans Sprint::TYPES_CONTINUOUS_IMPROVEMENT (« Innovation » /
     * « Amélioration continue »).
     * Comparaisons insensibles à la casse ; un ticket cumulant label et type n'apparaît qu'une fois
     * (un seul passage par le prédicat).
     *
     * @param  list<SprintTicketDto> $tickets
     * @return list<SprintTicketDto>
     */
    private function continuousImprovementTickets(array $tickets): array
    {
        return $this->filterTickets($tickets, static function (SprintTicketDto $ticket): bool {
            foreach ($ticket->labels as $issueLabel) {
                if (strcasecmp($issueLabel, Sprint::LABEL_CONTINUOUS_IMPROVEMENT) === 0) {
                    return true;
                }
            }

            foreach (Sprint::TYPES_CONTINUOUS_IMPROVEMENT as $type) {
                if (strcasecmp($ticket->issueType, $type) === 0) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Résume un sous-ensemble de tickets : compteur, totaux SP et liste.
     *
     * Totaux fournis :
     *   - storyPoints          : total des Story Points (initiaux, à la date de réf.)
     *   - remainingStoryPoints : total du champ « Reste à faire »
     *     (STORY_POINTS_REMAINING_CUSTOM_FIELD → spRemaining)
     *   - finalStoryPoints     : total du champ « Points rééstimés / SP final »
     *     (STORY_POINTS_FINAL_CUSTOM_FIELD → spFinal)
     *
     * @param  list<SprintTicketDto> $tickets
     * @return array{count:int, storyPoints:float, remainingStoryPoints:float, finalStoryPoints:float,
     *     issues:list<array<string, mixed>>}
     */
    private function summarize(array $tickets): array
    {
        $storyPoints          = 0.0;
        $remainingStoryPoints = 0.0;
        $finalStoryPoints     = 0.0;

        foreach ($tickets as $ticket) {
            $storyPoints          += $ticket->storyPoints ?? 0.0;
            $remainingStoryPoints += $ticket->spRemaining ?? 0.0;
            $finalStoryPoints     += $ticket->spFinal ?? 0.0;
        }

        return [
            'count'                => count($tickets),
            'storyPoints'          => $storyPoints,
            'remainingStoryPoints' => $remainingStoryPoints,
            'finalStoryPoints'     => $finalStoryPoints,
            // Forme array : les résumés partent tels quels vers la vue et le snapshot JSON.
            'issues'               => array_map(static fn (SprintTicketDto $t): array => $t->toArray(), $tickets),
        ];
    }

    /**
     * Ventilation des non-estimés par type de ticket (ex. ['Story' => 2, 'Bug' => 1]).
     * Même condition que le compteur issuesWithoutStoryPoints.
     *
     * @param  list<SprintTicketDto> $tickets
     * @return array<string, int> Type de ticket => nombre de non-estimés, du plus fréquent au moins fréquent
     */
    private function issuesWithoutStoryPointsByType(array $tickets): array
    {
        $issuesWithoutStoryPointsByType = [];
        foreach ($tickets as $ticket) {
            if ($ticket->storyPoints === null) {
                $issuesWithoutStoryPointsByType[$ticket->issueType]
                    = ($issuesWithoutStoryPointsByType[$ticket->issueType] ?? 0) + 1;
            }
        }
        arsort($issuesWithoutStoryPointsByType); // du type le plus fréquent au moins fréquent
        return $issuesWithoutStoryPointsByType;
    }
}
