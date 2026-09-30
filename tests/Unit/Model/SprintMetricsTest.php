<?php

namespace Tests\Unit\Model;

use App\Dto\SprintTicketDto;
use App\Model\Sprint;
use App\Model\SprintMetricsCalculator;
use App\Model\SprintTicketBuilder;
use App\Model\Timeline;
use DateTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests des métriques agrégées du sprint (calculs purs sur $tickets) :
 * - computePredictability() : prédictibilité (SP) et engagement (sujets), cible 80 %
 * - computeStats()          : fourchette SP, non-estimés, totaux done / reliquat
 * - analyzeSprintMembership(): détection ajout/retrait depuis le changelog « Sprint »
 * - toSnapshot()/fromSnapshot() : fidélité du round-trip de cache
 *
 * On instancie Sprint sans constructeur (pas d'appel Jira) et on injecte
 * directement l'état nécessaire par réflexion, comme SprintReconstructionTest.
 */
class SprintMetricsTest extends TestCase
{
    private FakeConfig $config;
    private IssueBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config  = new FakeConfig();
        $this->builder = new IssueBuilder($this->config);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeSprint(string $sprintId = '100', ?string $startDate = null): Sprint
    {
        $sprint = (new ReflectionClass(Sprint::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(Sprint::class, 'sprintId'))->setValue($sprint, $sprintId);
        (new ReflectionProperty(Sprint::class, 'config'))->setValue($sprint, $this->config);
        // timeline est requis par les reconstructions à une date (Timeline::getChangeHistory).
        (new ReflectionProperty(Sprint::class, 'timeline'))->setValue($sprint, new Timeline($this->config));
        if ($startDate !== null) {
            (new ReflectionProperty(Sprint::class, 'startDateTime'))->setValue($sprint, new DateTime($startDate));
        }

        return $sprint;
    }

    private function call(Sprint $sprint, string $method, array $args = []): mixed
    {
        return (new ReflectionMethod(Sprint::class, $method))->invokeArgs($sprint, $args);
    }

    /**
     * Crée le constructeur de tickets (API publique de reconstruction, extraite de Sprint).
     */
    private function makeBuilder(string $sprintId = '100', ?string $startDate = null): SprintTicketBuilder
    {
        return new SprintTicketBuilder(
            $this->config,
            new Timeline($this->config),
            $sprintId,
            'Sprint',
            $startDate !== null ? new DateTime($startDate) : null
        );
    }

    /**
     * Construit un ticket minimal au format attendu par computeStats/Predictability.
     *
     * @param string[] $labels
     */
    private function ticket(
        float $storyPoints,
        bool $isDone = false,
        bool $addedDuringSprint = false,
        array $labels = [],
        string $key = 'T-1',
        ?float $spRemaining = null
    ): SprintTicketDto {
        return SprintTicketDto::fromArray([
            'key'                 => $key,
            'storyPoints'         => $storyPoints,
            // Null = clé absente pour fromArray → « Reste à faire » non renseigné.
            'spRemaining'         => $spRemaining,
            'addedDuringSprint'   => $addedDuringSprint,
            'removedDuringSprint' => false,
            'isDone'              => $isDone,
            'estimatedAfterAdd'   => false,
            'labels'              => $labels,
            'parentKey'           => null,
        ]);
    }

    private function predictabilityFor(array $tickets): array
    {
        $sprint = $this->makeSprint();
        (new ReflectionProperty(Sprint::class, 'tickets'))->setValue($sprint, $tickets);
        $this->call($sprint, 'computePredictability');

        return $sprint->getPredictability();
    }

    private function statsFor(array $tickets): array
    {
        $sprint = $this->makeSprint();
        (new ReflectionProperty(Sprint::class, 'tickets'))->setValue($sprint, $tickets);
        $this->call($sprint, 'computeStats');

        return $sprint->getStats();
    }

    // =====================================================================
    // computePredictability()
    // =====================================================================

    #[Test]
    public function test_predictability_calculeAchievedEtEngagement(): void
    {
        // Planifiés : 10 (done) + 10 (non done) = 20 SP au démarrage, 2 sujets.
        // Ajouté : 5 SP (done) — compte dans le réalisé SP mais pas dans l'engagement.
        $p = $this->predictabilityFor([
            $this->ticket(10.0, isDone: true, addedDuringSprint: false, key: 'A'),
            $this->ticket(10.0, isDone: false, addedDuringSprint: false, key: 'B'),
            $this->ticket(5.0, isDone: true, addedDuringSprint: true, key: 'C'),
        ]);

        $this->assertSame(20.0, $p['startStoryPoints']);
        $this->assertSame(15.0, $p['completedStoryPoints']);   // 10 + 5
        $this->assertSame(2, $p['startTicketCount']);
        $this->assertSame(1, $p['completedFromStartTicketCount']); // seul A est planifié ET done
        $this->assertSame(75.0, $p['achievedPercent']);        // 15 / 20
        $this->assertSame(50.0, $p['engagementPercent']);      // 1 / 2
        $this->assertSame(80, $p['targetPercent']);
        $this->assertSame(16.0, $p['targetStoryPoints']);      // 20 × 80 %
        $this->assertFalse($p['isReached']);                   // 75 ≤ 80
        $this->assertFalse($p['isEngagementReached']);
    }

    #[Test]
    public function test_predictability_atteinteAuDessusDeLaCible(): void
    {
        // 9 SP terminés sur 10 planifiés = 90 % > 80 % → atteinte.
        $p = $this->predictabilityFor([
            $this->ticket(9.0, isDone: true, key: 'A'),
            $this->ticket(1.0, isDone: false, key: 'B'),
        ]);

        $this->assertSame(90.0, $p['achievedPercent']);
        $this->assertTrue($p['isReached']);
    }

    #[Test]
    public function test_predictability_sprintVide_pasDeDivisionParZero(): void
    {
        // Aucun ticket → aucune division par zéro, pourcentages à 0.
        $p = $this->predictabilityFor([]);

        $this->assertSame(0.0, $p['achievedPercent']);
        $this->assertSame(0.0, $p['engagementPercent']);
        $this->assertSame(0.0, $p['startStoryPoints']);
        $this->assertFalse($p['isReached']);
    }

    // =====================================================================
    // computeStats() : fourchette SP, non-estimés, done / reliquat
    // =====================================================================

    #[Test]
    public function test_stats_fourchetteSp_ignoreNullEtZero(): void
    {
        // SP retenus pour la fourchette : strictement positifs → [2, 5, 8].
        // null = non estimé ; 0 = estimation valide mais hors fourchette.
        $stats = $this->statsFor([
            $this->ticket(2.0, key: 'A'),
            $this->ticket(5.0, key: 'B'),
            $this->ticket(8.0, key: 'C'),
            $this->ticket(0.0, key: 'D'),
            $this->mkNullSpTicket('E'),
        ]);

        $range = $stats['storyPointsRange'];
        $this->assertSame(2.0, $range['min']);
        $this->assertSame(8.0, $range['max']);
        $this->assertSame(5.0, $range['median']);              // médiane de [2,5,8]
        $this->assertSame(5.0, $range['average']);             // (2+5+8)/3
        $this->assertSame(15.0, $stats['totalStoryPoints']);   // 2+5+8+0
        $this->assertSame(1, $stats['issuesWithoutStoryPoints']); // seul le null
    }

    #[Test]
    public function test_stats_totauxDoneEtReliquat(): void
    {
        $stats = $this->statsFor([
            $this->ticket(5.0, isDone: true, key: 'A'),
            $this->ticket(3.0, isDone: false, key: 'B'),
            $this->ticket(2.0, isDone: true, key: 'C'),
        ]);

        $this->assertSame(2, $stats['doneTickets']['count']);
        $this->assertSame(7.0, $stats['doneTickets']['storyPoints']);
        $this->assertSame(1, $stats['reliquat']['count']);
        $this->assertSame(3.0, $stats['reliquat']['storyPoints']);
    }

    #[Test]
    public function test_stats_resteAFaire_valeursNonNullesUniquement(): void
    {
        // Reste à faire par ticket : uniquement les valeurs renseignées du champ dédié
        // (0 compris). Pas de repli sur les Story Points quand le champ est vide.
        // A : SP 5, champ absent       → 0 (ne contribue pas)
        // B : SP 3, reste à faire 1    → 1
        // C : SP 3, reste à faire 0    → 0 (0 est une valeur renseignée)
        $a = $this->ticket(5.0, key: 'A');                       // champ non renseigné
        $b = $this->ticket(3.0, key: 'B', spRemaining: 1.0);
        $c = $this->ticket(3.0, key: 'C', spRemaining: 0.0);

        $stats = $this->statsFor([$a, $b, $c]);

        $this->assertSame(1.0, $stats['totalRemainingStoryPoints']); // 0 + 1 + 0
        $this->assertSame(11.0, $stats['totalStoryPoints']);         // 5 + 3 + 3
    }

    /**
     * Ticket non estimé (storyPoints null) — le helper ticket() impose un float.
     */
    private function mkNullSpTicket(string $key): SprintTicketDto
    {
        // Clé storyPoints absente → fromArray restitue null (non estimé).
        return SprintTicketDto::fromArray(['key' => $key]);
    }

    // =====================================================================
    // analyzeSprintMembership() : ajout / retrait depuis le changelog
    // =====================================================================

    #[Test]
    public function test_membership_ajoutApresDemarrage_detecteAdded(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100') // entrée après démarrage
            ->build();

        $result = $sprint->analyzeSprintMembership($issue, new DateTime('2025-03-15T00:00:00+00:00'));

        $this->assertTrue($result['added']);
        $this->assertFalse($result['removed']);
    }

    #[Test]
    public function test_membership_retraitApresDemarrage_detecteRemoved(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withSprintChange('2025-03-08T08:00:00+00:00', '100', '') // sortie après démarrage
            ->build();

        $result = $sprint->analyzeSprintMembership($issue, new DateTime('2025-03-15T00:00:00+00:00'));

        $this->assertTrue($result['removed']);
        $this->assertFalse($result['added']);
    }

    #[Test]
    public function test_membership_mouvementApresDateReference_ignore(): void
    {
        // Ticket retiré APRÈS la date de référence : à l'instant observé il était encore dans le sprint,
        // le retrait ne doit donc pas compter comme un changement de périmètre.
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withSprintChange('2025-03-20T08:00:00+00:00', '100', '')
            ->build();

        $result = $sprint->analyzeSprintMembership($issue, new DateTime('2025-03-15T00:00:00+00:00'));

        $this->assertFalse($result['removed']);
        $this->assertFalse($result['added']);
    }

    #[Test]
    public function test_membership_entreeAvantDemarrage_pasDeScopeChange(): void
    {
        // Entrée AVANT la date de démarrage → fait partie du périmètre initial,
        // ne compte donc pas comme ajout en cours de sprint.
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withSprintChange('2025-02-20T08:00:00+00:00', '', '100')
            ->build();

        $result = $sprint->analyzeSprintMembership($issue, new DateTime('2025-03-15T00:00:00+00:00'));

        $this->assertFalse($result['added']);
        $this->assertFalse($result['removed']);
    }

    #[Test]
    public function test_membership_sprintFutur_sansDateDemarrage_retourneFalse(): void
    {
        // Pas de startDateTime → aucun changement de périmètre calculable.
        $sprint = $this->makeBuilder('100'); // sans date de démarrage
        $issue  = $this->builder
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100')
            ->build();

        $result = $sprint->analyzeSprintMembership($issue, new DateTime('2025-03-15T00:00:00+00:00'));

        $this->assertFalse($result['added']);
        $this->assertFalse($result['removed']);
    }

    // =====================================================================
    // toSnapshot() / fromSnapshot() : fidélité du round-trip
    // =====================================================================

    #[Test]
    public function test_snapshot_roundTrip_preserveLesChamps(): void
    {
        // Ordre des clés identique à Sprint::toSnapshot() pour un assertSame strict.
        $snapshot = [
            'sprintId'        => '100',
            'projectKey'      => 'ABC',
            'boardId'         => '7',
            'asOfDate'          => null,
            'reintegratedCount' => 0,
            'sprintData'      => ['id' => 100, 'name' => 'Sprint 1', 'state' => 'closed'],
            'issuesCount'     => 12,
            'landingByStatus' => [['status' => 'Done', 'count' => 5]],
            'objectiveGroups' => [],
            'features'        => [['key' => 'ABC-1', 'summary' => 'Feature A']],
            'stats'           => ['totalStoryPoints' => 30.0],
            'predictability'  => ['achievedPercent' => 75.0],
            'ticketAgeStats'  => ['average' => 6.5, 'median' => 5.0],
            'totalTimeSpentInWaiting'   => 4.75,
            'averageTimeSpentInWaiting' => 0.4,
            'aggregatedWaitingTimes'    => ['Bloqué' => 3.0, 'En attente client' => 1.75],
            // Pendants « cycle de vie complet » : ici supérieurs, le ticket ayant attendu avant ce sprint.
            'totalTimeSpentInWaitingFull'   => 12.0,
            'averageTimeSpentInWaitingFull' => 1.0,
            'aggregatedWaitingTimesFull'    => ['Bloqué' => 9.0, 'En attente client' => 3.0],
        ];

        $sprint = Sprint::fromSnapshot($snapshot);

        // Round-trip strict : re-sérialiser doit redonner exactement l'entrée.
        $this->assertSame($snapshot, $sprint->toSnapshot());

        // Et les getters exposent bien les valeurs restaurées.
        $this->assertSame('100', $sprint->getSprintId());
        $this->assertSame(30.0, $sprint->getStats()['totalStoryPoints']);
        $this->assertSame(75.0, $sprint->getPredictability()['achievedPercent']);
        $this->assertSame(5.0, $sprint->getTicketAgeStats()['median']);
        // Régression : l'attente doit survivre au cache (bug « waiting times qui ne remontaient pas »).
        $this->assertSame(4.75, $sprint->getTotalTimeSpentInWaiting());
        $this->assertSame(['Bloqué' => 3.0, 'En attente client' => 1.75], $sprint->getAggregatedWaitingTimes());
        // Les deux définitions cohabitent et restent distinctes après passage par le cache.
        $this->assertSame(12.0, $sprint->getTotalTimeSpentInWaitingFull());
        $this->assertSame(['Bloqué' => 9.0, 'En attente client' => 3.0], $sprint->getAggregatedWaitingTimesFull());
    }

    #[Test]
    public function test_snapshot_fromSnapshot_toleranteAuxChampsManquants(): void
    {
        // Snapshot partiel (ancien format) → valeurs par défaut, pas d'erreur.
        $sprint = Sprint::fromSnapshot(['sprintId' => '42']);

        $this->assertSame('42', $sprint->getSprintId());
        $this->assertSame([], $sprint->getStats());
        $this->assertSame([], $sprint->getPredictability());
    }

    // =====================================================================
    // buildStats() : fusion des tickets pontés (Sprint::fetchPuntedTickets())
    // =====================================================================

    #[Test]
    public function test_buildStats_ticketsPontesSupplementaires_comptesUniquementDansRemoved(): void
    {
        $tickets = [
            $this->ticket(5.0, isDone: true, key: 'A'),
            $this->ticket(3.0, isDone: false, key: 'B'),
        ];
        // isDone: true à dessein — si le ticket ponté fuitait dans $tickets, doneTickets
        // compterait 2 (A + le ponté) au lieu de 1 (seul A est done parmi $tickets).
        $ponte = $this->ticket(1.5, isDone: true, key: 'GCS-840');

        $stats = (new SprintMetricsCalculator())->buildStats($tickets, [$ponte]);

        $this->assertSame(1, $stats['removedDuringSprint']['count']);
        $this->assertSame(1.5, $stats['removedDuringSprint']['storyPoints']);
        $this->assertSame(['GCS-840'], array_column($stats['removedDuringSprint']['issues'], 'key'));

        // Le ticket ponté n'alimente ni les totaux SP globaux ni les autres buckets :
        // il a quitté le périmètre du sprint, seul son retrait est réel.
        $this->assertSame(8.0, $stats['totalStoryPoints']);       // 5 + 3, sans le 1.5 ponté
        $this->assertSame(1, $stats['doneTickets']['count']);
        $this->assertSame(0, $stats['addedDuringSprint']['count']);
    }

    #[Test]
    public function test_buildStats_continuousImprovement_inclutLabelEtTypesInnovationAmelioration(): void
    {
        // Retenus : étiquette amelio_continue OU type « Innovation » / « Amélioration continue » (casse indifférente).
        $tickets = [
            SprintTicketDto::fromArray(['key' => 'LBL', 'labels' => ['amelio_continue']]),
            SprintTicketDto::fromArray(['key' => 'INNO', 'issueType' => 'Innovation']),
            SprintTicketDto::fromArray(['key' => 'AMEL', 'issueType' => 'Amélioration Continue']),
            SprintTicketDto::fromArray(['key' => 'BOTH', 'labels' => ['amelio_continue'], 'issueType' => 'Innovation']),
            SprintTicketDto::fromArray(['key' => 'OUT', 'issueType' => 'Story', 'labels' => ['autre']]),
        ];

        $stats = (new SprintMetricsCalculator())->buildStats($tickets);
        $keys  = array_column($stats['continuousImprovement'], 'key');

        // BOTH ne figure qu'une fois (pas de doublon label + type) ; OUT est exclu.
        $this->assertSame(['LBL', 'INNO', 'AMEL', 'BOTH'], $keys);
    }

    #[Test]
    public function test_buildStats_sansTicketsPontes_removedDuringSprintVide(): void
    {
        $stats = (new SprintMetricsCalculator())->buildStats([$this->ticket(5.0, key: 'A')]);

        $this->assertSame(0, $stats['removedDuringSprint']['count']);
        $this->assertSame(0.0, $stats['removedDuringSprint']['storyPoints']);
    }
}
