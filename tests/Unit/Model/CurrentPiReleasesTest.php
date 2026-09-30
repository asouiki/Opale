<?php

namespace Tests\Unit\Model;

use App\Model\CurrentPiReleases;
use App\Model\Version;
use App\Service\JiraService;
use App\Service\ReleaseMetricsCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de CurrentPiReleases : filtrage de la fenêtre du PI, agrégats d'en-tête et calcul de
 * l'efficacité du flow. Les accès Jira (listes de Releases, collections de tickets liés) sont
 * neutralisés via des doublures, pour ne tester que la logique métier.
 */
class CurrentPiReleasesTest extends TestCase
{
    private const FROM = '2026-01-01';
    private const TO   = '2026-03-31';

    /**
     * Construit une issue Release brute (format API Jira search) pour buildReleaseRows().
     *
     * @param  string      $id
     * @param  string      $key
     * @param  string|null $created
     * @param  string|null $resolution
     * @param  bool        $done
     * @return array
     */
    private function rawRelease(string $id, string $key, ?string $created, ?string $resolution, bool $done): array
    {
        return [
            'id'     => $id,
            'key'    => $key,
            'fields' => [
                'summary'        => 'Release ' . $key,
                'created'        => $created,
                'resolutiondate' => $resolution,
                'status'         => [
                    'name'           => $done ? 'Done' : 'In Progress',
                    'statusCategory' => ['key' => $done ? 'done' : 'indeterminate'],
                ],
            ],
        ];
    }

    /**
     * Stub d'une collection Version exposant les agrégats Lead/Cycle/Waiting des tickets liés.
     *
     * @param  float $totalLead     Lead Time cumulé des tickets liés
     * @param  float $totalCycle    Cycle Time cumulé des tickets liés
     * @param  float $waitingTotal  Waiting Time cumulé
     * @param  float $waitingAvg    Waiting Time moyen par ticket
     * @param  float $waitingMedian Waiting Time médian par ticket
     * @return Version
     */
    private function collectionStub(float $totalLead, float $totalCycle, float $waitingTotal, float $waitingAvg, float $waitingMedian): Version
    {
        $stub = $this->createStub(Version::class);
        $stub->method('getTotalLeadTime')->willReturn($totalLead);
        $stub->method('getTotalCycleTime')->willReturn($totalCycle);
        $stub->method('getTotalWaitingTime')->willReturn($waitingTotal);
        $stub->method('getAverageWaitingTime')->willReturn($waitingAvg);
        $stub->method('getMedianWaitingTime')->willReturn($waitingMedian);

        return $stub;
    }

    /**
     * Construit une fixVersion brute (format API Jira) pour buildVersionRows().
     *
     * @param  string      $id
     * @param  string|null $start
     * @param  string|null $release
     * @param  bool        $released
     * @return array
     */
    private function rawVersion(string $id, ?string $start, ?string $release, bool $released): array
    {
        return [
            'id'          => $id,
            'name'        => 'Version ' . $id,
            'startDate'   => $start,
            'releaseDate' => $release,
            'released'    => $released,
        ];
    }

    /**
     * Instancie le sujet sous test avec des Releases / Versions données et des doublures de collection.
     *
     * @param  array<int, array>      $releases    Issues Release brutes
     * @param  array<array-key, Version> $collections Map idOrKey → collection mockée
     * @param  array<int, array>      $versions    fixVersions brutes (défaut : aucune)
     * @return CurrentPiReleases
     */
    private function makeSubject(array $releases, array $collections, array $versions = []): CurrentPiReleases
    {
        $jira = $this->createStub(JiraService::class);
        $jira->method('getVersionsByProject')->willReturn($versions);
        $jira->method('getReleasesByProject')->willReturn($releases);

        return new TestableCurrentPiReleases('ABC', self::FROM, self::TO, $jira, $collections);
    }

    #[Test]
    public function test_filtreFenetre_gardeDemarréesOuTerminées_excluteHorsFenetre(): void
    {
        $releases = [
            // Démarrée dans la fenêtre (pas encore terminée) → incluse
            $this->rawRelease('1', 'ABC-1', '2026-02-01', null, false),
            // Démarrée avant mais terminée dans la fenêtre → incluse
            $this->rawRelease('2', 'ABC-2', '2025-11-01', '2026-02-15', true),
            // Entièrement hors fenêtre → exclue
            $this->rawRelease('3', 'ABC-3', '2025-01-01', '2025-06-01', true),
        ];

        $collections = [
            'ABC-1' => $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0),
            'ABC-2' => $this->collectionStub(0.0, 0.0, 0.0, 0.0, 0.0),
        ];

        $subject = $this->makeSubject($releases, $collections);

        $keys = array_column($subject->getItems(), 'key');
        $this->assertEqualsCanonicalizing(['ABC-1', 'ABC-2'], $keys);
    }

    #[Test]
    public function test_agregats_compteursMoyennesEtMedianes(): void
    {
        $releases = [
            $this->rawRelease('10', 'ABC-A', '2026-01-10', '2026-02-10', true),
            $this->rawRelease('11', 'ABC-B', '2026-01-20', '2026-03-20', true),
        ];
        // Lead cumulé 10/20, Cycle cumulé 6/10, Waiting total 12/30, moyen 4/5, médian 3/5.
        $collections = [
            'ABC-A' => $this->collectionStub(10.0, 6.0, 12.0, 4.0, 3.0),
            'ABC-B' => $this->collectionStub(20.0, 10.0, 30.0, 5.0, 5.0),
        ];

        $stats = $this->makeSubject($releases, $collections)->getStats();

        // Deux Releases Done, démarrées et terminées dans la fenêtre.
        $this->assertSame(2, $stats['nbCreated']);
        $this->assertSame(2, $stats['nbDone']);

        // Lead 10/20, Cycle 6/10, Waiting(moyenne/ticket) 4/5.
        $this->assertSame(15.0, $stats['lead']['avg']);
        $this->assertSame(15.0, $stats['lead']['median']);
        $this->assertSame(8.0, $stats['cycle']['avg']);
        $this->assertSame(8.0, $stats['cycle']['median']);
        $this->assertSame(4.5, $stats['waiting']['avg']);
        $this->assertSame(4.5, $stats['waiting']['median']);
    }

    #[Test]
    public function test_efficaciteFlow_joursEtPourcentParLigne(): void
    {
        $releases = [
            $this->rawRelease('20', 'ABC-F', '2026-02-01', '2026-03-01', true),
        ];
        // Lead cumulé = 10, Cycle cumulé = 5, Waiting total = 3 → Production = max(0, 5 − 3) = 2 j.
        $subject = $this->makeSubject(
            $releases,
            ['ABC-F' => $this->collectionStub(10.0, 5.0, 3.0, 3.0, 3.0)]
        );

        $item = $subject->getItems()[0];
        // Réalisation réelle = Production rapportée au Lead = 2 / 10 = 20 %.
        $this->assertSame(2.0, $item['realisationDays']);
        $this->assertSame(20.0, $item['realisationPct']);
        // Flow Efficiency = Production / Cycle = 2 / 5 = 40 % (identique à /release).
        $this->assertSame(2.0, $item['efficaciteDays']);
        $this->assertSame(40.0, $item['efficacitePct']);
    }

    #[Test]
    public function test_efficaciteFlow_sansCycleTime_realisationNAEtEfficaciteSurLead(): void
    {
        $releases = [
            $this->rawRelease('21', 'ABC-G', '2026-02-01', '2026-03-01', true),
        ];
        // Cycle cumulé = 0 → null (aucun ticket lié n'a de Cycle Time) : Lead cumulé = 10, Waiting total = 4.
        $subject = $this->makeSubject(
            $releases,
            ['ABC-G' => $this->collectionStub(10.0, 0.0, 4.0, 4.0, 4.0)]
        );

        $item = $subject->getItems()[0];
        $this->assertNull($item['cycleTime']);
        // Sans Cycle, la Production n'a pas de sens → Réalisation N/A (null).
        $this->assertNull($item['realisationDays']);
        $this->assertNull($item['realisationPct']);
        // L'efficacité se replie sur le Lead : (Lead − Waiting total) / Lead = (10 − 4) / 10 = 60 %.
        $this->assertSame(6.0, $item['efficaciteDays']);
        $this->assertSame(60.0, $item['efficacitePct']);
    }

    #[Test]
    public function test_version_utiliseLesCumulsDesTicketsLiesEtFlowEfficiency(): void
    {
        $versions = [
            $this->rawVersion('500', '2026-02-01', '2026-03-01', true),
        ];
        // Collection de la version : Lead cumulé 10, Cycle cumulé 5, Waiting total 3 → Production 2.
        $collections = [
            '500' => $this->collectionStub(10.0, 5.0, 3.0, 3.0, 3.0),
        ];

        $item = $this->makeSubject([], $collections, $versions)->getItems()[0];

        // Une Version utilise désormais les cumuls des tickets liés (et non startDate→releaseDate).
        $this->assertSame('version', $item['type']);
        $this->assertSame(10.0, $item['leadTime']);
        $this->assertSame(5.0, $item['cycleTime']);
        // Même Flow Efficiency que les Releases : Production / Cycle = 2 / 5 = 40 %.
        $this->assertSame(2.0, $item['efficaciteDays']);
        $this->assertSame(40.0, $item['efficacitePct']);
    }
}

/**
 * Doublure testable de CurrentPiReleases : neutralise les accès Jira coûteux (collections de tickets
 * liés, cache fichier) au profit de données injectées. Les stubs doivent être positionnés avant
 * l'appel au constructeur parent, qui orchestre tout le calcul.
 */
class TestableCurrentPiReleases extends CurrentPiReleases
{
    /** @var array<string, Version> */
    private array $collectionStubs;

    /**
     * @param string                 $projectKey
     * @param string                 $from
     * @param string                 $to
     * @param JiraService            $jira
     * @param array<array-key, Version> $collections Map idOrKey → collection mockée
     */
    public function __construct(string $projectKey, string $from, string $to, JiraService $jira, array $collections)
    {
        $this->collectionStubs = $collections;

        parent::__construct($projectKey, $from, $to, false, '', '', false, [], jira: $jira);
    }

    protected function newCollection(string $idOrKey): Version
    {
        return $this->collectionStubs[$idOrKey];
    }

    /**
     * Cache neutre : toujours un miss → newCollection() (stubbée) est appelée, sans aucune E/S disque.
     *
     * @return ReleaseMetricsCacheService
     */
    protected function makeCache(): ReleaseMetricsCacheService
    {
        return new class () extends ReleaseMetricsCacheService {
            public function __construct()
            {
            }
            public function get(string $type, string $id): ?Version
            {
                return null;
            }
            public function put(string $type, string $id, bool $isDone, Version $version): void
            {
            }
            public function bust(string $type, string $id): void
            {
            }
            public function save(): void
            {
            }
        };
    }
}
