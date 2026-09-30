<?php

namespace Tests\Unit\Model;

use App\Model\Issue;
use App\Model\Timeline;
use DateTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests unitaires pour le calcul des Waiting Times
 *
 * Couverture :
 * - Issue sans attente
 * - Issue avec labels d'attente uniquement
 * - Issue avec statuts d'attente uniquement
 * - Issue avec labels + statuts (pas de double comptage)
 * - Plusieurs issues simultanées (agrégation Collection)
 */
class WaitingTimeTest extends TestCase
{
    private FakeConfig $config;
    private IssueBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        // Pas de jours fériés → jours ouvrés = jours de semaine uniquement
        $this->config  = new FakeConfig();
        $this->builder = new IssueBuilder($this->config);
    }

    // ==========================================
    // Tests Issue-level : aucune attente
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_returns_zero_when_no_waiting(): void
    {
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00') // lundi
            ->withResolutionDate('2025-10-21T08:00:00+00:00') // mardi
            ->withStatusTransition('2025-10-21T08:00:00+00:00', 'To Do', 'Done')
            ->build();

        $this->assertEquals(0.0, $issue->getTimeSpentInWaiting());
        $this->assertEmpty($issue->getWaitingTimes());
    }

    // ==========================================
    // Tests Issue-level : labels d'attente
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_calculates_waiting_time_from_label_only(): void
    {
        // Lun 08h : label "attente-externe" ajouté
        // Mer 08h : label "attente-externe" retiré (même heure ±30min → jours calendaires = 2 jours)
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')    // lundi
            ->withResolutionDate('2025-10-24T18:00:00+00:00') // vendredi
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->withLabelChange(
                '2025-10-20T08:00:00+00:00',
                '',
                'attente-externe'
            )
            ->withLabelChange(
                '2025-10-22T08:00:00+00:00',
                'attente-externe',
                ''
            )
            ->build();

        // Lun 08h → Mer 08h, même heure → 2 jours calendaires ouvrés (Mar, Mer)
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertArrayHasKey('attente-externe', $issue->getWaitingTimes());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['attente-externe']);
    }

    /**
     * @test
     */
    #[Test]
    public function it_accumulates_same_label_applied_twice(): void
    {
        // Label posé 2 fois
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-27T18:00:00+00:00') // lundi suivant
            ->withStatusTransition('2025-10-27T18:00:00+00:00', 'In Progress', 'Done')
            ->withLabelChange('2025-10-20T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-21T08:00:00+00:00', 'attente-externe', '') // retiré mardi
            ->withLabelChange('2025-10-22T08:00:00+00:00', '', 'attente-externe') // reposé mercredi
            ->withLabelChange('2025-10-23T08:00:00+00:00', 'attente-externe', '') // retiré jeudi
            ->build();

        // 1er passage : Lun→Mar = 1 jour, 2e passage : Mer→Jeu = 1 jour → total 2 jours
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['attente-externe']);
    }

    // ==========================================
    // Tests Issue-level : statuts d'attente
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_calculates_waiting_time_from_status_only(): void
    {
        // To Do → En Attente (lun 08h) → In Progress (mer 08h) → Done (jeu 08h)
        // Lun→Mer même heure → 2 jours
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-23T08:00:00+00:00')
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-10-23T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertArrayHasKey('En Attente', $issue->getWaitingTimes());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['En Attente']);
    }

    // ==========================================
    // Tests Issue-level : labels + statuts — pas de double comptage
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_does_not_double_count_when_label_and_status_both_present(): void
    {
        // Statut "En Attente" : Lun→Mer = 2j
        // Label "attente-externe" : Jeu→Ven = 1j
        // Total attendu : 3j (pas 2+2+1=5)
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T18:00:00+00:00')
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withLabelChange('2025-10-23T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-24T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $waitingTimes = $issue->getWaitingTimes();

        $this->assertArrayHasKey('En Attente', $waitingTimes);
        $this->assertEquals(2.0, $waitingTimes['En Attente'], 'Statut En Attente = 2j');

        $this->assertArrayHasKey('attente-externe', $waitingTimes);
        $this->assertEquals(1.0, $waitingTimes['attente-externe'], 'Label attente-externe = 1j');

        // Total = somme exacte, sans double comptage
        $this->assertEquals(3.0, $issue->getTimeSpentInWaiting(), 'Total waiting = 3j, pas de double comptage');
    }

    /**
     * @test
     */
    #[Test]
    public function it_does_not_double_count_concurrent_label_and_status(): void
    {
        // Label et statut d'attente appliqués SIMULTANÉMENT sur la même période (Lun→Mer).
        // La ventilation garde 2j par cause, mais le TOTAL est dédupliqué (union) = 2j.
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-23T18:00:00+00:00')
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->withLabelChange('2025-10-20T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-22T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'Done')
            ->build();

        $waitingTimes = $issue->getWaitingTimes();

        // Ventilation par cause : chaque badge conserve sa durée réelle (2j).
        $this->assertEquals(2.0, $waitingTimes['En Attente'] ?? 0.0);
        $this->assertEquals(2.0, $waitingTimes['attente-externe'] ?? 0.0);
        // Total dédupliqué : les 2 causes couvrent la MÊME période → union = 2j (pas 4j).
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
    }

    /**
     * @test
     */
    #[Test]
    public function it_deduplicates_partial_overlap_between_flag_and_status(): void
    {
        // Statut « En Attente » Lun→Mer (2j) ; flag posé Mar→Ven (3j).
        // Recouvrement Mar→Mer ; union = Lun→Ven = 4 jours ouvrés.
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')    // lundi
            ->withResolutionDate('2025-10-24T18:00:00+00:00') // vendredi
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withFlagChange('2025-10-21T08:00:00+00:00', '', 'Impediment')
            ->withFlagChange('2025-10-24T08:00:00+00:00', 'Impediment', '')
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $waitingTimes = $issue->getWaitingTimes();
        // Ventilation : durées réelles par cause (statut 2j, flag 3j).
        $this->assertEquals(2.0, $waitingTimes['En Attente'] ?? 0.0);
        $this->assertEquals(3.0, $waitingTimes['Ticket flaggé'] ?? 0.0);
        // Union Lun→Ven = 4j (le recouvrement Mar-Mer n'est pas compté deux fois).
        $this->assertEquals(4.0, $issue->getTimeSpentInWaiting());
    }

    /**
     * @test
     */
    #[Test]
    public function it_sums_disjoint_causes_without_deduplication(): void
    {
        // Flag Lun→Mar (1j) et label attente-externe Jeu→Ven (1j) : périodes DISJOINTES.
        // Union = somme = 2j (non-régression : pas de fusion abusive de périodes séparées).
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T18:00:00+00:00')
            ->withFlagChange('2025-10-20T08:00:00+00:00', '', 'Impediment')
            ->withFlagChange('2025-10-21T08:00:00+00:00', 'Impediment', '')
            ->withLabelChange('2025-10-23T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-24T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
    }

    /**
     * @test
     */
    #[Test]
    public function it_ignores_open_ended_waiting_status_like_the_breakdown(): void
    {
        // Ticket ENCORE dans un statut d'attente en fin d'historique (jamais quitté).
        // buildStatusTimeline n'ajoute pas ce segment final à la ventilation → l'union ne
        // doit pas le compter non plus, sous peine d'un total > somme des causes affichées.
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T18:00:00+00:00')
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->build();

        // Aucune entrée de ventilation pour ce statut → total cohérent = 0.
        $this->assertArrayNotHasKey('En Attente', $issue->getWaitingTimes());
        $this->assertEquals(0.0, $issue->getTimeSpentInWaiting());
    }

    // ==========================================
    // Tests Issue-level : flag Jira (« Flagged »)
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_calculates_waiting_time_from_flag_only(): void
    {
        // Flag posé lun 08h, retiré mer 08h → 2 jours ouvrés (Mar, Mer)
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')    // lundi
            ->withResolutionDate('2025-10-24T18:00:00+00:00') // vendredi
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->withFlagChange('2025-10-20T08:00:00+00:00', '', 'Impediment')
            ->withFlagChange('2025-10-22T08:00:00+00:00', 'Impediment', '')
            ->build();

        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertArrayHasKey('Ticket flaggé', $issue->getWaitingTimes());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['Ticket flaggé']);
    }

    /**
     * @test
     */
    #[Test]
    public function it_calculates_flag_still_active_until_resolution(): void
    {
        // Flag posé lun 08h, jamais retiré → calculé jusqu'à la résolution (mer 08h) = 2 jours
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')    // lundi
            ->withResolutionDate('2025-10-22T08:00:00+00:00') // mercredi
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'In Progress', 'Done')
            ->withFlagChange('2025-10-20T08:00:00+00:00', '', 'Impediment')
            ->build();

        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['Ticket flaggé']);
    }

    /**
     * @test
     */
    #[Test]
    public function it_accumulates_flag_and_label_as_separate_waiting_causes(): void
    {
        // Flag : Lun→Mar = 1j ; Label "attente-externe" : Jeu→Ven = 1j → total 2j, deux entrées distinctes
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T18:00:00+00:00')
            ->withFlagChange('2025-10-20T08:00:00+00:00', '', 'Impediment')
            ->withFlagChange('2025-10-21T08:00:00+00:00', 'Impediment', '')
            ->withLabelChange('2025-10-23T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-24T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-24T18:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $waitingTimes = $issue->getWaitingTimes();

        $this->assertEquals(1.0, $waitingTimes['Ticket flaggé'] ?? 0.0, 'Flag = 1j');
        $this->assertEquals(1.0, $waitingTimes['attente-externe'] ?? 0.0, 'Label = 1j');
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting(), 'Total = 2j');
    }

    // ==========================================
    // Tests Collection-level — agrégation
    // ==========================================

    /**
     * @test
     */
    #[Test]
    public function it_aggregates_waiting_times_across_multiple_issues_without_double_count(): void
    {
        // Issue 1 : 2j de statut "En Attente"
        $issue1 = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-23T08:00:00+00:00')
            ->withStatusTransition('2025-10-20T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-10-23T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        // Issue 2 : 1j de label "attente-externe"
        $issue2 = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-22T08:00:00+00:00')
            ->withLabelChange('2025-10-20T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-21T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(2.0, $issue1->getTimeSpentInWaiting(), 'Issue 1 = 2j');
        $this->assertEquals(1.0, $issue2->getTimeSpentInWaiting(), 'Issue 2 = 1j');

        // Simulation du calcul Collection : additionner les totaux par issue
        $totalWaiting = $issue1->getTimeSpentInWaiting() + $issue2->getTimeSpentInWaiting();
        $this->assertEquals(3.0, $totalWaiting, 'Collection : 2j + 1j = 3j, sans double comptage');
    }

    // ==========================================
    // Tests Issue-level : attente BORNÉE à la fenêtre du sprint (page /sprint)
    // ==========================================

    /**
     * Rejoue le calcul d'attente en le bornant à une fenêtre, comme le fait SprintTicketBuilder
     * pour le sprint analysé.
     */
    private function boundTo(Issue $issue, string $from, string $to): void
    {
        (new Timeline($this->config))->buildWaitingTimes($issue, new DateTime($from), new DateTime($to));
    }

    /**
     * @test
     */
    #[Test]
    public function it_ignores_waiting_entirely_before_the_sprint_window(): void
    {
        // Attente « En Attente » du 6 au 8 octobre, sprint analysé du 20 au 24 octobre.
        $issue = $this->builder
            ->withCreatedDate('2025-10-06T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T08:00:00+00:00')
            ->withStatusTransition('2025-10-06T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-08T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-10-24T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        // Hors fenêtre, cette attente compte bien (comportement des pages VSM).
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());

        $this->boundTo($issue, '2025-10-20T08:00:00+00:00', '2025-10-24T08:00:00+00:00');

        // Bornée au sprint : le ticket n'a pas attendu PENDANT ce sprint.
        $this->assertEquals(0.0, $issue->getTimeSpentInWaiting());
        $this->assertSame([], $issue->getWaitingTimes(), 'Aucune cause d attente sur la periode');
    }

    /**
     * @test
     */
    #[Test]
    public function it_keeps_only_the_in_window_part_of_a_waiting_started_before_the_sprint(): void
    {
        // « En Attente » du 13 au 22 octobre (7 j ouvrés), sprint du 20 au 24 octobre.
        $issue = $this->builder
            ->withCreatedDate('2025-10-13T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T08:00:00+00:00')
            ->withStatusTransition('2025-10-13T08:00:00+00:00', 'To Do', 'En Attente')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-10-24T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(7.0, $issue->getTimeSpentInWaiting(), 'Non borne : 13 au 22 octobre');

        $this->boundTo($issue, '2025-10-20T08:00:00+00:00', '2025-10-24T08:00:00+00:00');

        // Seule la part du 20 au 22 octobre est imputée au sprint, ventilation comprise.
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['En Attente'] ?? 0.0);
    }

    /**
     * @test
     */
    #[Test]
    public function it_truncates_a_waiting_label_still_active_after_the_sprint_end(): void
    {
        // Label d'attente posé le 22 octobre et jamais retiré ; sprint clos le 24 octobre.
        $issue = $this->builder
            ->withCreatedDate('2025-10-20T08:00:00+00:00')
            ->withResolutionDate('2025-10-31T08:00:00+00:00')
            ->withLabelChange('2025-10-22T08:00:00+00:00', '', 'attente-externe')
            ->withStatusTransition('2025-10-31T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(7.0, $issue->getTimeSpentInWaiting(), 'Non borne : 22 au 31 octobre');

        $this->boundTo($issue, '2025-10-20T08:00:00+00:00', '2025-10-24T08:00:00+00:00');

        // Tronquée à la fin du sprint : 22 -> 24 octobre.
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting());
        $this->assertEquals(2.0, $issue->getWaitingTimes()['attente-externe'] ?? 0.0);
    }

    /**
     * @test
     */
    #[Test]
    public function it_still_deduplicates_causes_once_bounded(): void
    {
        // Statut « En Attente » 13 -> 22 octobre et label sur la meme periode : causes concurrentes.
        // Bornees au sprint (20 -> 24), chaque cause vaut 2 j et le TOTAL reste 2 j (union).
        $issue = $this->builder
            ->withCreatedDate('2025-10-13T08:00:00+00:00')
            ->withResolutionDate('2025-10-24T08:00:00+00:00')
            ->withStatusTransition('2025-10-13T08:00:00+00:00', 'To Do', 'En Attente')
            ->withLabelChange('2025-10-13T08:00:00+00:00', '', 'attente-externe')
            ->withLabelChange('2025-10-22T08:00:00+00:00', 'attente-externe', '')
            ->withStatusTransition('2025-10-22T08:00:00+00:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-10-24T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $this->boundTo($issue, '2025-10-20T08:00:00+00:00', '2025-10-24T08:00:00+00:00');

        $waitingTimes = $issue->getWaitingTimes();
        $this->assertEquals(2.0, $waitingTimes['En Attente'] ?? 0.0);
        $this->assertEquals(2.0, $waitingTimes['attente-externe'] ?? 0.0);
        $this->assertEquals(2.0, $issue->getTimeSpentInWaiting(), 'Union des deux causes, pas leur somme');
    }
}
