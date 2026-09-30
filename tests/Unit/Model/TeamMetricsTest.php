<?php

namespace Tests\Unit\Model;

use App\Model\TeamMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests des agrégats d'équipe (calculs purs sur la liste des résumés de sprint).
 *
 * - computeAggregates()   : vélocité (SP/jour), garde division par zéro, moyennes
 *                           par sprint, exclusion des scope-change non définis,
 *                           médianes lead/cycle/waiting.
 * - computeCycleTimeBySp(): regroupement du cycle time par estimation.
 * - median()              : médiane (vide/pair/impair, arrondi 1 décimale).
 *
 * TeamMetrics est instancié sans constructeur (le constructeur chargerait les
 * sprints via Jira/cache) ; on injecte directement la propriété $sprints.
 */
class TeamMetricsTest extends TestCase
{
    private function makeTeamMetrics(array $sprints): TeamMetrics
    {
        $tm = (new ReflectionClass(TeamMetrics::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TeamMetrics::class, 'sprints'))->setValue($tm, $sprints);
        return $tm;
    }

    private function call(TeamMetrics $tm, string $method, array $args = []): mixed
    {
        return (new ReflectionMethod(TeamMetrics::class, $method))->invokeArgs($tm, $args);
    }

    /**
     * Résumé de sprint au format attendu par computeAggregates() (valeurs par défaut).
     */
    private function sprintSummary(array $overrides = []): array
    {
        return array_merge([
            'doneCount'             => 0,
            'doneStoryPoints'       => 0.0,
            'totalCount'            => 0,
            'waitingCauses'         => [],
            'totalStoryPoints'      => 0.0,
            'totalRemainingStoryPoints' => 0.0,
            'addedCount'            => 0,
            'addedStoryPoints'      => 0.0,
            'reliquatCount'         => 0,
            'reliquatStoryPoints'   => 0.0,
            'reliquatRemainingSp'   => 0.0,
            'businessDays'          => 0.0,
            'predictabilityPercent' => 0.0,
            'engagementPercent'     => 0.0,
            'scopeGrowthPercent'    => null,
            'newPrioPercent'        => null,
            'manDays'               => null,
            'capacityPoints'        => null,
            'doneIssues'            => [],
        ], $overrides);
    }

    private function aggregatesFor(array $sprints): array
    {
        $tm = $this->makeTeamMetrics($sprints);
        $this->call($tm, 'computeAggregates');
        return $tm->getStats();
    }

    // =====================================================================
    // Vélocité
    // =====================================================================

    #[Test]
    public function test_velocite_estLesPointsTerminesParJourOuvre(): void
    {
        // 30 SP terminés / 15 jours ouvrés = 2.0 SP/jour.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'businessDays' => 10.0]),
            $this->sprintSummary(['doneStoryPoints' => 10.0, 'businessDays' => 5.0]),
        ]);

        $this->assertSame(2.0, $stats['velocityPerDay']);
        $this->assertSame(30.0, $stats['totalDoneStoryPoints']);
        $this->assertSame(15.0, $stats['totalBusinessDays']);
        // Inverse : 15 jours ouvrés / 30 SP = 0.5 jour ouvré pour terminer 1 SP.
        $this->assertSame(0.5, $stats['daysPerSp']);
    }

    #[Test]
    public function test_velocite_zeroJourOuvre_retourneZeroSansDivisionParZero(): void
    {
        // Aucun jour ouvré (dates manquantes) → vélocité 0, pas d'erreur.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 10.0, 'businessDays' => 0.0]),
        ]);

        $this->assertSame(0.0, $stats['velocityPerDay']);
        // 0 jour ouvré / 10 SP = 0.0 (garde la division par zéro côté SP, pas côté jours).
        $this->assertSame(0.0, $stats['daysPerSp']);
    }

    // =====================================================================
    // Vélocité jours-homme (saisie utilisateur, sprints non renseignés exclus)
    // =====================================================================

    #[Test]
    public function test_velociteJoursHomme_estSpTerminesParJourHommeSaisi(): void
    {
        // (20 + 10) SP / (25 + 5) jours-homme = 30 / 30 = 1.0 SP/jour-homme.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'manDays' => 25.0]),
            $this->sprintSummary(['doneStoryPoints' => 10.0, 'manDays' => 5.0]),
        ]);

        $this->assertSame(1.0, $stats['velocityPerManDay']);
        $this->assertSame(30.0, $stats['totalManDays']);
        // Inverse : 30 jours-homme / 30 SP = 1.0 jour-homme pour terminer 1 SP.
        $this->assertSame(1.0, $stats['manDaysPerSp']);
        // Jours-homme : moyenne (25 + 5) / 2 = 15.0 et fourchette 5 – 25.
        $this->assertSame(15.0, $stats['avgManDays']);
        $this->assertSame(5.0, $stats['minManDays']);
        $this->assertSame(25.0, $stats['maxManDays']);
    }

    #[Test]
    public function test_velociteJoursHomme_excutLesSprintsSansSaisie(): void
    {
        // Seul le sprint renseigné compte : 20 SP / 10 jours-homme = 2.0.
        // Le sprint sans manDays n'entre ni au numérateur ni au dénominateur.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'manDays' => 10.0]),
            $this->sprintSummary(['doneStoryPoints' => 50.0, 'manDays' => null]),
        ]);

        $this->assertSame(2.0, $stats['velocityPerManDay']);
        $this->assertSame(10.0, $stats['totalManDays']);
        // Inverse, sur le seul sprint renseigné : 10 jours-homme / 20 SP = 0.5.
        $this->assertSame(0.5, $stats['manDaysPerSp']);
    }

    #[Test]
    public function test_velociteJoursHomme_aucuneSaisie_retourneNull(): void
    {
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'manDays' => null]),
            $this->sprintSummary(['doneStoryPoints' => 10.0, 'manDays' => 0.0]),
        ]);

        $this->assertNull($stats['velocityPerManDay']);
        $this->assertSame(0.0, $stats['totalManDays']);
        // Aucune saisie de jours-homme → inverse également null.
        $this->assertNull($stats['manDaysPerSp']);
        // Sans jours-homme, le SP par personne est indéfini.
        $this->assertNull($stats['avgSpPerPerson']);
        // Sans jours-homme, moyenne et fourchette indéfinies.
        $this->assertNull($stats['avgManDays']);
        $this->assertNull($stats['minManDays']);
        $this->assertNull($stats['maxManDays']);
    }

    // =====================================================================
    // SP par personne (effectif moyen estimé = jours-homme / jours ouvrés)
    // =====================================================================

    #[Test]
    public function test_spParPersonne_moyenneParSprintAvecEffectifEstime(): void
    {
        // s1 : 20 SP, effectif 10/5 = 2 → 10 SP/personne.
        // s2 : 30 SP, effectif 15/10 = 1.5 → 20 SP/personne.
        // Moyenne = (10 + 20) / 2 = 15.0.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'manDays' => 10.0, 'businessDays' => 5.0]),
            $this->sprintSummary(['doneStoryPoints' => 30.0, 'manDays' => 15.0, 'businessDays' => 10.0]),
        ]);

        $this->assertSame(15.0, $stats['avgSpPerPerson']);
    }

    #[Test]
    public function test_spParPersonne_ignoreLesSprintsSansJoursHommeOuSansJourOuvre(): void
    {
        // Seul le 1er sprint est éligible (manDays + businessDays) : 20 × 5 / 10 = 10.
        // Le 2e (sans manDays) et le 3e (sans jour ouvré) sont exclus.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneStoryPoints' => 20.0, 'manDays' => 10.0, 'businessDays' => 5.0]),
            $this->sprintSummary(['doneStoryPoints' => 50.0, 'manDays' => null, 'businessDays' => 8.0]),
            $this->sprintSummary(['doneStoryPoints' => 40.0, 'manDays' => 8.0, 'businessDays' => 0.0]),
        ]);

        $this->assertSame(10.0, $stats['avgSpPerPerson']);
    }

    // =====================================================================
    // Moyennes par sprint
    // =====================================================================

    #[Test]
    public function test_moyennesParSprint(): void
    {
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneCount' => 6, 'doneStoryPoints' => 20.0, 'addedCount' => 2]),
            $this->sprintSummary(['doneCount' => 4, 'doneStoryPoints' => 10.0, 'addedCount' => 0]),
        ]);

        $this->assertSame(2, $stats['sprintCount']);
        $this->assertSame(5.0, $stats['avgDoneTickets']);       // (6+4)/2
        $this->assertSame(15.0, $stats['avgDoneStoryPoints']);  // (20+10)/2
        $this->assertSame(1.0, $stats['avgAddedTickets']);      // (2+0)/2
    }

    #[Test]
    public function test_resteAFaireMoyen_estLaMoyenneParSprint(): void
    {
        // Reste à faire moyen = (12 + 8) / 2 = 10.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['totalRemainingStoryPoints' => 12.0]),
            $this->sprintSummary(['totalRemainingStoryPoints' => 8.0]),
        ]);

        $this->assertSame(10.0, $stats['avgRemainingStoryPoints']);
    }

    #[Test]
    public function test_causesAttente_cumuleLesCausesParSprintEtTriParDureeDecroissante(): void
    {
        // Chaque sprint apporte sa ventilation ; les causes communes s'additionnent, les autres
        // s'ajoutent. Attendu : Attente client 4.5 (1.5+3), Revue 3.0 (3), Attente TMA 1.0 (1),
        // trié du plus coûteux au moins coûteux.
        $tm = $this->makeTeamMetrics([
            $this->sprintSummary(['waitingCauses' => ['Attente client' => 1.5, 'Revue' => 3.0]]),
            $this->sprintSummary(['waitingCauses' => ['Attente client' => 3.0, 'Attente TMA' => 1.0]]),
        ]);

        $this->call($tm, 'computeAggregatedWaitingTimes');

        $this->assertSame(
            ['Attente client' => 4.5, 'Revue' => 3.0, 'Attente TMA' => 1.0],
            $tm->getAggregatedWaitingTimes()
        );
    }

    #[Test]
    public function test_causesAttente_aucuneCause_retourneTableauVide(): void
    {
        $tm = $this->makeTeamMetrics([$this->sprintSummary()]);

        $this->call($tm, 'computeAggregatedWaitingTimes');

        $this->assertSame([], $tm->getAggregatedWaitingTimes());
    }

    #[Test]
    public function test_partReliquat_estLaMoyenneDesTauxParSprintEtNonLeRatioCumule(): void
    {
        // Taux de reliquat de CHAQUE sprint, puis moyenne : chaque sprint pèse pareil.
        // Tickets : S1 = 1/10 (10 %), S2 = 9/30 (30 %) → (10+30)/2 = 20 % (le cumulé donnerait 25 %).
        // SP      : S1 = 2/20 (10 %), S2 = 18/60 (30 %) → (10+30)/2 = 20 % (le cumulé donnerait 25 %).
        $stats = $this->aggregatesFor([
            $this->sprintSummary([
                'totalCount' => 10, 'reliquatCount' => 1,
                'totalStoryPoints' => 20.0, 'reliquatStoryPoints' => 2.0,
            ]),
            $this->sprintSummary([
                'totalCount' => 30, 'reliquatCount' => 9,
                'totalStoryPoints' => 60.0, 'reliquatStoryPoints' => 18.0,
            ]),
        ]);

        $this->assertSame(20.0, $stats['reliquatTicketSharePercent']);
        $this->assertSame(20.0, $stats['reliquatSpSharePercent']);
    }

    #[Test]
    public function test_partReliquat_sprintAuVolumeNul_estExcluDeLaMoyenne(): void
    {
        // S2 n'a aucun ticket : il n'a pas de taux défini et ne doit pas tirer la moyenne vers 0.
        // Moyenne attendue = 20 % (S1 seul), et non 10 % (moyenne de 20 % et 0 %).
        $stats = $this->aggregatesFor([
            $this->sprintSummary([
                'totalCount' => 10, 'reliquatCount' => 2,
                'totalStoryPoints' => 10.0, 'reliquatStoryPoints' => 2.0,
            ]),
            $this->sprintSummary(['totalCount' => 0, 'totalStoryPoints' => 0.0]),
        ]);

        $this->assertSame(20.0, $stats['reliquatTicketSharePercent']);
        $this->assertSame(20.0, $stats['reliquatSpSharePercent']);
    }

    #[Test]
    public function test_partReliquat_volumeTotalNul_retourneNullSansDivisionParZero(): void
    {
        $stats = $this->aggregatesFor([$this->sprintSummary()]);

        $this->assertNull($stats['reliquatTicketSharePercent']);
        $this->assertNull($stats['reliquatSpSharePercent']);
    }

    #[Test]
    public function test_deltaCapacite_ignoreLesSprintsSansCapaciteSaisie(): void
    {
        // Delta = total SP − capacité, moyenné sur les seuls sprints avec capa saisie.
        // S1 : 25 − 20 = +5 ; S2 : capa absente → ignoré ; S3 : 18 − 22 = −4.
        // Moyenne = (5 + (−4)) / 2 = 0.5 ; capacité moyenne = (20 + 22) / 2 = 21.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['totalStoryPoints' => 25.0, 'capacityPoints' => 20.0]),
            $this->sprintSummary(['totalStoryPoints' => 30.0, 'capacityPoints' => null]),
            $this->sprintSummary(['totalStoryPoints' => 18.0, 'capacityPoints' => 22.0]),
        ]);

        $this->assertSame(0.5, $stats['avgCapacityDelta']);
        $this->assertSame(21.0, $stats['avgCapacityPoints']);
    }

    #[Test]
    public function test_deltaCapacite_nullSiAucuneCapaciteSaisie(): void
    {
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['totalStoryPoints' => 25.0]),
            $this->sprintSummary(['totalStoryPoints' => 30.0]),
        ]);

        $this->assertNull($stats['avgCapacityDelta']);
        $this->assertNull($stats['avgCapacityPoints']);
    }

    #[Test]
    public function test_engagementMoyen_estLaMoyenneDesEngagementsParSprint(): void
    {
        // Engagement moyen = (80 + 60 + 100) / 3 = 80.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['engagementPercent' => 80.0]),
            $this->sprintSummary(['engagementPercent' => 60.0]),
            $this->sprintSummary(['engagementPercent' => 100.0]),
        ]);

        $this->assertSame(80.0, $stats['avgEngagement']);
    }

    // =====================================================================
    // Changement de périmètre : exclusion des sprints sans valeur définie
    // =====================================================================

    #[Test]
    public function test_scopeChange_moyenneIgnoreLesSprintsNull(): void
    {
        // Seuls les sprints avec un % défini comptent : (40 + 20) / 2 = 30.
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['scopeGrowthPercent' => 40.0]),
            $this->sprintSummary(['scopeGrowthPercent' => null]),
            $this->sprintSummary(['scopeGrowthPercent' => 20.0]),
        ]);

        $this->assertSame(30.0, $stats['avgScopeGrowthPercent']);
    }

    #[Test]
    public function test_scopeChange_tousNull_retourneNull(): void
    {
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['scopeGrowthPercent' => null]),
            $this->sprintSummary(['scopeGrowthPercent' => null]),
        ]);

        $this->assertNull($stats['avgScopeGrowthPercent']);
    }

    // =====================================================================
    // Médianes lead / cycle / waiting (tickets terminés, valeurs > 0)
    // =====================================================================

    #[Test]
    public function test_medianesEtMoyennes_surTicketsTermines(): void
    {
        $stats = $this->aggregatesFor([
            $this->sprintSummary(['doneIssues' => [
                ['cycleTime' => 2.0, 'leadTime' => 4.0, 'waitingTime' => 1.0],
                ['cycleTime' => 4.0, 'leadTime' => 8.0, 'waitingTime' => 0.0], // waiting 0 ignoré
            ]]),
        ]);

        $this->assertSame(2, $stats['doneTicketCount']);     // 2 cycle times > 0
        $this->assertSame(3.0, $stats['avgCycleTime']);      // (2+4)/2
        $this->assertSame(3.0, $stats['medianCycleTime']);   // médiane [2,4]
        $this->assertSame(6.0, $stats['avgLeadTime']);       // (4+8)/2
        $this->assertSame(1.0, $stats['avgWaitingTime']);    // seul 1.0 compté
    }

    // =====================================================================
    // median() (privée)
    // =====================================================================

    #[Test]
    public function test_median_vide_retourneZero(): void
    {
        $this->assertSame(0.0, $this->call($this->makeTeamMetrics([]), 'median', [[]]));
    }

    #[Test]
    public function test_median_impair_retourneValeurCentrale(): void
    {
        $this->assertSame(2.0, $this->call($this->makeTeamMetrics([]), 'median', [[3.0, 1.0, 2.0]]));
    }

    #[Test]
    public function test_median_pair_retourneMoyenneArrondie(): void
    {
        // [1, 2, 3, 4] → (2+3)/2 = 2.5
        $this->assertSame(2.5, $this->call($this->makeTeamMetrics([]), 'median', [[1.0, 2.0, 3.0, 4.0]]));
    }

    // =====================================================================
    // computeCycleTimeBySp() : regroupement par estimation
    // =====================================================================

    #[Test]
    public function test_cycleTimeBySp_regroupeParEstimationEtTrie(): void
    {
        $tm = $this->makeTeamMetrics([
            $this->sprintSummary(['doneIssues' => [
                ['storyPoints' => 3.0, 'cycleTime' => 2.0],
                ['storyPoints' => 3.0, 'cycleTime' => 4.0],
                ['storyPoints' => 5.0, 'cycleTime' => 10.0],
                ['storyPoints' => null, 'cycleTime' => 9.0], // non estimé → exclu
                ['storyPoints' => 3.0, 'cycleTime' => 0.0],  // cycle nul → exclu
            ]]),
        ]);

        $this->call($tm, 'computeCycleTimeBySp');
        $dist = $tm->getCycleTimeBySp();

        // Deux seaux triés par estimation croissante : 3 puis 5.
        $this->assertCount(2, $dist);

        $this->assertSame(3.0, $dist[0]['storyPoints']);
        $this->assertSame(2, $dist[0]['count']);
        $this->assertSame(3.0, $dist[0]['average']); // (2+4)/2
        $this->assertSame(3.0, $dist[0]['median']);
        $this->assertSame(2.0, $dist[0]['min']);
        $this->assertSame(4.0, $dist[0]['max']);

        $this->assertSame(5.0, $dist[1]['storyPoints']);
        $this->assertSame(1, $dist[1]['count']);
        $this->assertSame(10.0, $dist[1]['average']);
    }

    // =====================================================================
    // computeCycleTimeScatterByType() : nuage de points groupé par type
    // =====================================================================

    #[Test]
    public function test_cycleTimeScatterByType_groupeEtTriePARFrequence(): void
    {
        $tm = $this->makeTeamMetrics([
            $this->sprintSummary(['doneIssues' => [
                ['issueType' => 'Story', 'storyPoints' => 3.0, 'cycleTime' => 2.0, 'key' => 'A'],
                ['issueType' => 'Story', 'storyPoints' => 5.0, 'cycleTime' => 4.0, 'key' => 'B'],
                ['issueType' => 'Bug',   'storyPoints' => 2.0, 'cycleTime' => 6.0, 'key' => 'C'],
                ['storyPoints' => 1.0, 'cycleTime' => 1.0, 'key' => 'D'],                 // type absent → « Autre »
                ['issueType' => 'Bug', 'storyPoints' => 0.0, 'cycleTime' => 5.0, 'key' => 'E'], // sp 0 → exclu
            ]]),
        ]);

        $this->call($tm, 'computeCycleTimeScatterByType');
        $scatter = $tm->getCycleTimeScatterByType();

        // Types triés par nombre de points décroissant : Story (2), puis Bug, Autre (1).
        $this->assertSame(['Story', 'Bug', 'Autre'], array_keys($scatter));
        $this->assertCount(2, $scatter['Story']);
        $this->assertSame(['x' => 3.0, 'y' => 2.0, 'key' => 'A'], $scatter['Story'][0]);
        $this->assertCount(1, $scatter['Autre']);
    }
}
