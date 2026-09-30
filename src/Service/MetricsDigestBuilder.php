<?php

namespace App\Service;

use App\Model\Sprint;
use App\Model\StatusCategory;
use App\Model\TeamMetrics;

/**
 * MetricsDigestBuilder
 *
 * Sérialise des métriques déjà calculées (sprint unique ou agrégat d'équipe) en un digest texte
 * chiffré et lisible, destiné à être soumis à un LLM : synthèse IA locale (LlmService) ou export
 * d'un prompt à coller dans une IA Web.
 *
 * Aucun ticket brut n'est exposé : uniquement les agrégats des modèles Sprint / TeamMetrics.
 */
class MetricsDigestBuilder
{
    /**
     * Formate la complétion par objectif en lignes de digest.
     *
     * Produit un en-tête « Objectifs du sprint : » suivi d'une ligne par objectif « objectif_N / sprint_goal_N » (pourcentage + ratio terminés/total).
     * Si aucun objectif n'est étiqueté, une unique ligne l'indique explicitement (évite un en-tête orphelin).
     *
     * Réutilise MetricsDigestBuilder::format() pour le pourcentage afin d'harmoniser
     * l'affichage numérique avec le reste du digest (75.0 → « 75 »).
     *
     * @param  array<string, array{done:int, total:int, percent:float}> $goals
     *         Agrégats produits par MetricsDigestBuilder::progressPerSprintGoal().
     * @return string[] Lignes prêtes à être fusionnées dans le digest.
     */
    private function formatSprintGoals(array $goals): array
    {
        if ($goals === []) {
            return ['Complétion des objectifs du sprint : aucun ticket associé à un objectif (pas d\'étiquette objectif_<1/2/3/...> ou goal_<1/2/3/...>)'];
        }

        $lines = ['Complétion des objectifs du sprint :'];

        foreach ($goals as $label => $goal) {
            // ex. « - objectif_1 : 75 % (3/4 terminés) »
            $lines[] = '- ' . $label . ' : ' . $this->format($goal['percent']) . ' %'
                . ' (' . $goal['done'] . '/' . $goal['total'] . ' terminés)';
        }

        return $lines;
    }

    /**
     * Formate l'atterrissage par statut en lignes de digest.
     *
     * Reçoit Sprint::getLandingByStatus() (déjà ordonné selon le workflow, statuts transités à
     * 0 ticket inclus). Exclut la catégorie « refinement » (hors atterrissage). N'expose que des
     * agrégats par statut — aucun nom de ticket.
     *
     * @param  list<array{status:string, category:string, count:int, storyPoints:float,
     *     totalDays:float, averageDays:float}> $landing
     * @return string[]
     */
    private function formatLanding(array $landing): array
    {
        $lines = [];

        foreach ($landing as $row) {
            if ($row['category'] === StatusCategory::REFINEMENT) {
                continue; // affinage : hors périmètre d'atterrissage
            }

            $lines[] = '- ' . $row['status'] . ' : '
                . $row['count'] . ' tickets à date, '
                . $this->format($row['storyPoints']) . ' SP, '
                . 'cumul ' . $this->format($row['totalDays']) . ' j, '
                . 'moyenne ' . $this->format($row['averageDays']) . ' j/ticket';
        }

        return $lines === [] ? ['- (aucun statut hors affinage)'] : $lines;
    }

    /**
     * Formate les causes d'attente cumulées en lignes de digest (une par cause, jours ouvrés).
     * Ligne « aucune » si le tableau est vide (évite un intitulé orphelin). Partagé sprint/équipe.
     *
     * @param  array<string, float> $causes Cause => jours ouvrés cumulés (déjà triés desc par la source)
     * @param  array<string, float> $full   Mêmes causes sur tout le cycle de vie des tickets ; vide côté
     *                                      équipe, où la valeur non bornée n'est pas sommable entre sprints
     * @return string[]
     */
    private function formatWaitingCauses(array $causes, array $full = []): array
    {
        if ($causes === []) {
            return ['- Aucune cause d\'attente (statut, étiquette ou indicateur)'];
        }

        $lines = [];
        foreach ($causes as $cause => $days) {
            $line = '- ' . $cause . ' : ' . $this->format($days) . ' j';
            if ($full !== []) {
                $line .= ' (' . $this->format($full[$cause] ?? $days) . ' j sur tout le cycle de vie)';
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Formate un nombre pour le digest : 2 décimales maximum, sans « .0 » superflu ; « N/A » si null.
     */
    private function format(float|int|string|null $value): string
    {
        if ($value === null) {
            return 'N/A';
        }

        $formatted = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Formate la distribution du cycle time par type puis par estimation, en lignes de digest.
     * N'expose que des agrégats (aucune clé de ticket). Ligne « aucun » si vide.
     *
     * @param  array<string, list<array{storyPoints:float, count:int, average:float,
     *     median:float, min:float, max:float}>> $byType Cf. TeamMetrics::getCycleTimeBySpByType().
     * @return string[]
     */
    private function formatCycleTimeBySpByType(array $byType): array
    {
        if ($byType === []) {
            return ['- Aucun ticket terminé estimé avec un cycle time mesurable'];
        }

        $lines = [];
        foreach ($byType as $type => $rows) {
            $lines[] = $type . ' :';
            foreach ($rows as $row) {
                $lines[] = '  - ' . $this->format($row['storyPoints']) . ' SP'
                    . ' (' . $row['count'] . ' tickets) : '
                    . 'min ' . $this->format($row['min'])
                    . ', max ' . $this->format($row['max'])
                    . ', médiane ' . $this->format($row['median'])
                    . ', moyenne ' . $this->format($row['average']) . ' j';
            }
        }

        return $lines;
    }

    /**
     * Formate une ventilation [type => nombre] en « 2 Story, 1 Bug ».
     * Chaîne vide si le tableau est vide (le total suffit alors).
     *
     * @param  array<string, int> $countByType Type de ticket => nombre (déjà trié desc par la source)
     */
    private function formatCountByType(array $countByType): string
    {
        $parts = [];
        foreach ($countByType as $type => $count) {
            $parts[] = $count . ' ' . $type;
        }

        return implode(', ', $parts);
    }

    /**
     * Formate le bloc capacité + vélocité d'un sprint.
     *
     * Capacités jours/homme et points reprises telles quelles (« N/A » si non saisies). Les 5 ratios de
     * vélocité reprennent exactement les formules du composant JS `sprintManDays` de la page /sprint (source
     * de vérité pour la cohérence UI ↔ digest) : chacun vaut « N/A » si un dénominateur est nul ou absent
     * (jours/homme non saisis, aucun SP terminé...).
     *
     * @param  float  $doneSp         Story Points terminés (numérateur des vélocités « par ... »)
     * @param  float  $businessDays   Durée du sprint en jours ouvrés
     * @param  ?float $manDays        Capacité jours/homme saisie, null si non renseignée
     * @param  ?float $capacityPoints Capacité en points saisie, null si non renseignée
     * @return string[]
     */
    private function formatSprintCapacityAndVelocity(
        float $doneSp,
        float $businessDays,
        ?float $manDays,
        ?float $capacityPoints
    ): array {
        // Ratio défensif : null (→ « N/A ») si le dénominateur est absent ou ≤ 0, ou le numérateur absent.
        $ratio = static function (?float $num, ?float $den): ?float {
            return ($num !== null && $den !== null && $den > 0.0) ? $num / $den : null;
        };

        $spPerPerson = ($manDays !== null && $manDays > 0.0 && $businessDays > 0.0)
            ? $doneSp * $businessDays / $manDays
            : null;

        return [
            '- Capacité jours/homme : ' . $this->format($manDays),
            '- Capacité en points : ' . $this->format($capacityPoints),
            '- SP terminés / jour-homme : ' . $this->format($ratio($doneSp, $manDays)),
            '- SP terminés / jour ouvré : ' . $this->format($ratio($doneSp, $businessDays)),
            '- Jours-homme / SP : ' . $this->format($ratio($manDays, $doneSp)),
            '- Jours ouvrés / SP : ' . $this->format($ratio($businessDays, $doneSp)),
            '- SP terminés / personne : ' . $this->format($spPerPerson),
        ];
    }

    /**
     * Digest d'un sprint : périmètre, prédictibilité, engagement, atterrissage, capacité/vélocité,
     * évolution du périmètre etc.
     *
     * @param ?float $manDays        Capacité jours/homme saisie pour le sprint (config), null si non renseignée
     * @param ?float $capacityPoints Capacité en points saisie pour le sprint (config), null si non renseignée
     */
    public function forSprint(Sprint $sprint, ?float $manDays = null, ?float $capacityPoints = null): string
    {
        $stats  = $sprint->getStats();
        $pred   = $sprint->getPredictability();
        $scope  = $stats['scopeChange'] ?? [];
        $growth = $scope['storyPointsGrowthPercent'] ?? null;
        $range  = $stats['storyPointsRange'] ?? [];
        $unestimatedByType = $this->formatCountByType($stats['issuesWithoutStoryPointsByType'] ?? []);
        $sprintGoalsCompletion = $sprint->getSprintGoalsCompletion();

        // Bases des ratios de vélocité (mêmes entrées que le composant JS sprintManDays de la page).
        $doneSp       = (float) ($stats['doneTickets']['storyPoints'] ?? 0);
        $businessDays = $sprint->getBusinessDays();

        $lines = [
            'Sprint : ' . $sprint->getName(),
            'État : ' . $sprint->getState(),
            'Période : ' . ($sprint->getStartDate() ?? '?') . ' → '
                . ($sprint->getCompleteDate() ?? $sprint->getEndDate() ?? '?'),
            '',
            ...$this->formatSprintGoals($sprintGoalsCompletion),
            '',
            'Périmètre :',
            '- Tickets dans le sprint : ' . ($scope['currentTicketCount'] ?? 0),
            '- Total Story Points : ' . $this->format($stats['totalStoryPoints'] ?? 0),
            '- Tickets non estimés à la clôture : ' . ($stats['issuesWithoutStoryPoints'] ?? 0)
                . ($unestimatedByType !== '' ? ' (' . $unestimatedByType . ')' : ''),
            '- Dont estimés après leur ajout au sprint : ' . ($stats['estimatedAfterAdd']['count'] ?? 0),
            '- Features traitées : ' . count($sprint->getFeatures()),
            '- Distribution des SP : min ' . $this->format($range['min'] ?? 0)
                . ', max ' . $this->format($range['max'] ?? 0)
                . ', médiane ' . $this->format($range['median'] ?? 0)
                . ', moyenne ' . $this->format($range['average'] ?? 0),
            '',
            'Prédictibilité (Story Points) :',
            '- SP planifiés au démarrage : ' . $this->format($pred['startStoryPoints'] ?? 0),
            '- SP terminés : ' . $this->format($pred['completedStoryPoints'] ?? 0),
            '- Prédictibilité atteinte : ' . $this->format($pred['achievedPercent'] ?? 0)
                . ' % (cible minimale : ' . $this->format($pred['targetPercent'] ?? 0) . ' %)',
            '',
            'Engagement (sujets du périmètre initial) :',
            '- Tickets planifiés au démarrage : ' . ($pred['startTicketCount'] ?? 0),
            '- Tickets planifiés terminés : ' . ($pred['completedFromStartTicketCount'] ?? 0),
            '- Engagement atteint : ' . $this->format($pred['engagementPercent'] ?? 0)
                . ' % (cible minimale : ' . $this->format($pred['targetPercent'] ?? 0) . ' %)',
            '',
            'Atterrissage :',
            '- Tickets terminés : ' . ($stats['doneTickets']['count'] ?? 0)
                . ' (' . $this->format($stats['doneTickets']['storyPoints'] ?? 0) . ' SP)',
            '- Reliquat (non terminés) : ' . ($stats['reliquat']['count'] ?? 0)
                . ' (' . $this->format($stats['reliquat']['storyPoints'] ?? 0) . ' SP)',
            '',
            'Atterrissage par statut (hors affinage) :',
            ...$this->formatLanding($sprint->getLandingByStatus()),
            '',
            // Les chiffres sont bornés au sprint ; le pendant « cycle de vie complet » est fourni entre
            // parenthèses pour que le modèle distingue un blocage né dans le sprint d'une attente héritée.
            'Temps d\'attente (borné au sprint, entre parenthèses sur tout le cycle de vie des tickets) :',
            '- Total : ' . $this->format($sprint->getTotalTimeSpentInWaiting()) . ' j'
                . ' (' . $this->format($sprint->getTotalTimeSpentInWaitingFull()) . ' j sur tout le cycle de vie)',
            '- Moyenne : ' . $this->format($sprint->getAverageTimeSpentInWaiting()) . ' j/ticket'
                . ' (' . $this->format($sprint->getAverageTimeSpentInWaitingFull()) . ' j sur tout le cycle de vie)',
            'Détail des causes :',
            ...$this->formatWaitingCauses($sprint->getAggregatedWaitingTimes(), $sprint->getAggregatedWaitingTimesFull()),
            '',
            'Capacité & vélocité :',
            ...$this->formatSprintCapacityAndVelocity($doneSp, $businessDays, $manDays, $capacityPoints),
            '',
            'Évolution du périmètre :',
            '- SP planifiés → à la fin : ' . $this->format($scope['plannedStoryPoints'] ?? 0)
                . ' → ' . $this->format($scope['currentStoryPoints'] ?? 0),
            '- Croissance du périmètre (SP) : ' . ($growth === null ? 'N/A' : $this->format($growth) . ' %'),
            '- Tickets ajoutés en cours de sprint : ' . ($stats['addedDuringSprint']['count'] ?? 0)
                . ' (' . $this->format($stats['addedDuringSprint']['storyPoints'] ?? 0) . ' SP)',
            '- Dont priorités ajoutées en urgence (étiquette nouvelle_prio) : ' . ($stats['newPrio']['count'] ?? 0)
                . ' (' . $this->format($stats['newPrio']['storyPoints'] ?? 0) . ' SP)',
            '- Tickets retirés du sprint : ' . ($stats['removedDuringSprint']['count'] ?? 0)
                . ' (' . $this->format($stats['removedDuringSprint']['storyPoints'] ?? 0) . ' SP)',
        ];

        return implode("\n", $lines);
    }

    /**
     * Digest d'un agrégat d'équipe : moyennes par sprint, prédictibilité/engagement, vélocité,
     * temps de traversée, etc. Puis un récapitulatif sprint par sprint en ordre chronologique (par date
     * de clôture, à défaut date de fin) pour donner la tendance au modèle.
     */
    public function forTeam(TeamMetrics $metrics): string
    {
        $stats   = $metrics->getStats();
        $sprints = $metrics->getSprints();

        // Sprints en ordre chronologique (par clôture, à défaut fin) pour la tendance.
        usort($sprints, static function (array $a, array $b): int {
            $keyA = $a['completeDate'] ?: ($a['endDate'] ?: '');
            $keyB = $b['completeDate'] ?: ($b['endDate'] ?: '');
            return strcmp((string) $keyA, (string) $keyB);
        });

        $lines = [
            'Nombre de sprints clôturés analysés : ' . ($stats['sprintCount'] ?? count($sprints)),
            'Jours ouvrés cumulés : ' . $this->format($stats['totalBusinessDays'] ?? 0),
            '',
            'Moyennes par sprint :',
            '- Tickets terminés : ' . $this->format($stats['avgDoneTickets'] ?? 0),
            '- Story Points terminés : ' . $this->format($stats['avgDoneStoryPoints'] ?? 0),
            '- Durée (jours ouvrés) : ' . $this->format($stats['avgBusinessDays'] ?? 0),
            '- Durée (jours-homme) : ' . $this->format($stats['avgManDays'] ?? null),
            '- Tickets ajoutés en cours de sprint : ' . $this->format($stats['avgAddedTickets'] ?? 0)
                . ' (' . $this->format($stats['avgAddedStoryPoints'] ?? 0) . ' SP)',
            '- Reliquat : ' . $this->format($stats['avgReliquatTickets'] ?? 0) . ' tickets ('
                . $this->format($stats['avgReliquatStoryPoints'] ?? 0) . ' SP)',
            '- Reliquat : part non terminée moyenne ' . $this->format($stats['reliquatTicketSharePercent'] ?? null)
                . ' % des tickets, ' . $this->format($stats['reliquatSpSharePercent'] ?? null) . ' % des SP',
            '- Delta moyen (total SP vs capacité) : ' . $this->format($stats['avgCapacityDelta'] ?? null) . ' pts',
            '',
            'Prédictibilité & engagement :',
            '- Prédictibilité moyenne : ' . $this->format($stats['avgPredictability'] ?? 0)
                . ' % (cible minimale : ' . $this->format($stats['predictabilityTarget'] ?? 0) . ' %)',
            '- Engagement moyen : ' . $this->format($stats['avgEngagement'] ?? 0)
                . ' % (cible minimale : ' . $this->format($stats['predictabilityTarget'] ?? 0) . ' %)',
            '- Changement de périmètre moyen : ' . $this->format($stats['avgScopeGrowthPercent'] ?? null) . ' %'
                . ' (dont nouvelle_prio : ' . $this->format($stats['avgnewPrioPercent'] ?? null) . ' %)',
            '',
            'Vélocité :',
            '- Story Points terminés / jour ouvré : ' . $this->format($stats['velocityPerDay'] ?? 0),
            '- Story Points terminés / jour-homme : ' . $this->format($stats['velocityPerManDay'] ?? null),
            '- Story Points terminés / personne : ' . $this->format($stats['avgSpPerPerson'] ?? null),
            '- Jours ouvrés / Story Point : ' . $this->format($stats['daysPerSp'] ?? null),
            '- Jours-homme / Story Point : ' . $this->format($stats['manDaysPerSp'] ?? null),
            '',
            'Temps de traversée (tickets terminés, jours ouvrés) :',
            '- Lead time : moyen ' . $this->format($stats['avgLeadTime'] ?? 0)
                . ', médian ' . $this->format($stats['medianLeadTime'] ?? 0),
            '- Cycle time : moyen ' . $this->format($stats['avgCycleTime'] ?? 0)
                . ', médian ' . $this->format($stats['medianCycleTime'] ?? 0),
            '- Waiting time : moyen ' . $this->format($stats['avgWaitingTime'] ?? 0)
                . ', médian ' . $this->format($stats['medianWaitingTime'] ?? 0),
            '',
            'Causes d\'attente (jours ouvrés cumulés) :',
            ...$this->formatWaitingCauses($metrics->getAggregatedWaitingTimes()),
            '',
            'Cycle time par estimation et par type (jours ouvrés) :',
            ...$this->formatCycleTimeBySpByType($metrics->getCycleTimeBySpByType()),
            '',
            'Détails par sprint (du plus ancien au plus récent) :',
        ];

        foreach ($sprints as $sprint) {
            $lines[] = '- ' . ($sprint['name'] ?? '?')
                . ' : prédictibilité ' . $this->format($sprint['predictabilityPercent'] ?? 0) . ' %'
                . ', engagement ' . $this->format($sprint['engagementPercent'] ?? 0) . ' %'
                . ', ' . ($sprint['doneCount'] ?? 0) . ' tickets terminés ('
                . $this->format($sprint['doneStoryPoints'] ?? 0) . ' SP)'
                . ', reliquat ' . ($sprint['reliquatCount'] ?? 0) . ' tickets'
                . ', changement de périmètre ' . $this->format($sprint['scopeGrowthPercent'] ?? null) . ' %'
                . ', vélocité (points par jours/homme) ' . $this->format($sprint['velocityPerManDay'] ?? null);
        }

        return implode("\n", $lines);
    }
}
