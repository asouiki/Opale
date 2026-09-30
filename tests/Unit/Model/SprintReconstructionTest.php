<?php

namespace Tests\Unit\Model;

use App\Dto\SprintTicketDto;
use App\Model\Issue;
use App\Model\JiraFields;
use App\Model\Sprint;
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
 * Tests de la reconstruction « à date » des données du sprint depuis le changelog.
 *
 * Couvre les méthodes privées critiques de Sprint :
 * - storyPointsAtDate()    : valeur SP à une date passée
 * - statusAtDate()         : statut à une date passée
 * - labelsAtDate()         : étiquettes à une date passée
 * - wasEstimatedAfterAdd() : ticket estimé après son entrée dans le sprint
 *
 * Plus la normalisation des statuts natifs (Timeline::normalizeNativeStatusName()).
 */
class SprintReconstructionTest extends TestCase
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
    // Helpers : instancier Sprint sans constructeur + appeler ses privées
    // =====================================================================

    /**
     * Crée un Sprint sans déclencher le constructeur (qui appellerait Jira).
     */
    private function makeSprint(string $sprintId = '100', ?string $startDate = null): Sprint
    {
        $sprint = (new ReflectionClass(Sprint::class))->newInstanceWithoutConstructor();

        $this->setPrivate($sprint, 'sprintId', $sprintId);
        // config est requis par sprintAssignmentNames() (lecture des sprints ignorés).
        $this->setPrivate($sprint, 'config', $this->config);
        // timeline est requis par les reconstructions à une date (Timeline::getChangeHistory).
        $this->setPrivate($sprint, 'timeline', new Timeline($this->config));
        if ($startDate !== null) {
            $this->setPrivate($sprint, 'startDateTime', new DateTime($startDate));
        }

        return $sprint;
    }

    /**
     * Crée le constructeur de tickets (API publique de reconstruction à date, extrait de Sprint).
     */
    private function makeBuilder(
        string $sprintId = '100',
        ?string $startDate = null,
        string $sprintName = 'Sprint',
        ?FakeConfig $config = null
    ): SprintTicketBuilder {
        $config ??= $this->config;

        return new SprintTicketBuilder(
            $config,
            new Timeline($config),
            $sprintId,
            $sprintName,
            $startDate !== null ? new DateTime($startDate) : null
        );
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        // Depuis PHP 8.1, les membres privés sont accessibles via réflexion
        // sans setAccessible() (déprécié en 8.5).
        (new ReflectionProperty(Sprint::class, $prop))->setValue($obj, $value);
    }

    private function call(Sprint $sprint, string $method, array $args): mixed
    {
        return (new ReflectionMethod(Sprint::class, $method))->invokeArgs($sprint, $args);
    }

    private function spAtDate(SprintTicketBuilder $builder, Issue $issue, string $at, string $fieldId = JiraFields::STORY_POINTS): ?float
    {
        return $builder->storyPointsAtDate($issue, $fieldId, new DateTime($at));
    }

    // =====================================================================
    // storyPointsAtDate()
    // =====================================================================

    #[Test]
    public function test_storyPointsAtDate_sansChangement_retourneValeurCourante(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder->withStoryPoints(5.0)->build();

        $this->assertSame(5.0, $this->spAtDate($sprint, $issue, '2025-06-01T00:00:00+00:00'));
    }

    #[Test]
    public function test_storyPointsAtDate_avantPremiereEstimation_retourneNull(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStoryPoints(5.0)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '5')
            ->build();

        // À une date antérieure à l'estimation : non estimé → null
        $this->assertNull($this->spAtDate($sprint, $issue, '2025-03-01T00:00:00+00:00'));
    }

    #[Test]
    public function test_storyPointsAtDate_entreDeuxChangements_retourneValeurIntermediaire(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStoryPoints(8.0)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '5')
            ->withStoryPointChange('2025-03-20T08:00:00+00:00', '5', '8')
            ->build();

        $this->assertSame(5.0, $this->spAtDate($sprint, $issue, '2025-03-15T00:00:00+00:00'));
        $this->assertSame(8.0, $this->spAtDate($sprint, $issue, '2025-03-25T00:00:00+00:00'));
    }

    #[Test]
    public function test_storyPointsAtDate_zeroEstUneEstimationValide(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStoryPoints(0.0)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '0')
            ->build();

        // 0 doit être conservé (≠ null « non estimé »)
        $this->assertSame(0.0, $this->spAtDate($sprint, $issue, '2025-03-15T00:00:00+00:00'));
    }

    #[Test]
    public function test_storyPointsAtDate_tolereLaVirguleDecimale(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStoryPoints(2.5)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '2,5')
            ->build();

        $this->assertSame(2.5, $this->spAtDate($sprint, $issue, '2025-03-15T00:00:00+00:00'));
    }

    #[Test]
    public function test_storyPointsAtDate_champResteAFaire_valeurFigeeALaCloture(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withSpRemaining(3.0) // valeur live actuelle (potentiellement modifiée après coup)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '2', JiraFields::STORY_POINTS_REMAINING)
            ->build();

        // À la date de clôture, seule la transition null → 2 est connue.
        $this->assertSame(
            2.0,
            $this->spAtDate($sprint, $issue, '2025-03-20T00:00:00+00:00', JiraFields::STORY_POINTS_REMAINING)
        );
    }

    #[Test]
    public function test_storyPointsAtDate_champResteAFaire_transitionApresClotureIgnoree(): void
    {
        // Reproduit le cas rapporté : ticket démarré en sprint A, non terminé, sprint A clos le
        // 20/03. Le champ « Reste à faire » n'est renseigné qu'après (25/03), pour le sprint B.
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withSpRemaining(5.0) // valeur live actuelle (renseignée pour le sprint B)
            ->withStoryPointChange('2025-03-25T08:00:00+00:00', null, '5', JiraFields::STORY_POINTS_REMAINING)
            ->build();

        // À la date de clôture de A (avant la transition du 25/03) : jamais renseigné → null.
        $this->assertNull(
            $this->spAtDate($sprint, $issue, '2025-03-20T00:00:00+00:00', JiraFields::STORY_POINTS_REMAINING)
        );
    }

    #[Test]
    public function test_storyPointsAtDate_champResteAFaire_sansChangement_retourneValeurCourante(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder->withSpRemaining(1.5)->build();

        $this->assertSame(
            1.5,
            $this->spAtDate($sprint, $issue, '2025-06-01T00:00:00+00:00', JiraFields::STORY_POINTS_REMAINING)
        );
    }

    #[Test]
    public function test_storyPointsAtDate_champSpFinal_valeurFigeeALaCloture(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withSpFinal(8.0) // valeur live actuelle (potentiellement modifiée après coup)
            ->withStoryPointChange('2025-03-10T08:00:00+00:00', null, '5', JiraFields::STORY_POINTS_FINAL)
            ->build();

        $this->assertSame(
            5.0,
            $this->spAtDate($sprint, $issue, '2025-03-20T00:00:00+00:00', JiraFields::STORY_POINTS_FINAL)
        );
    }

    // =====================================================================
    // statusAtDate()
    // =====================================================================

    #[Test]
    public function test_statusAtDate_avantPremiereTransition_retourneStatutInitial(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStatus('Done')
            ->withStatusTransition('2025-03-10T08:00:00+00:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-03-20T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $status = $sprint->statusAtDate($issue, new DateTime('2025-03-01T00:00:00+00:00'));
        $this->assertSame('To Do', $status);
    }

    #[Test]
    public function test_statusAtDate_entreTransitions_retourneStatutIntermediaire(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStatus('Done')
            ->withStatusTransition('2025-03-10T08:00:00+00:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-03-20T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $status = $sprint->statusAtDate($issue, new DateTime('2025-03-15T00:00:00+00:00'));
        $this->assertSame('In Progress', $status);
    }

    #[Test]
    public function test_statusAtDate_apresToutesLesTransitions_retourneStatutFinal(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withStatus('Done')
            ->withStatusTransition('2025-03-10T08:00:00+00:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-03-20T08:00:00+00:00', 'In Progress', 'Done')
            ->build();

        $status = $sprint->statusAtDate($issue, new DateTime('2025-03-25T00:00:00+00:00'));
        $this->assertSame('Done', $status);
    }

    #[Test]
    public function test_statusAtDate_sansTransition_retourneStatutCourant(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder->withStatus('In Progress')->build();

        $status = $sprint->statusAtDate($issue, new DateTime('2025-03-25T00:00:00+00:00'));
        $this->assertSame('In Progress', $status);
    }

    // =====================================================================
    // labelsAtDate()
    // =====================================================================

    #[Test]
    public function test_labelsAtDate_avantAjout_retourneListeInitiale(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withLabels(['objectif_1', 'nouvelle_prio'])
            ->withLabelChange('2025-03-10T08:00:00+00:00', '', 'objectif_1')
            ->withLabelChange('2025-03-20T08:00:00+00:00', 'objectif_1', 'objectif_1 nouvelle_prio')
            ->build();

        $labels = $sprint->labelsAtDate($issue, new DateTime('2025-03-01T00:00:00+00:00'));
        $this->assertSame([], $labels);
    }

    #[Test]
    public function test_labelsAtDate_entreChangements_retourneEtiquettesIntermediaires(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder
            ->withLabels(['objectif_1', 'nouvelle_prio'])
            ->withLabelChange('2025-03-10T08:00:00+00:00', '', 'objectif_1')
            ->withLabelChange('2025-03-20T08:00:00+00:00', 'objectif_1', 'objectif_1 nouvelle_prio')
            ->build();

        $labels = $sprint->labelsAtDate($issue, new DateTime('2025-03-15T00:00:00+00:00'));
        $this->assertSame(['objectif_1'], $labels);

        $labelsAfter = $sprint->labelsAtDate($issue, new DateTime('2025-03-25T00:00:00+00:00'));
        $this->assertSame(['objectif_1', 'nouvelle_prio'], $labelsAfter);
    }

    #[Test]
    public function test_labelsAtDate_sansChangement_retourneEtiquettesCourantes(): void
    {
        $sprint = $this->makeBuilder();
        $issue  = $this->builder->withLabels(['amelio_continue'])->build();

        $labels = $sprint->labelsAtDate($issue, new DateTime('2025-03-25T00:00:00+00:00'));
        $this->assertSame(['amelio_continue'], $labels);
    }

    // =====================================================================
    // wasEstimatedAfterAdd()
    // =====================================================================

    #[Test]
    public function test_wasEstimatedAfterAdd_nonEstimeALEntreePuisEstime_retourneTrue(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withStoryPoints(5.0)
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100')   // entrée sans SP
            ->withStoryPointChange('2025-03-12T08:00:00+00:00', null, '5') // estimé après
            ->build();

        $result = $sprint->wasEstimatedAfterAdd($issue, new DateTime('2025-03-20T00:00:00+00:00'));
        $this->assertTrue($result);
    }

    #[Test]
    public function test_wasEstimatedAfterAdd_dejaEstimeALEntree_retourneFalse(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withStoryPoints(5.0)
            ->withStoryPointChange('2025-03-02T08:00:00+00:00', null, '5')  // estimé AVANT l'entrée
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100')      // entrée déjà estimée
            ->build();

        $result = $sprint->wasEstimatedAfterAdd($issue, new DateTime('2025-03-20T00:00:00+00:00'));
        $this->assertFalse($result);
    }

    #[Test]
    public function test_wasEstimatedAfterAdd_toujoursNonEstimeADate_retourneFalse(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withStoryPoints(null)
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100') // entrée sans SP, jamais estimé
            ->build();

        $result = $sprint->wasEstimatedAfterAdd($issue, new DateTime('2025-03-20T00:00:00+00:00'));
        $this->assertFalse($result);
    }

    #[Test]
    public function test_wasEstimatedAfterAdd_estimeAZeroApresEntree_retourneTrue(): void
    {
        $sprint = $this->makeBuilder('100', '2025-03-01T00:00:00+00:00');
        $issue  = $this->builder
            ->withStoryPoints(0.0)
            ->withSprintChange('2025-03-05T08:00:00+00:00', '', '100')
            ->withStoryPointChange('2025-03-12T08:00:00+00:00', null, '0') // 0 = estimation valide
            ->build();

        $result = $sprint->wasEstimatedAfterAdd($issue, new DateTime('2025-03-20T00:00:00+00:00'));
        $this->assertTrue($result);
    }

    // =====================================================================
    // Timeline::normalizeNativeStatusName()
    // =====================================================================

    #[Test]
    public function test_normalizeNativeStatusName_traduitLesStatutsNatifs(): void
    {
        $timeline = new Timeline($this->config);

        $this->assertSame('Terminé(e)', $timeline->normalizeNativeStatusName('Done'));
        $this->assertSame('Terminé(e)', $timeline->normalizeNativeStatusName('Terminé'));
        $this->assertSame('À faire', $timeline->normalizeNativeStatusName('To Do'));
        $this->assertSame('En cours', $timeline->normalizeNativeStatusName('In Progress'));
    }

    #[Test]
    public function test_normalizeNativeStatusName_laisseInchangeLesAutresStatuts(): void
    {
        $timeline = new Timeline($this->config);

        $this->assertSame('Bloqué', $timeline->normalizeNativeStatusName('Bloqué'));
        $this->assertSame('En Test', $timeline->normalizeNativeStatusName('En Test'));
    }

    // =====================================================================
    // Évolution du périmètre : % d'augmentation des points (scopeChange)
    // =====================================================================

    /**
     * Construit un ticket minimal pour alimenter computeStats().
     *
     * @param string[] $labels
     */
    private function ticket(float $storyPoints, bool $addedDuringSprint, array $labels = []): SprintTicketDto
    {
        return SprintTicketDto::fromArray([
            'storyPoints'         => $storyPoints,
            'addedDuringSprint'   => $addedDuringSprint,
            'removedDuringSprint' => false,
            'isDone'              => false,
            'estimatedAfterAdd'   => false,
            'labels'              => $labels,
            'parentKey'           => null,
        ]);
    }

    /**
     * Instancie un Sprint avec une liste de tickets et déclenche computeStats().
     */
    private function statsFor(array $tickets): array
    {
        $sprint = $this->makeSprint();
        $this->setPrivate($sprint, 'tickets', $tickets);
        $this->call($sprint, 'computeStats', []);

        return $sprint->getStats();
    }

    #[Test]
    public function test_scopeChange_pourcentageAugmentation_exempleNominal(): void
    {
        // Planifié = 10 pts (T1 non ajouté), ajouté = 4 pts (T2 nouvelle_prio)
        // → total 14, +4 pts = +40%
        $stats = $this->statsFor([
            $this->ticket(10.0, false),
            $this->ticket(4.0, true, ['nouvelle_prio']),
        ]);

        $scope = $stats['scopeChange'];
        $this->assertSame(40.0, $scope['storyPointsGrowthPercent']);
        $this->assertSame(40.0, $scope['addedStoryPointsPercent']);
        $this->assertSame(40.0, $scope['newPrioStoryPointsPercent']);
        // Part sur le total à date : 4 / 14 × 100 = 28.6 %
        $this->assertSame(28.6, $scope['newPrioTotalStoryPointsPercent']);
        // En nombre de tickets : 1 planifié, 2 à date, 1 nouvelle_prio
        // → 1/1 = 100 % du planifié, 1/2 = 50 % du total.
        $this->assertSame(100.0, $scope['newPrioIssuesPercent']);
        $this->assertSame(50.0, $scope['newPrioTotalIssuesPercent']);
    }

    #[Test]
    public function test_scopeChange_pourcentageNul_quandAucunAjout(): void
    {
        $stats = $this->statsFor([
            $this->ticket(8.0, false),
            $this->ticket(5.0, false),
        ]);

        $scope = $stats['scopeChange'];
        $this->assertSame(0.0, $scope['storyPointsGrowthPercent']);
        $this->assertSame(0.0, $scope['addedStoryPointsPercent']);
    }

    #[Test]
    public function test_scopeChange_pourcentageNull_quandAucunPointPlanifie(): void
    {
        // Aucun point planifié → division impossible → null
        $stats = $this->statsFor([
            $this->ticket(5.0, true),
        ]);

        $this->assertNull($stats['scopeChange']['storyPointsGrowthPercent']);
    }

    #[Test]
    public function test_scopeChange_newPrioReconduiteNePasCompter(): void
    {
        // Ticket nouvelle_prio NON ajouté (reconduit du sprint précédent) → ne
        // compte pas dans newPrio ni dans le % nouvelle_prio
        $stats = $this->statsFor([
            $this->ticket(10.0, false),
            $this->ticket(3.0, false, ['nouvelle_prio']), // reconduit → exclu
        ]);

        $this->assertSame(0.0, $stats['scopeChange']['newPrioStoryPointsPercent']);
        $this->assertSame(0, $stats['newPrio']['count']);
    }

    #[Test]
    public function test_scopeChange_newPrioAjouteeCompte(): void
    {
        // Ticket nouvelle_prio ajouté pendant le sprint → compte
        $stats = $this->statsFor([
            $this->ticket(10.0, false),
            $this->ticket(3.0, false, ['nouvelle_prio']), // reconduit → exclu
            $this->ticket(2.0, true, ['nouvelle_prio']),  // ajouté → inclus
        ]);

        // plannedStoryPoints = 10 + 3 (reconduit, non ajouté) = 13
        // newPrio SP = 2 → 2/13 × 100 = 15.4 %
        $this->assertSame(15.4, $stats['scopeChange']['newPrioStoryPointsPercent']);
        // Part sur le total à date : total = 15, 2 / 15 × 100 = 13.3 %
        $this->assertSame(13.3, $stats['scopeChange']['newPrioTotalStoryPointsPercent']);
        $this->assertSame(1, $stats['newPrio']['count']);
    }

    // =====================================================================
    // sprintAssignmentNames() : ordre des sprints affectés
    // =====================================================================

    #[Test]
    public function test_sprintAssignmentNames_sprintCourantToujoursEnDernier(): void
    {
        // Sprint analysé = « Sprint 3 ». Le ticket est passé : Sprint 3 → +Sprint 1
        // → +Sprint 2. Le sprint courant est donc inséré EN PREMIER durant le
        // replay du changelog ; il doit néanmoins finir EN DERNIER (le plus récent).
        $sprint = $this->makeBuilder(sprintName: 'Sprint 3');

        $issue = $this->builder
            ->withSprintChange('2025-01-10T08:00:00+00:00', 'Sprint 3', 'Sprint 3, Sprint 1')
            ->withSprintChange('2025-01-20T08:00:00+00:00', 'Sprint 3, Sprint 1', 'Sprint 3, Sprint 1, Sprint 2')
            ->build();

        $names = $sprint->sprintAssignmentNames($issue, new DateTime('2025-02-01T00:00:00+00:00'));

        // Ordre attendu : du plus ancien au courant, courant en dernier.
        $this->assertSame(['Sprint 1', 'Sprint 2', 'Sprint 3'], $names);
    }

    #[Test]
    public function test_sprintAssignmentNames_ignoreLesSprintsFictifsConfigures(): void
    {
        // « ABC prio » (sprint fictif de priorisation PO) est configuré comme ignoré
        // → il doit disparaître de la liste, sans affecter l'ordre des autres.
        $sprint = $this->makeBuilder(
            sprintName: 'Sprint 3',
            config: (new FakeConfig())->withIgnoredSprints(['ABC prio'])
        );

        $issue = $this->builder
            ->withSprintChange('2025-01-10T08:00:00+00:00', 'Sprint 1', 'Sprint 1, ABC prio')
            ->withSprintChange('2025-01-20T08:00:00+00:00', 'Sprint 1, ABC prio', 'Sprint 1, ABC prio, Sprint 3')
            ->build();

        $names = $sprint->sprintAssignmentNames($issue, new DateTime('2025-02-01T00:00:00+00:00'));

        $this->assertSame(['Sprint 1', 'Sprint 3'], $names);
    }

    #[Test]
    public function test_sprintAssignmentNames_triNaturel_replaceIPetAjoutsDansLeDesordre(): void
    {
        // Convention ABC : « ABC PIn ité m » / « ité IP ». Le ticket est ajouté
        // DANS LE DÉSORDRE (PI7 ité 1 avant PI6 ité IP). Le tri naturel doit
        // rétablir : PI6 ité 3, PI6 ité IP, PI7 ité 1, puis le courant en dernier.
        $sprint = $this->makeBuilder(sprintName: 'ABC PI7 ité 3');

        $issue = $this->builder
            ->withSprintChange('2025-01-10T08:00:00+00:00', 'ABC PI6 ité 3', 'ABC PI6 ité 3, ABC PI7 ité 1')
            ->withSprintChange('2025-01-20T08:00:00+00:00', 'ABC PI6 ité 3, ABC PI7 ité 1', 'ABC PI6 ité 3, ABC PI7 ité 1, ABC PI6 ité IP')
            ->build();

        $names = $sprint->sprintAssignmentNames($issue, new DateTime('2025-02-01T00:00:00+00:00'));

        // ité IP rangé après ité 3 (chiffre < lettre), PI6 avant PI7, courant dernier.
        $this->assertSame(
            ['ABC PI6 ité 3', 'ABC PI6 ité IP', 'ABC PI7 ité 1', 'ABC PI7 ité 3'],
            $names
        );
    }

    #[Test]
    public function test_sprintAssignmentNames_sprintCourantRenomme_pasDeDoublon(): void
    {
        // Bug reporté : le ticket entre dans le sprint courant (ID 100) alors
        // qu'il s'appelle « sprint1_tmp », puis l'admin Jira renomme ce sprint en
        // « sprint1 » (même ID). Le renommage ne crée aucune entrée de changelog :
        // l'ancien nom reste figé dans le changelog, le nouveau vient des
        // métadonnées. On ne doit PAS afficher les deux — c'est le même sprint.
        $sprint = $this->makeBuilder('100', sprintName: 'sprint1');

        $issue = $this->builder
            ->withSprintChangeNamed('2025-01-10T08:00:00+00:00', '', '100', '', 'sprint1_tmp')
            ->build();

        $names = $sprint->sprintAssignmentNames($issue, new DateTime('2025-02-01T00:00:00+00:00'));

        // Une seule entrée : le nom canonique actuel du sprint courant.
        $this->assertSame(['sprint1'], $names);
    }

    #[Test]
    public function test_sprintAssignmentNames_sprintCourantRenomme_conserveLesAutresSprints(): void
    {
        // Non-régression : le ticket est d'abord passé par un AUTRE sprint (ID 50,
        // « PI6 ») avant d'arriver dans le courant (ID 100) renommé « sprint1 ».
        // Seul l'ancien nom du courant doit être collapsé ; « PI6 » reste affiché.
        $sprint = $this->makeBuilder('100', sprintName: 'sprint1');

        $issue = $this->builder
            ->withSprintChangeNamed('2025-01-05T08:00:00+00:00', '', '50', '', 'PI6')
            ->withSprintChangeNamed('2025-01-10T08:00:00+00:00', '50', '50, 100', 'PI6', 'PI6, sprint1_tmp')
            ->build();

        $names = $sprint->sprintAssignmentNames($issue, new DateTime('2025-02-01T00:00:00+00:00'));

        $this->assertSame(['PI6', 'sprint1'], $names);
    }

    // =====================================================================
    // buildPuntedTickets() : tickets retirés du sprint et jamais réintégrés
    // (source : contents.puntedIssues du "Sprint Report" interne de Jira)
    // =====================================================================

    #[Test]
    public function test_buildPuntedTickets_entreeComplete_mappeLeDtoAttendu(): void
    {
        $builder = $this->makeBuilder('100');

        // Fixture proche d'une vraie entrée puntedIssues (cf. test manuel GCS-840).
        $raw = [
            'key'          => 'GCS-840',
            'summary'      => 'Retours reprises - Mise à jour écrasement de rectif',
            'typeName'     => 'Story',
            'typeUrl'      => 'https://tenant.atlassian.net/rest/api/2/universal_avatar/view/type/issuetype/avatar/10315',
            'priorityName' => 'Critique',
            'priorityUrl'  => 'https://tenant.atlassian.net/images/icons/priorities/high_new.svg',
            'priorityId'   => '2',
            'statusName'   => 'À faire',
            'done'         => false,
            'labels'       => ['une_etiquette'],
            'currentEstimateStatistic' => ['statFieldValue' => ['value' => 1.0]],
        ];

        $tickets = $builder->buildPuntedTickets([$raw], []);

        $this->assertCount(1, $tickets);
        $ticket = $tickets[0];
        $this->assertSame('GCS-840', $ticket->key);
        $this->assertTrue(str_ends_with($ticket->url, '/browse/GCS-840'));
        $this->assertSame('Story', $ticket->issueType);
        $this->assertSame('À faire', $ticket->status);
        $this->assertSame('Critique', $ticket->priority['name']);
        $this->assertSame('2', $ticket->priority['id']);
        $this->assertSame(1.0, $ticket->storyPoints);
        $this->assertNull($ticket->spFinal);
        $this->assertNull($ticket->spRemaining);
        $this->assertSame(['une_etiquette'], $ticket->labels);
        $this->assertFalse($ticket->isDone);
        $this->assertFalse($ticket->addedDuringSprint);
        $this->assertTrue($ticket->removedDuringSprint);
        $this->assertFalse($ticket->estimatedAfterAdd);
    }

    #[Test]
    public function test_buildPuntedTickets_listeVide_retourneListeVide(): void
    {
        $builder = $this->makeBuilder('100');

        $this->assertSame([], $builder->buildPuntedTickets([], []));
    }

    #[Test]
    public function test_buildPuntedTickets_cleDejaCouverteParLeChangelog_ignoreePourEviterLeDoublon(): void
    {
        // Retiré PUIS réintégré : déjà détecté via analyzeSprintMembership() (changelog),
        // ne doit pas être recompté via le Sprint Report.
        $builder = $this->makeBuilder('100');
        $raw = ['key' => 'GCS-905', 'statusName' => 'Terminé', 'done' => true];

        $tickets = $builder->buildPuntedTickets([$raw], ['GCS-905']);

        $this->assertSame([], $tickets);
    }

    #[Test]
    public function test_buildPuntedTickets_entreeSansCle_ignoreeSansErreur(): void
    {
        $builder = $this->makeBuilder('100');

        $tickets = $builder->buildPuntedTickets([['summary' => 'Sans clé, ne doit jamais arriver']], []);

        $this->assertSame([], $tickets);
    }
}
