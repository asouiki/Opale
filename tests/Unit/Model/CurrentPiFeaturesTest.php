<?php

namespace Tests\Unit\Model;

use App\Model\CurrentPiFeatures;
use App\Model\Feature;
use App\Model\Issue;
use App\Model\JiraFields;
use App\Service\FeatureMetricsCacheService;
use App\Service\JiraService;
use App\Service\JiraStatusCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de CurrentPiFeatures : filtrage de la fenêtre du PI, agrégats d'en-tête et calcul de
 * l'efficacité du flow. Les accès Jira (liste de Features, collections de tickets enfants) sont
 * neutralisés via des doublures, pour ne tester que la logique métier partagée (AbstractPiEntities).
 */
class CurrentPiFeaturesTest extends TestCase
{
    private const FROM = '2026-01-01';
    private const TO   = '2026-03-31';

    /**
     * Construit une issue Feature brute (format API Jira search) pour buildFeatureRows().
     *
     * @return array<string, mixed>
     */
    private function rawFeature(string $id, string $key, ?string $created, ?string $resolution, bool $done): array
    {
        return [
            'id'     => $id,
            'key'    => $key,
            'fields' => [
                'summary'        => 'Feature ' . $key,
                'created'        => $created,
                'resolutiondate' => $resolution,
                'status'         => [
                    'name'           => $done ? 'Terminé(e)' : 'En cours',
                    'statusCategory' => ['key' => $done ? 'done' : 'indeterminate'],
                ],
            ],
        ];
    }

    /**
     * Stub d'une collection Feature exposant les agrégats Lead/Cycle/Waiting de ses tickets enfants,
     * et éventuellement les tickets eux-mêmes (détail dépliable de la ligne).
     *
     * @param Issue[] $issues Tickets enfants exposés par getIssues()
     */
    private function collectionStub(float $totalLead, float $totalCycle, float $waitingTotal, float $waitingAvg, float $waitingMedian, array $issues = []): Feature
    {
        $stub = $this->createStub(Feature::class);
        $stub->method('getTotalLeadTime')->willReturn($totalLead);
        $stub->method('getTotalCycleTime')->willReturn($totalCycle);
        $stub->method('getTotalWaitingTime')->willReturn($waitingTotal);
        $stub->method('getAverageWaitingTime')->willReturn($waitingAvg);
        $stub->method('getMedianWaitingTime')->willReturn($waitingMedian);
        $stub->method('getIssues')->willReturn($issues);

        return $stub;
    }

    /**
     * Construit un ticket enfant minimal, rattaché à un projet donné (critère de contribution d'équipe).
     *
     * @param string[] $labels Étiquettes Jira du ticket
     */
    private function childIssue(string $projectKey, string $type, ?float $storyPoints, array $labels = []): Issue
    {
        return Issue::fromProjection([
            'data' => [
                'key'    => $projectKey . '-1',
                'fields' => [
                    'project'                => ['key' => $projectKey],
                    'issuetype'              => ['name' => $type],
                    'labels'                 => $labels,
                    JiraFields::STORY_POINTS => $storyPoints,
                ],
            ],
        ]);
    }

    /**
     * Instancie le sujet sous test avec des Features données et des doublures de collection.
     *
     * @param array<int, array<string, mixed>> $features    Issues Feature brutes
     * @param array<array-key, Feature>        $collections Map Key → collection recalculée
     * @param array<string, Feature>           $cached      Map Key → collection déjà en cache
     * @param list<string>                     $refreshKeys Rafraîchissement ciblé sur ces Keys
     */
    private function makeSubject(array $features, array $collections, array $cached = [], array $refreshKeys = []): TestableCurrentPiFeatures
    {
        $jira = $this->createStub(JiraService::class);
        $jira->method('getFeaturesByProject')->willReturn($features);

        return new TestableCurrentPiFeatures('ABC', self::FROM, self::TO, $jira, $collections, $cached, $refreshKeys);
    }

    #[Test]
    public function test_fenetreEtStatutsCroises_sontTransmisAuJql(): void
    {
        // Le croisement config × statuts déclarés est le garde-fou qui évite qu'un libellé inconnu de
        // Jira fasse échouer la requête entière. La doublure ne déclare que 4 statuts : « Spike » et
        // consorts, absents, ne doivent pas être transmis.
        $jira = $this->createMock(JiraService::class);
        $jira->expects($this->once())
            ->method('getFeaturesByProject')
            ->with(
                'ABC',
                '',
                self::FROM,
                self::TO,
                $this->callback(static function (array $statuses): bool {
                    return in_array('En cours', $statuses, true)
                        && in_array('Planifié', $statuses, true)
                        && $statuses === array_values(array_intersect(
                            $statuses,
                            ['Planifié', 'En cours', 'Terminé', 'Terminé(e)']
                        ));
                })
            )
            ->willReturn([]);

        new TestableCurrentPiFeatures('ABC', self::FROM, self::TO, $jira, []);
    }

    #[Test]
    public function test_selectionDuJql_faitFoi_aucunRefiltrageSurLesDates(): void
    {
        // Régression : rejouer un filtre sur les dates écarterait les Features démarrées AVANT la fenêtre
        // et toujours en cours pendant — alors qu'elles ont bien été travaillées sur la période, et que
        // c'est précisément pour cela que Jira les a retenues.
        $features = [
            $this->rawFeature('1', 'ABC-1', '2026-02-01', null, false),
            $this->rawFeature('2', 'ABC-2', '2024-01-01', null, false),
        ];

        $collections = [
            'ABC-1' => $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0),
            'ABC-2' => $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0),
        ];

        $keys = array_column($this->makeSubject($features, $collections)->getItems(), 'key');

        $this->assertEqualsCanonicalizing(['ABC-1', 'ABC-2'], $keys);
    }

    #[Test]
    public function test_featureDemarreeAvantLaFenetre_maisTermineeDedans_estRetenue(): void
    {
        $features = [
            // Créée bien avant la fenêtre, clôturée pendant : c'est le cas REP-360, que l'ancienne borne
            // glissante `created >= -365d` rendait invisible.
            $this->rawFeature('1', 'ABC-1', '2025-09-05', '2026-02-20', true),
        ];

        $item = $this->makeSubject($features, ['ABC-1' => $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0)])
            ->getItems()[0];

        $this->assertSame('ABC-1', $item['key']);
        $this->assertSame('2026-02-20', $item['closeDate']);
    }

    #[Test]
    public function test_ligne_typeFeatureEtCumulsDesTicketsEnfants(): void
    {
        $features = [
            $this->rawFeature('10', 'ABC-A', '2026-01-10', '2026-02-10', true),
        ];
        // Lead cumulé 10, Cycle cumulé 5, Waiting total 3 → Production = max(0, 5 − 3) = 2 j.
        $item = $this->makeSubject(
            $features,
            ['ABC-A' => $this->collectionStub(10.0, 5.0, 3.0, 3.0, 3.0)]
        )->getItems()[0];

        $this->assertSame('feature', $item['type']);
        $this->assertSame(10.0, $item['leadTime']);
        $this->assertSame(5.0, $item['cycleTime']);
        // Réalisation réelle = Production / Lead = 2 / 10 = 20 %.
        $this->assertSame(2.0, $item['realisationDays']);
        $this->assertSame(20.0, $item['realisationPct']);
        // Flow Efficiency = Production / Cycle = 2 / 5 = 40 % (identique à /feature et /release).
        $this->assertSame(2.0, $item['efficaciteDays']);
        $this->assertSame(40.0, $item['efficacitePct']);
        // Deep-link vers /feature avec la Key.
        $this->assertSame('/feature?featureId=ABC-A', $item['analyseUrl']);
    }

    #[Test]
    public function test_breakdown_neRetientQueLesTicketsDuProjetDeLEquipe(): void
    {
        $features = [
            $this->rawFeature('10', 'ABC-A', '2026-01-10', '2026-02-10', true),
        ];

        // Feature portée par le projet parent : elle agrège les contributions de deux équipes.
        $collection = $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0, [
            $this->childIssue('ABC', 'Story', 5.0),
            $this->childIssue('ABC', 'Bug', 2.0, ['BUG_Technique']),
            $this->childIssue('XYZ', 'Story', 13.0),
        ]);

        $item = $this->makeSubject($features, ['ABC-A' => $collection])->getItems()[0];

        // Le ticket du projet XYZ (autre équipe) est exclu de TOUS les compteurs du détail.
        $this->assertSame([
            'issuesCount'      => 2,
            'totalStoryPoints' => 7.0,
            'bugCount'         => 1,
            'bugStoryPoints'   => 2.0,
            'bugsByLabel'      => ['BUG_Technique' => 1],
            'byType'           => [
                ['type' => 'Bug', 'count' => 1, 'storyPoints' => 2.0],
                ['type' => 'Story', 'count' => 1, 'storyPoints' => 5.0],
            ],
        ], $item['breakdown']);
    }

    #[Test]
    public function test_featureTerminee_sansCiblage_estServieDuCache_sansAppelJira(): void
    {
        $features = [$this->rawFeature('10', 'ABC-A', '2026-01-10', '2026-02-10', true)];
        $cached   = ['ABC-A' => $this->collectionStub(1.0, 0.0, 0.0, 0.0, 0.0)];

        $subject = $this->makeSubject($features, [], $cached);

        $this->assertSame([], $subject->recomputed);
        $this->assertSame(1.0, $subject->getItems()[0]['leadTime']);
    }

    #[Test]
    public function test_rafraichissementCible_recalculeLaFeatureTerminee_etLaisseLesAutresAuCache(): void
    {
        $features = [
            $this->rawFeature('10', 'ABC-A', '2026-01-10', '2026-02-10', true),
            $this->rawFeature('11', 'ABC-B', '2026-01-12', '2026-02-12', true),
        ];
        // Les deux sont en cache avec un Lead de 1 j ; seule ABC-A est ciblée et vaut 9 j après recalcul.
        $cached = [
            'ABC-A' => $this->collectionStub(1.0, 0.0, 0.0, 0.0, 0.0),
            'ABC-B' => $this->collectionStub(1.0, 0.0, 0.0, 0.0, 0.0),
        ];
        $fresh = ['ABC-A' => $this->collectionStub(9.0, 0.0, 0.0, 0.0, 0.0)];

        $subject = $this->makeSubject($features, $fresh, $cached, ['ABC-A']);

        // Un seul aller-retour « Jira » : la Feature ciblée, bien que terminée (cache permanent).
        $this->assertSame(['ABC-A'], $subject->recomputed);

        $byKey = array_column($subject->getItems(), 'leadTime', 'key');
        $this->assertSame(9.0, $byKey['ABC-A']);
        $this->assertSame(1.0, $byKey['ABC-B']);
    }

    #[Test]
    public function test_agregats_compteursMoyennesEtMedianes(): void
    {
        $features = [
            $this->rawFeature('10', 'ABC-A', '2026-01-10', '2026-02-10', true),
            $this->rawFeature('11', 'ABC-B', '2026-01-20', '2026-03-20', true),
        ];
        // Lead cumulé 10/20, Cycle cumulé 6/10, Waiting total 12/30, moyen 4/5, médian 3/5.
        $collections = [
            'ABC-A' => $this->collectionStub(10.0, 6.0, 12.0, 4.0, 3.0),
            'ABC-B' => $this->collectionStub(20.0, 10.0, 30.0, 5.0, 5.0),
        ];

        $stats = $this->makeSubject($features, $collections)->getStats();

        $this->assertSame(2, $stats['nbCreated']);
        $this->assertSame(2, $stats['nbDone']);
        $this->assertSame(15.0, $stats['lead']['avg']);
        $this->assertSame(15.0, $stats['lead']['median']);
        $this->assertSame(8.0, $stats['cycle']['avg']);
        $this->assertSame(8.0, $stats['cycle']['median']);
        $this->assertSame(4.5, $stats['waiting']['avg']);
        $this->assertSame(4.5, $stats['waiting']['median']);
    }
}

/**
 * Doublure testable de CurrentPiFeatures : neutralise les accès Jira coûteux (collections de tickets
 * enfants, cache fichier) au profit de données injectées. Les stubs doivent être positionnés avant
 * l'appel au constructeur parent, qui orchestre tout le calcul.
 */
class TestableCurrentPiFeatures extends CurrentPiFeatures
{
    /** @var array<string, Feature> */
    private array $collectionStubs;

    /** @var array<string, Feature> Entrées considérées comme DÉJÀ en cache (servies sans recalcul). */
    private array $cachedStubs;

    /** @var list<string> Keys réellement recalculées depuis « Jira » — la trace des appels coûteux. */
    public array $recomputed = [];

    /**
     * @param array<array-key, Feature> $collections Map Key → collection recalculée
     * @param array<string, Feature>    $cached      Map Key → collection déjà en cache
     * @param list<string>              $refreshKeys Rafraîchissement ciblé sur ces Keys
     */
    public function __construct(string $projectKey, string $from, string $to, JiraService $jira, array $collections, array $cached = [], array $refreshKeys = [])
    {
        $this->collectionStubs = $collections;
        $this->cachedStubs     = $cached;

        parent::__construct($projectKey, $from, $to, false, '', '', false, $refreshKeys, jira: $jira);
    }

    protected function newCollection(string $key): Feature
    {
        $this->recomputed[] = $key;

        return $this->collectionStubs[$key];
    }

    /**
     * Cache en mémoire alimenté par le test : un miss déclenche newCollection() (stubbée), sans E/S disque.
     */
    protected function makeCache(): FeatureMetricsCacheService
    {
        return new class ($this->cachedStubs) extends FeatureMetricsCacheService {
            /** @param array<string, Feature> $entries */
            public function __construct(private array $entries)
            {
            }
            public function get(string $id): ?Feature
            {
                return $this->entries[$id] ?? null;
            }
            public function put(string $id, bool $isDone, Feature $feature): void
            {
            }
            public function save(): void
            {
            }
        };
    }

    /**
     * Statuts déclarés figés : aucun appel Jira ni E/S disque. La liste couvre la config du workflow
     * Feature, pour que le croisement laisse passer les statuts attendus.
     */
    protected function makeStatusCache(): JiraStatusCacheService
    {
        return new class () extends JiraStatusCacheService {
            public function __construct()
            {
            }

            public function getStatusNames(string $projectKey): array
            {
                return ['Planifié', 'En cours', 'Terminé', 'Terminé(e)'];
            }
        };
    }
}
