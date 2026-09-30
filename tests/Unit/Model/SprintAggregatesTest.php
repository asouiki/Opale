<?php

namespace Tests\Unit\Model;

use App\Dto\SprintTicketDto;
use App\Model\Sprint;
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
 * Tests de caractérisation des agrégats de Sprint encore non couverts, en vue du futur éclatement de
 * la god-method computeStats (refactor à comportement constant) :
 * - computeLandingByStatus()          : atterrissage (count + SP + temps) par statut, trié workflow
 * - computeObjectiveGroups()          : regroupement des tickets par étiquette « objectif »
 * - computeSprintBoundedTimeByStatus(): temps par statut borné à la fenêtre [démarrage → fin] du sprint
 * - computeTicketAgeStats()           : âge moyen / médian des tickets
 *
 * Sprint est instancié sans constructeur (aucun appel Jira) ; l'état nécessaire est injecté par
 * réflexion, comme SprintMetricsTest / SprintReconstructionTest.
 */
class SprintAggregatesTest extends TestCase
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
    // buildTickets()
    // =====================================================================

    #[Test]
    public function test_buildTickets_reconstruitLEtatALaDateDeReference_etAssembleToutesLesCles(): void
    {
        // Workflow avec les DEUX formes (native + normalisée), comme la config de prod : après
        // normalisation du statut reconstruit, catégorie / isDone / rang restent corrects.
        $workflow = [
            'refinement_statuses' => ['To Do'],
            'sprint_statuses'     => ['À faire', 'In Progress', 'En cours'],
            'done_statuses'       => ['Done', 'Terminé(e)', 'Terminé'],
            'waiting_statuses'    => ['En Attente'],
        ];
        $config = new FakeConfig([], $workflow);

        // Sprint clôturé le 15/01 → date de référence = completeDate. Le ticket est « In Progress »
        // à cette date, mais passe « Done » APRÈS (le 20/01) : buildTickets doit refléter l'état AU
        // 15/01 (In Progress, 5 SP), pas l'état live (Done, 8 SP).
        $sprint = (new ReflectionClass(Sprint::class))->newInstanceWithoutConstructor();
        $this->setProp($sprint, 'config', $config);
        $this->setProp($sprint, 'timeline', new Timeline($config));
        $this->setProp($sprint, 'sprintId', '100');
        $this->setProp($sprint, 'startDateTime', new DateTime('2026-01-05T09:00:00+00:00'));
        $this->setProp($sprint, 'sprintData', ['state' => 'closed', 'completeDate' => '2026-01-15T18:00:00+00:00']);

        $issue = (new IssueBuilder($config))
            ->withCreatedDate('2026-01-01T08:00:00+00:00')
            ->withStatus('Done')      // état LIVE
            ->withStoryPoints(8.0)    // SP LIVE
            ->withStatusTransition('2026-01-06T09:00:00+00:00', 'To Do', 'In Progress')
            ->withStoryPointChange('2026-01-06T09:30:00+00:00', null, '5')
            ->withSprintChangeNamed('2026-01-08T09:00:00+00:00', '', '100', '', 'Sprint 100')
            ->withStatusTransition('2026-01-20T09:00:00+00:00', 'In Progress', 'Done') // après clôture
            ->withStoryPointChange('2026-01-20T09:30:00+00:00', '5', '8')              // après clôture
            ->build();
        $this->setProp($sprint, 'issues', [$issue]);

        $this->call($sprint, 'buildTickets');
        $tickets = $this->getProp($sprint, 'tickets');

        $this->assertCount(1, $tickets);
        $this->assertInstanceOf(SprintTicketDto::class, $tickets[0]);
        // Contrat de sortie (vues / phtml / snapshot JSON) : on caractérise la forme array restituée.
        $ticket = $tickets[0]->toArray();

        // Assemblage complet : jeu de clés figé (toute clé retirée/renommée par un refacto casse ici).
        $this->assertSame([
            'key', 'summary', 'url', 'issueType', 'issueTypeIcon', 'status', 'statusCategory',
            'statusRank', 'storyPoints', 'spFinal', 'spRemaining', 'parentKey', 'parentSummary',
            'priority', 'cycleTime', 'leadTime', 'waitingTime', 'waitingTimes',
            'waitingTimeFull', 'waitingTimesFull', 'labels', 'sprintNames',
            'isDone', 'addedDuringSprint', 'removedDuringSprint', 'estimatedAfterAdd',
        ], array_keys($ticket));

        // Reconstruction à la date de référence (et NON l'état live) :
        $this->assertSame('En cours', $ticket['status']);      // In Progress @15/01, normalisé
        $this->assertSame('sprint', $ticket['statusCategory']);
        $this->assertFalse($ticket['isDone']);                 // pas encore Done au 15/01
        $this->assertSame(5.0, $ticket['storyPoints']);        // 5 @15/01, pas le live 8
        $this->assertIsInt($ticket['statusRank']);

        // Appartenance au sprint + champ sprintNames assemblé et peuplé (contenu exact = ressort de
        // sprintAssignmentNames, couvert par ailleurs).
        $this->assertTrue($ticket['addedDuringSprint']);
        $this->assertIsArray($ticket['sprintNames']);
        $this->assertNotEmpty($ticket['sprintNames']);

        // Passe-plats de base.
        $this->assertSame($issue->getKey(), $ticket['key']);
        $this->assertArrayHasKey('name', $ticket['priority']);
    }

    // =====================================================================
    // computeLandingByStatus()
    // =====================================================================

    #[Test]
    public function test_computeLandingByStatus_agregeCountSpEtTemps_trieSelonWorkflow(): void
    {
        $sprint = $this->makeSprint();
        $this->setProp($sprint, 'issuesCount', 3);
        $this->setProp($sprint, 'tickets', [
            SprintTicketDto::fromArray(['status' => 'Done', 'statusCategory' => 'done', 'storyPoints' => 5.0]),
            SprintTicketDto::fromArray(['status' => 'Done', 'statusCategory' => 'done', 'storyPoints' => 3.0]),
            SprintTicketDto::fromArray(['status' => 'In Progress', 'statusCategory' => 'sprint', 'storyPoints' => 2.0]),
        ]);
        // 'À faire' : statut traversé pendant le sprint mais sans ticket à date → ligne count 0.
        $this->setProp($sprint, 'sprintBoundedTimeByStatus', [
            'Done'        => 6.0,
            'In Progress' => 4.0,
            'À faire'     => 2.0,
        ]);

        $this->call($sprint, 'computeLandingByStatus');
        $landing = $sprint->getLandingByStatus();

        // Ordre = workflow (To Do, À faire, In Progress, Done) : To Do absent.
        $this->assertSame(['À faire', 'In Progress', 'Done'], array_column($landing, 'status'));

        // À faire : historique sans ticket → count 0, catégorie résolue via le workflow, moy = 2/3.
        $this->assertSame(0, $landing[0]['count']);
        $this->assertSame('sprint', $landing[0]['category']);
        $this->assertSame(2.0, $landing[0]['totalDays']);
        $this->assertSame(0.7, $landing[0]['averageDays']);

        // In Progress : 1 ticket, 2 SP, 4 j / 3 issues = 1.3.
        $this->assertSame(1, $landing[1]['count']);
        $this->assertSame(2.0, $landing[1]['storyPoints']);
        $this->assertSame(1.3, $landing[1]['averageDays']);

        // Done : 2 tickets cumulés, 8 SP, 6 j / 3 = 2.0.
        $this->assertSame(2, $landing[2]['count']);
        $this->assertSame(8.0, $landing[2]['storyPoints']);
        $this->assertSame(6.0, $landing[2]['totalDays']);
        $this->assertSame(2.0, $landing[2]['averageDays']);
    }

    // =====================================================================
    // computeObjectiveGroups()
    // =====================================================================

    #[Test]
    public function test_computeObjectiveGroups_regroupeParLabelObjectif_titreDepuisGoal_triParParent(): void
    {
        $sprint = $this->makeSprint(sprintData: ['goal' => "- Alpha\n- Beta"]);
        $this->setProp($sprint, 'tickets', [
            $this->objectiveTicket('A-1', ['objectif_1'], 'Done', 'P-2'),
            $this->objectiveTicket('A-2', ['objectif_1'], 'In Progress', 'P-1'),
            $this->objectiveTicket('A-3', ['objectif_2', 'autre'], 'Done', null),
            // Aucune étiquette « objectif » → exclu de tout groupe.
            $this->objectiveTicket('A-4', ['random'], 'Done', 'P-9'),
        ]);

        $this->call($sprint, 'computeObjectiveGroups');
        $groups = $sprint->getObjectiveGroups();

        // Deux groupes seulement (A-4 ignoré), triés naturellement par label.
        $this->assertCount(2, $groups);
        $this->assertSame('objectif_1', $groups[0]['label']);
        $this->assertSame('Alpha', $groups[0]['title']);          // 1re ligne du goal
        $this->assertSame('objectif_2', $groups[1]['label']);
        $this->assertSame('Beta', $groups[1]['title']);           // 2e ligne du goal

        // Groupe objectif_1 : 2 tickets triés par parentKey (P-1 avant P-2).
        $this->assertCount(2, $groups[0]['issues']);
        $this->assertSame('P-1', $groups[0]['issues'][0]['parentKey']);
        $this->assertSame('A-2', $groups[0]['issues'][0]['key']);

        // Groupe objectif_2 : un seul ticket.
        $this->assertCount(1, $groups[1]['issues']);
        $this->assertSame('A-3', $groups[1]['issues'][0]['key']);
    }

    #[Test]
    public function test_computeObjectiveGroups_goalAbsent_titreDeRepli(): void
    {
        $sprint = $this->makeSprint();
        $this->setProp($sprint, 'tickets', [
            $this->objectiveTicket('A-1', ['objectif_3'], 'Done', null),
        ]);

        $this->call($sprint, 'computeObjectiveGroups');
        $groups = $sprint->getObjectiveGroups();

        // Sans goal exploitable → repli « Objectif N » (N extrait du label).
        $this->assertCount(1, $groups);
        $this->assertSame('Objectif 3', $groups[0]['title']);
    }

    #[Test]
    public function test_computeObjectiveGroups_regroupeAussiEtiquetteGoal(): void
    {
        // Famille « goal » détectée au même titre qu'« objectif » (contains sur le label).
        $sprint = $this->makeSprint(sprintData: ['goal' => "- Alpha\n- Beta"]);
        $this->setProp($sprint, 'tickets', [
            $this->objectiveTicket('A-1', ['goal_1'], 'Done', null),
            $this->objectiveTicket('A-2', ['sprint_goal_2'], 'In Progress', null),
        ]);

        $this->call($sprint, 'computeObjectiveGroups');
        $groups = $sprint->getObjectiveGroups();

        // Tri naturel par label : goal_1 avant sprint_goal_2.
        $this->assertCount(2, $groups);
        $this->assertSame('goal_1', $groups[0]['label']);
        $this->assertSame('Alpha', $groups[0]['title']);           // 1re ligne du goal
        $this->assertSame('sprint_goal_2', $groups[1]['label']);
        $this->assertSame('Beta', $groups[1]['title']);            // 2e ligne du goal
    }

    // =====================================================================
    // resolveSprintEnd() : date de référence des calculs
    // =====================================================================

    #[Test]
    public function test_resolveSprintEnd_sprintCloture_retourneLaDateDeCloture(): void
    {
        $sprint = $this->makeSprint(sprintData: ['state' => 'closed', 'completeDate' => '2026-02-02T18:00:00+00:00']);

        $this->assertSame('2026-02-02T18:00:00+00:00', $sprint->resolveSprintEnd()->format(DateTime::ATOM));
    }

    #[Test]
    public function test_resolveSprintEnd_dateObservation_primeSurLaDateDeCloture(): void
    {
        // Photo « à date » : la date choisie fait foi même sur un sprint clôturé — c'est tout l'objet de la
        // fonctionnalité de rattrapage (reliquat déplacé avant la clôture).
        $sprint = $this->makeSprint(sprintData: ['state' => 'closed', 'completeDate' => '2026-02-02T18:00:00+00:00']);
        $this->setProp($sprint, 'asOfDate', new DateTime('2026-02-01T17:00:00+00:00'));

        $this->assertSame('2026-02-01T17:00:00+00:00', $sprint->resolveSprintEnd()->format(DateTime::ATOM));
    }

    // =====================================================================
    // computeTicketAgeStats()
    // =====================================================================

    #[Test]
    public function test_computeTicketAgeStats_moyenneEtMediane(): void
    {
        $timeline = new Timeline($this->config);
        $sprint   = $this->makeSprint(sprintData: ['state' => 'closed', 'completeDate' => '2026-02-02T18:00:00+00:00']);
        $this->setProp($sprint, 'timeline', $timeline);
        $this->setProp($sprint, 'issues', [
            $this->builder->withCreatedDate('2026-01-05T09:00:00+00:00')->build(),
            $this->builder->withCreatedDate('2026-01-15T09:00:00+00:00')->build(),
        ]);

        $this->call($sprint, 'computeTicketAgeStats');
        $stats = $sprint->getTicketAgeStats();

        // Âge = jours ouvrés création → date de référence (clôturé → completeDate).
        $ref = $sprint->resolveSprintEnd();
        $a1  = $timeline->calculateBusinessDays(new DateTime('2026-01-05T09:00:00+00:00'), $ref);
        $a2  = $timeline->calculateBusinessDays(new DateTime('2026-01-15T09:00:00+00:00'), $ref);

        $this->assertGreaterThan(0.0, $a1);
        $this->assertSame(round(($a1 + $a2) / 2, 1), $stats['average']);
        $this->assertSame(($a1 + $a2) / 2, $stats['median']);
    }

    #[Test]
    public function test_computeTicketAgeStats_sansIssues_retourneZero(): void
    {
        $sprint = $this->makeSprint(sprintData: ['state' => 'closed', 'completeDate' => '2026-02-02T18:00:00+00:00']);
        $this->setProp($sprint, 'timeline', new Timeline($this->config));
        $this->setProp($sprint, 'issues', []);

        $this->call($sprint, 'computeTicketAgeStats');

        $this->assertSame(['average' => 0.0, 'median' => 0.0], $sprint->getTicketAgeStats());
    }

    // =====================================================================
    // computeSprintBoundedTimeByStatus()
    // =====================================================================

    #[Test]
    public function test_computeSprintBoundedTimeByStatus_ventileLaFenetre_sommeEgaleDureeSprint(): void
    {
        $timeline = new Timeline($this->config);
        $sprint   = $this->makeSprint(
            startDate: '2026-01-05T09:00:00+00:00',
            sprintData: ['state' => 'closed', 'completeDate' => '2026-01-12T18:00:00+00:00']
        );
        $this->setProp($sprint, 'timeline', $timeline);
        $this->setProp($sprint, 'issues', [
            $this->builder
                ->withCreatedDate('2026-01-01T08:00:00+00:00')
                ->withStatus('Done')
                ->withStatusTransition('2026-01-06T09:00:00+00:00', 'To Do', 'In Progress')
                ->withStatusTransition('2026-01-09T09:00:00+00:00', 'In Progress', 'Done')
                ->build(),
        ]);

        $this->call($sprint, 'computeSprintBoundedTimeByStatus');
        $byStatus = $this->getProp($sprint, 'sprintBoundedTimeByStatus');

        // La fenêtre est ventilée sur plusieurs statuts, et la somme couvre toute la durée du sprint.
        $this->assertGreaterThanOrEqual(2, count($byStatus));
        $windowDays = $timeline->calculateBusinessDays(
            new DateTime('2026-01-05T09:00:00+00:00'),
            $sprint->resolveSprintEnd()
        );
        $this->assertEqualsWithDelta($windowDays, array_sum($byStatus), 0.5);
    }

    #[Test]
    public function test_computeSprintBoundedTimeByStatus_sansDateDemarrage_neFaitRien(): void
    {
        $sprint = $this->makeSprint(); // pas de startDateTime
        $this->setProp($sprint, 'timeline', new Timeline($this->config));
        $this->setProp($sprint, 'issues', [$this->builder->build()]);

        $this->call($sprint, 'computeSprintBoundedTimeByStatus');

        $this->assertSame([], $this->getProp($sprint, 'sprintBoundedTimeByStatus'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param array<string, mixed> $sprintData
     */
    private function makeSprint(?string $startDate = null, array $sprintData = []): Sprint
    {
        $sprint = (new ReflectionClass(Sprint::class))->newInstanceWithoutConstructor();

        $this->setProp($sprint, 'config', $this->config);
        if ($startDate !== null) {
            $this->setProp($sprint, 'startDateTime', new DateTime($startDate));
        }
        if ($sprintData !== []) {
            $this->setProp($sprint, 'sprintData', $sprintData);
        }

        return $sprint;
    }

    /**
     * Ticket minimal au format attendu par computeObjectiveGroups (labels + statut + parent).
     *
     * @param string[] $labels
     */
    private function objectiveTicket(string $key, array $labels, string $status, ?string $parentKey): SprintTicketDto
    {
        return SprintTicketDto::fromArray([
            'key'       => $key,
            'labels'    => $labels,
            'status'    => $status,
            'parentKey' => $parentKey,
        ]);
    }

    private function call(Sprint $sprint, string $method): mixed
    {
        return (new ReflectionMethod(Sprint::class, $method))->invoke($sprint);
    }

    private function setProp(object $obj, string $prop, mixed $value): void
    {
        (new ReflectionProperty($obj, $prop))->setValue($obj, $value);
    }

    private function getProp(object $obj, string $prop): mixed
    {
        return (new ReflectionProperty($obj, $prop))->getValue($obj);
    }
}
