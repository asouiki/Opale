<?php

namespace Tests\Unit\Service;

use App\Model\Sprint;
use App\Model\TeamMetrics;
use App\Service\MetricsDigestBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Tests de caractérisation du digest texte soumis aux LLM (synthèse IA locale et export de prompt).
 *
 * Les golden strings figent le format exact du digest : toute évolution doit être un choix explicite,
 * pas un effet de bord. On couvre le cas nominal complet (assertSame), les cas limites (capacité non
 * saisie, aucun objectif, snapshot vide), l'exclusion de l'affinage dans l'atterrissage, le calcul de
 * vélocité (formules du composant JS sprintManDays) et le tri chronologique côté équipe.
 *
 * Déterminisme : la durée en jours ouvrés du sprint est calculée par Timeline sur des dates fixes sans
 * jour férié (1→15 juin 2026 = 10 j ouvrés), ce qui rend les ratios « par jour ouvré » stables.
 */
class MetricsDigestBuilderTest extends TestCase
{
    private MetricsDigestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MetricsDigestBuilder();
    }

    // =====================================================================
    // forSprint()
    // =====================================================================

    #[Test]
    public function test_forSprint_metriquesCompletes_produitLeDigestAttendu(): void
    {
        $sprint = Sprint::fromSnapshot($this->richSprintSnapshot());

        $expected = <<<'TXT'
            Sprint : Sprint 42 — Refonte VSM
            État : closed
            Période : 2026-06-01 → 2026-06-15

            Complétion des objectifs du sprint :
            - objectif_1 : 66.7 % (2/3 terminés)
            - objectif_2 : 100 % (1/1 terminés)

            Périmètre :
            - Tickets dans le sprint : 31
            - Total Story Points : 78.5
            - Tickets non estimés à la clôture : 3 (2 Story, 1 Bug)
            - Dont estimés après leur ajout au sprint : 1
            - Features traitées : 2
            - Distribution des SP : min 1, max 13, médiane 5, moyenne 5.5

            Prédictibilité (Story Points) :
            - SP planifiés au démarrage : 66.5
            - SP terminés : 61
            - Prédictibilité atteinte : 91.73 % (cible minimale : 80 %)

            Engagement (sujets du périmètre initial) :
            - Tickets planifiés au démarrage : 26
            - Tickets planifiés terminés : 21
            - Engagement atteint : 80.77 % (cible minimale : 80 %)

            Atterrissage :
            - Tickets terminés : 24 (61 SP)
            - Reliquat (non terminés) : 7 (17.5 SP)

            Atterrissage par statut (hors affinage) :
            - To Do : 5 tickets à date, 12 SP, cumul 0 j, moyenne 0 j/ticket
            - In Progress : 2 tickets à date, 8 SP, cumul 10 j, moyenne 5 j/ticket
            - Done : 24 tickets à date, 61 SP, cumul 120 j, moyenne 5 j/ticket
            - Annulé : 0 tickets à date, 0 SP, cumul 0 j, moyenne 0 j/ticket

            Temps d'attente (borné au sprint, entre parenthèses sur tout le cycle de vie des tickets) :
            - Total : 4.75 j (12 j sur tout le cycle de vie)
            - Moyenne : 0.4 j/ticket (1 j sur tout le cycle de vie)
            Détail des causes :
            - Bloqué : 3 j (9 j sur tout le cycle de vie)
            - En attente client : 1.75 j (3 j sur tout le cycle de vie)

            Capacité & vélocité :
            - Capacité jours/homme : 8
            - Capacité en points : 40
            - SP terminés / jour-homme : 7.63
            - SP terminés / jour ouvré : 6.1
            - Jours-homme / SP : 0.13
            - Jours ouvrés / SP : 0.16
            - SP terminés / personne : 76.25

            Évolution du périmètre :
            - SP planifiés → à la fin : 66.5 → 78.5
            - Croissance du périmètre (SP) : 18.05 %
            - Tickets ajoutés en cours de sprint : 5 (12 SP)
            - Dont priorités ajoutées en urgence (étiquette nouvelle_prio) : 2 (5 SP)
            - Tickets retirés du sprint : 1 (2 SP)
            TXT;

        $this->assertSame($expected, $this->builder->forSprint($sprint, 8.0, 40.0));
    }

    #[Test]
    public function test_forSprint_velocite_reprendLesFormulesDuComposantJs(): void
    {
        // 61 SP terminés, 10 jours ouvrés (dates fixes), 8 jours/homme :
        // SP/j-h = 61/8 = 7.63 ; j-h/SP = 8/61 = 0.13 ; SP/j ouvré = 61/10 = 6.1 ; SP/personne = 61*10/8 = 76.25.
        $sprint = Sprint::fromSnapshot($this->richSprintSnapshot());

        $digest = $this->builder->forSprint($sprint, 8.0, 40.0);

        $this->assertStringContainsString('- SP terminés / jour-homme : 7.63', $digest);
        $this->assertStringContainsString('- Jours-homme / SP : 0.13', $digest);
        $this->assertStringContainsString('- SP terminés / jour ouvré : 6.1', $digest);
        $this->assertStringContainsString('- SP terminés / personne : 76.25', $digest);
    }

    #[Test]
    public function test_forSprint_sansCapaciteSaisie_afficheNAMaisGardeLesRatiosParJourOuvre(): void
    {
        // manDays/capacityPoints null : capacité et ratios dépendant des jours/homme = N/A ; les ratios
        // par jour ouvré restent calculables (dérivés des dates du sprint).
        $sprint = Sprint::fromSnapshot($this->richSprintSnapshot());

        $digest = $this->builder->forSprint($sprint);

        $this->assertStringContainsString('- Capacité jours/homme : N/A', $digest);
        $this->assertStringContainsString('- Capacité en points : N/A', $digest);
        $this->assertStringContainsString('- SP terminés / jour-homme : N/A', $digest);
        $this->assertStringContainsString('- Jours-homme / SP : N/A', $digest);
        $this->assertStringContainsString('- SP terminés / personne : N/A', $digest);
        // Indépendant de la capacité : reste chiffré.
        $this->assertStringContainsString('- SP terminés / jour ouvré : 6.1', $digest);
    }

    #[Test]
    public function test_forSprint_atterrissage_excludeAffinageEtGardeStatutTransiteVide(): void
    {
        $sprint = Sprint::fromSnapshot($this->richSprintSnapshot());

        $digest = $this->builder->forSprint($sprint, 8.0, 40.0);

        // Le statut de catégorie « refinement » (Affinage) est hors atterrissage.
        $this->assertStringNotContainsString('Affinage', $digest);
        // Un statut traversé mais vide à date reste listé (compte 0).
        $this->assertStringContainsString('- Annulé : 0 tickets à date, 0 SP, cumul 0 j, moyenne 0 j/ticket', $digest);
    }

    #[Test]
    public function test_forSprint_completionParObjectif_nExposeQueLesObjectifsNumerotes(): void
    {
        $sprint = Sprint::fromSnapshot($this->richSprintSnapshot());

        $digest = $this->builder->forSprint($sprint, 8.0, 40.0);

        $this->assertStringContainsString('- objectif_1 : 66.7 % (2/3 terminés)', $digest);
        $this->assertStringContainsString('- objectif_2 : 100 % (1/1 terminés)', $digest);
        // Les objectifs non numérotés (« objectif_transverse ») et les libellés métier ne fuitent pas.
        $this->assertStringNotContainsString('transverse', $digest);
    }

    #[Test]
    public function test_forSprint_sansObjectifEtiquete_afficheAucunObjectif(): void
    {
        // Aucun groupe « objectif_N » : ligne explicite, pas d'en-tête orphelin.
        $snapshot                    = $this->richSprintSnapshot();
        $snapshot['objectiveGroups'] = [];

        $digest = $this->builder->forSprint(Sprint::fromSnapshot($snapshot));

        $this->assertStringContainsString(
            'Complétion des objectifs du sprint : aucun ticket associé à un objectif',
            $digest
        );
    }

    #[Test]
    public function test_forSprint_croissancePerimetreNulle_afficheNA(): void
    {
        // storyPointsGrowthPercent absent (périmètre initial vide) : la ligne doit dire N/A, sans « % ».
        $snapshot = $this->richSprintSnapshot();
        unset($snapshot['stats']['scopeChange']['storyPointsGrowthPercent']);

        $digest = $this->builder->forSprint(Sprint::fromSnapshot($snapshot));

        $this->assertStringContainsString('- Croissance du périmètre (SP) : N/A' . "\n", $digest);
    }

    #[Test]
    public function test_forSprint_snapshotVide_produitUnDigestNeutreSansErreur(): void
    {
        // Sprint sans aucune métrique : toutes les valeurs retombent sur leurs défauts, aucune notice.
        $digest = $this->builder->forSprint(Sprint::fromSnapshot(['sprintId' => '1']));

        $this->assertStringContainsString('Sprint : Sprint', $digest);
        $this->assertStringContainsString('Période : ? → ?', $digest);
        $this->assertStringContainsString('- Total Story Points : 0', $digest);
        $this->assertStringContainsString('- Croissance du périmètre (SP) : N/A', $digest);
        // Sections neuves robustes au vide (pas de division par zéro, pas d'en-tête orphelin).
        $this->assertStringContainsString('Complétion des objectifs du sprint : aucun ticket associé', $digest);
        $this->assertStringContainsString('- (aucun statut hors affinage)', $digest);
        $this->assertStringContainsString('- Capacité jours/homme : N/A', $digest);
        $this->assertStringContainsString('- SP terminés / jour ouvré : N/A', $digest);
    }

    // =====================================================================
    // forTeam()
    // =====================================================================

    #[Test]
    public function test_forTeam_metriquesCompletes_produitLeDigestAttendu(): void
    {
        $expected = <<<'TXT'
            Nombre de sprints clôturés analysés : 3
            Jours ouvrés cumulés : 29

            Moyennes par sprint :
            - Tickets terminés : 21.33
            - Story Points terminés : 54.5
            - Durée (jours ouvrés) : 9.67
            - Durée (jours-homme) : 12.5
            - Tickets ajoutés en cours de sprint : 4 (9.5 SP)
            - Reliquat : 6 tickets (14.25 SP)
            - Reliquat : part non terminée moyenne 22.4 % des tickets, 20.7 % des SP
            - Delta moyen (total SP vs capacité) : -3.5 pts

            Prédictibilité & engagement :
            - Prédictibilité moyenne : 86.42 % (cible minimale : 80 %)
            - Engagement moyen : 78.9 % (cible minimale : 80 %)
            - Changement de périmètre moyen : 15.5 % (dont nouvelle_prio : N/A %)

            Vélocité :
            - Story Points terminés / jour ouvré : 5.64
            - Story Points terminés / jour-homme : 4.36
            - Story Points terminés / personne : 43.6
            - Jours ouvrés / Story Point : 0.18
            - Jours-homme / Story Point : 0.23

            Temps de traversée (tickets terminés, jours ouvrés) :
            - Lead time : moyen 12.4, médian 10
            - Cycle time : moyen 6.2, médian 5
            - Waiting time : moyen 2.75, médian 2

            Causes d'attente (jours ouvrés cumulés) :
            - Bloqué : 9 j
            - Attente client : 4.5 j

            Cycle time par estimation et par type (jours ouvrés) :
            Story :
              - 3 SP (4 tickets) : min 2, max 8, médiane 5, moyenne 5 j
              - 5 SP (2 tickets) : min 6, max 10, médiane 8, moyenne 8 j
            Bug :
              - 1 SP (3 tickets) : min 1, max 3, médiane 2, moyenne 2 j

            Détails par sprint (du plus ancien au plus récent) :
            - Sprint 1 : prédictibilité 80 %, engagement 75 %, 18 tickets terminés (48 SP), reliquat 8 tickets, changement de périmètre N/A %, vélocité (points par jours/homme) N/A
            - Sprint 2 : prédictibilité 89.26 %, engagement 79.7 %, 21 tickets terminés (55.5 SP), reliquat 6 tickets, changement de périmètre 19 %, vélocité (points par jours/homme) 4.1
            - Sprint 3 : prédictibilité 90 %, engagement 82 %, 25 tickets terminés (60 SP), reliquat 4 tickets, changement de périmètre 12 %, vélocité (points par jours/homme) 4.8
            TXT;

        $this->assertSame($expected, $this->builder->forTeam($this->makeTeamMetrics()));
    }

    #[Test]
    public function test_forTeam_sprintsDesordonnes_sontTriesChronologiquement(): void
    {
        // La fixture fournit les sprints dans l'ordre 3, 1, 2 ; le digest doit rétablir 1, 2, 3 en se
        // basant sur completeDate, avec repli sur endDate quand la clôture est absente (Sprint 1).
        $digest = $this->builder->forTeam($this->makeTeamMetrics());

        $this->assertSame(
            [1, 2, 3],
            array_map(
                static fn (string $line): int => (int) substr($line, strlen('- Sprint ')),
                array_values(array_filter(
                    explode("\n", $digest),
                    static fn (string $line): bool => str_starts_with($line, '- Sprint ')
                ))
            )
        );
    }

    #[Test]
    public function test_forTeam_cycleTimeParType_regroupeParTypePuisEstimationCroissante(): void
    {
        $digest = $this->builder->forTeam($this->makeTeamMetrics());

        // En-têtes de type + lignes indentées par estimation croissante (3 puis 5 SP pour Story).
        $this->assertStringContainsString("Story :\n  - 3 SP (4 tickets) :", $digest);
        $this->assertStringContainsString('  - 5 SP (2 tickets) :', $digest);
        $this->assertStringContainsString("Bug :\n  - 1 SP (3 tickets) :", $digest);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Snapshot de sprint clôturé couvrant l'ensemble des sections du digest : objectifs numérotés (avec
     * un non numéroté à ignorer), atterrissage incluant un statut « refinement » (à exclure) et un statut
     * transité vide, non-estimés ventilés par type, distribution SP, attente, périmètre, tickets retirés.
     *
     * @return array<string, mixed>
     */
    private function richSprintSnapshot(): array
    {
        return [
            'sprintId'   => '100',
            'projectKey' => 'ABC',
            'boardId'    => '7',
            'sprintData' => [
                'id'           => 100,
                'name'         => 'Sprint 42 — Refonte VSM',
                'state'        => 'closed',
                'goal'         => 'Stabiliser le pipeline de métriques',
                'startDate'    => '2026-06-01',
                'endDate'      => '2026-06-14',
                'completeDate' => '2026-06-15',
            ],
            'issuesCount'     => 31,
            'objectiveGroups' => [
                ['label' => 'objectif_1', 'title' => 'Obj 1', 'issues' => [
                    ['isDone' => true], ['isDone' => true], ['isDone' => false],
                ]],
                ['label' => 'objectif_2', 'title' => 'Obj 2', 'issues' => [
                    ['isDone' => true],
                ]],
                ['label' => 'objectif_transverse', 'title' => 'Transverse', 'issues' => [
                    ['isDone' => false],
                ]],
            ],
            'landingByStatus' => [
                ['status' => 'To Do', 'category' => 'todo', 'count' => 5, 'storyPoints' => 12.0, 'totalDays' => 0.0, 'averageDays' => 0.0],
                ['status' => 'In Progress', 'category' => 'in_progress', 'count' => 2, 'storyPoints' => 8.0, 'totalDays' => 10.0, 'averageDays' => 5.0],
                ['status' => 'Affinage', 'category' => 'refinement', 'count' => 3, 'storyPoints' => 6.0, 'totalDays' => 4.0, 'averageDays' => 1.33],
                ['status' => 'Done', 'category' => 'done', 'count' => 24, 'storyPoints' => 61.0, 'totalDays' => 120.0, 'averageDays' => 5.0],
                ['status' => 'Annulé', 'category' => 'done', 'count' => 0, 'storyPoints' => 0.0, 'totalDays' => 0.0, 'averageDays' => 0.0],
            ],
            'features' => [
                ['key' => 'ABC-1', 'summary' => 'Feature A'],
                ['key' => 'ABC-2', 'summary' => 'Feature B'],
            ],
            'stats' => [
                'totalStoryPoints'               => 78.5,
                'issuesWithoutStoryPoints'       => 3,
                'issuesWithoutStoryPointsByType' => ['Story' => 2, 'Bug' => 1],
                'estimatedAfterAdd'              => ['count' => 1],
                'storyPointsRange'               => ['min' => 1.0, 'max' => 13.0, 'median' => 5.0, 'average' => 5.5],
                'doneTickets'                    => ['count' => 24, 'storyPoints' => 61.0],
                'reliquat'                       => ['count' => 7, 'storyPoints' => 17.5],
                'addedDuringSprint'              => ['count' => 5, 'storyPoints' => 12.0],
                'newPrio'                        => ['count' => 2, 'storyPoints' => 5.0],
                'removedDuringSprint'            => ['count' => 1, 'storyPoints' => 2.0],
                'scopeChange'                    => [
                    'currentTicketCount'       => 31,
                    'plannedStoryPoints'       => 66.5,
                    'currentStoryPoints'       => 78.5,
                    'storyPointsGrowthPercent' => 18.045,
                ],
            ],
            'predictability' => [
                'startStoryPoints'              => 66.5,
                'completedStoryPoints'          => 61.0,
                'achievedPercent'               => 91.729,
                'targetPercent'                 => 80,
                'startTicketCount'              => 26,
                'completedFromStartTicketCount' => 21,
                'engagementPercent'             => 80.769,
            ],
            'ticketAgeStats'            => ['average' => 6.5, 'median' => 5.0],
            'totalTimeSpentInWaiting'   => 4.75,
            'averageTimeSpentInWaiting' => 0.4,
            'aggregatedWaitingTimes'    => ['Bloqué' => 3.0, 'En attente client' => 1.75],
            // Volontairement SUPÉRIEURS aux valeurs bornées : le digest doit exposer les deux, pour que
            // le modèle distingue un blocage né dans le sprint d'une attente héritée des précédents.
            'totalTimeSpentInWaitingFull'   => 12.0,
            'averageTimeSpentInWaitingFull' => 1.0,
            'aggregatedWaitingTimesFull'    => ['Bloqué' => 9.0, 'En attente client' => 3.0],
        ];
    }

    /**
     * Monte un TeamMetrics sans passer par son constructeur (qui interrogerait Jira) : seuls les agrégats
     * lus par le digest sont injectés (stats, sprints en désordre, causes d'attente, nuage cycle time).
     */
    private function makeTeamMetrics(): TeamMetrics
    {
        /** @var TeamMetrics $metrics */
        $metrics = (new ReflectionClass(TeamMetrics::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(TeamMetrics::class, 'stats'))->setValue($metrics, [
            'sprintCount'                => 3,
            'totalBusinessDays'          => 29.0,
            'avgDoneTickets'             => 21.333,
            'avgDoneStoryPoints'         => 54.5,
            'avgBusinessDays'            => 9.667,
            'avgManDays'                 => 12.5,
            'avgAddedTickets'            => 4.0,
            'avgAddedStoryPoints'        => 9.5,
            'avgReliquatTickets'         => 6.0,
            'avgReliquatStoryPoints'     => 14.25,
            'reliquatTicketSharePercent' => 22.4,
            'reliquatSpSharePercent'     => 20.7,
            'avgCapacityDelta'           => -3.5,
            'avgPredictability'          => 86.42,
            'predictabilityTarget'       => 80,
            'avgEngagement'              => 78.9,
            'avgScopeGrowthPercent'      => 15.5,
            'avgnewPrioPercent'          => null,
            'velocityPerDay'             => 5.638,
            'velocityPerManDay'          => 4.36,
            'avgSpPerPerson'             => 43.6,
            'daysPerSp'                  => 0.177,
            'manDaysPerSp'               => 0.229,
            'avgLeadTime'                => 12.4,
            'medianLeadTime'             => 10.0,
            'avgCycleTime'               => 6.2,
            'medianCycleTime'            => 5.0,
            'avgWaitingTime'             => 2.75,
            'medianWaitingTime'          => 2.0,
        ]);

        (new ReflectionProperty(TeamMetrics::class, 'sprints'))->setValue($metrics, [
            [
                'name' => 'Sprint 3', 'completeDate' => '2026-06-30', 'endDate' => '2026-06-28',
                'predictabilityPercent' => 90.0, 'engagementPercent' => 82.0, 'doneCount' => 25,
                'doneStoryPoints' => 60.0, 'reliquatCount' => 4, 'scopeGrowthPercent' => 12.0, 'velocityPerManDay' => 4.8,
            ],
            [
                // Sans date de clôture : le tri doit se rabattre sur endDate.
                'name' => 'Sprint 1', 'completeDate' => '', 'endDate' => '2026-06-02',
                'predictabilityPercent' => 80.0, 'engagementPercent' => 75.0, 'doneCount' => 18,
                'doneStoryPoints' => 48.0, 'reliquatCount' => 8, 'scopeGrowthPercent' => null, 'velocityPerManDay' => null,
            ],
            [
                'name' => 'Sprint 2', 'completeDate' => '2026-06-16', 'endDate' => '2026-06-15',
                'predictabilityPercent' => 89.26, 'engagementPercent' => 79.7, 'doneCount' => 21,
                'doneStoryPoints' => 55.5, 'reliquatCount' => 6, 'scopeGrowthPercent' => 19.0, 'velocityPerManDay' => 4.1,
            ],
        ]);

        (new ReflectionProperty(TeamMetrics::class, 'aggregatedWaitingTimes'))
            ->setValue($metrics, ['Bloqué' => 9.0, 'Attente client' => 4.5]);

        (new ReflectionProperty(TeamMetrics::class, 'cycleTimeScatterByType'))->setValue($metrics, [
            'Story' => [
                ['x' => 3.0, 'y' => 2.0, 'key' => 'ABC-1'], ['x' => 3.0, 'y' => 4.0, 'key' => 'ABC-2'],
                ['x' => 3.0, 'y' => 6.0, 'key' => 'ABC-3'], ['x' => 3.0, 'y' => 8.0, 'key' => 'ABC-4'],
                ['x' => 5.0, 'y' => 6.0, 'key' => 'ABC-5'], ['x' => 5.0, 'y' => 10.0, 'key' => 'ABC-6'],
            ],
            'Bug' => [
                ['x' => 1.0, 'y' => 1.0, 'key' => 'ABC-7'], ['x' => 1.0, 'y' => 2.0, 'key' => 'ABC-8'],
                ['x' => 1.0, 'y' => 3.0, 'key' => 'ABC-9'],
            ],
        ]);

        return $metrics;
    }
}
