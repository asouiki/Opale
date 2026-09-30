<?php

namespace Tests\Unit\Service;

use App\Service\JiraService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests du préchargement groupé des métadonnées de Features (JiraService::prefetchFeatureDetails())
 * et de la complétion du changelog de la Feature elle-même.
 *
 * Enjeu perf : l'onglet Features appelait getIssueById() une fois par ligne. Sur une fin de PI (53
 * Features clôturées la même semaine, cas réel), ces requêtes sérialisées faisaient dépasser
 * max_execution_time à cache froid.
 *
 * Enjeu correction : getIssueById() ne complétait PAS le changelog, contrairement aux tickets enfants.
 * Une Feature très éditée voyait donc ses métriques propres (lead/cycle time) calculées sur un
 * historique tronqué. La complétion est appliquée aux DEUX chemins — préchargé et unitaire — sans quoi
 * une même Feature afficherait des chiffres différents selon qu'on passe par l'onglet ou par /feature,
 * alors que les deux partagent le même cache.
 */
class FeatureDetailsPrefetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Le constructeur de JiraService lit ces variables (sans appel réseau).
        $_ENV['JIRA_BASE_URL']  ??= 'http://feature-details-test.local';
        $_ENV['JIRA_EMAIL']     ??= 'test@example.com';
        $_ENV['JIRA_API_TOKEN'] ??= 'test-token';
    }

    protected function tearDown(): void
    {
        // Table STATIQUE : sans reset, un test hériterait des Features préchargées par le précédent.
        $prop = new ReflectionProperty(JiraService::class, 'prefetchedFeatureDetails');
        $prop->setValue(null, []);

        parent::tearDown();
    }

    /**
     * Feature brute minimale.
     *
     * @param  int                  $total     Taille annoncée du changelog par Jira
     * @param  int                  $returned  Nombre d'entrées réellement renvoyées
     * @return array<string, mixed>
     */
    private function feature(string $key, int $total = 0, int $returned = 0): array
    {
        return [
            'id'        => (string) crc32($key),
            'key'       => $key,
            'fields'    => ['summary' => 'Feature ' . $key],
            'changelog' => [
                'total'     => $total,
                'histories' => array_fill(0, $returned, ['id' => 'h', 'items' => []]),
            ],
        ];
    }

    #[Test]
    public function test_prefetch_sertLesMetadonneesSansRequeteUnitaire(): void
    {
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssues = [$this->feature('ABC-1'), $this->feature('ABC-2')];

        $jira->prefetchFeatureDetails(['ABC-1', 'ABC-2']);

        $this->assertSame('ABC-1', $jira->getIssueById('ABC-1')['key']);
        $this->assertSame('ABC-2', $jira->getIssueById('ABC-2')['key']);
        // Aucun aller-retour unitaire : c'est tout l'objet du lot.
        $this->assertSame([], $jira->requestedUrls);
    }

    #[Test]
    public function test_prefetch_uneSeuleRecherchePourPlusieursFeatures(): void
    {
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssues = [$this->feature('ABC-1')];

        $jira->prefetchFeatureDetails(['ABC-1', 'ABC-2', 'ABC-3']);

        $this->assertCount(1, $jira->submittedJql);
        $this->assertStringContainsString('key IN (', $jira->submittedJql[0]);
    }

    #[Test]
    public function test_sansPrefetch_retombeSurLaRequeteUnitaire(): void
    {
        // Comportement historique préservé : la page /feature ne précharge pas.
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssue = $this->feature('ABC-9');

        $this->assertSame('ABC-9', $jira->getIssueById('ABC-9')['key']);
        $this->assertCount(1, $jira->requestedUrls);
    }

    #[Test]
    public function test_prefetch_featureAbsenteDuLot_resteServieParRequeteUnitaire(): void
    {
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssues = [$this->feature('ABC-1')];
        $jira->prefetchFeatureDetails(['ABC-1']);

        $jira->nextIssue = $this->feature('ABC-9');

        $this->assertSame('ABC-9', $jira->getIssueById('ABC-9')['key']);
        $this->assertCount(1, $jira->requestedUrls);
    }

    #[Test]
    public function test_changelogTronque_estCompleteSurLeCheminPrecharge(): void
    {
        $jira = new DetailsRecordingJiraService();
        // Jira annonce 3 entrées mais n'en renvoie qu'une : historique tronqué.
        $jira->nextIssues    = [$this->feature('ABC-1', total: 3, returned: 1)];
        $jira->nextChangelog = [['id' => '1'], ['id' => '2'], ['id' => '3']];

        $jira->prefetchFeatureDetails(['ABC-1']);

        $this->assertCount(3, $jira->getIssueById('ABC-1')['changelog']['histories']);
    }

    #[Test]
    public function test_changelogTronque_estCompleteSurLeCheminUnitaire(): void
    {
        // Cohérence indispensable : onglet et page /feature partagent le même cache de métriques.
        // Deux chemins produisant des historiques différents feraient osciller les chiffres selon la
        // page consultée en premier.
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssue     = $this->feature('ABC-9', total: 3, returned: 1);
        $jira->nextChangelog = [['id' => '1'], ['id' => '2'], ['id' => '3']];

        $this->assertCount(3, $jira->getIssueById('ABC-9')['changelog']['histories']);
    }

    #[Test]
    public function test_changelogComplet_neDeclencheAucuneRequeteSupplementaire(): void
    {
        $jira = new DetailsRecordingJiraService();
        $jira->nextIssues = [$this->feature('ABC-1', total: 2, returned: 2)];

        $jira->prefetchFeatureDetails(['ABC-1']);

        $this->assertSame([], $jira->requestedUrls);
    }

    #[Test]
    public function test_prefetch_listeVide_neDeclencheAucuneRecherche(): void
    {
        $jira = new DetailsRecordingJiraService();

        $jira->prefetchFeatureDetails([]);

        $this->assertSame([], $jira->submittedJql);
    }
}

/**
 * Doublure rejouant recherches et requêtes unitaires, sans aucun accès réseau.
 */
class DetailsRecordingJiraService extends JiraService
{
    /** @var list<string> JQL soumis, dans l'ordre. */
    public array $submittedJql = [];

    /** @var list<string> URL appelées en requête unitaire (issue ou changelog). */
    public array $requestedUrls = [];

    /** @var array<int, array<string, mixed>> Issues renvoyées par la prochaine recherche. */
    public array $nextIssues = [];

    /** @var array<string, mixed> Issue renvoyée par la prochaine requête unitaire. */
    public array $nextIssue = [];

    /** @var array<int, array<string, mixed>> Entrées renvoyées par l'endpoint changelog. */
    public array $nextChangelog = [];

    /**
     * @param  array<string, mixed>            $payload
     * @return array<int, array<string, mixed>>
     */
    public function callSearchApiPost(array $payload): array
    {
        $this->submittedJql[] = (string) ($payload['jql'] ?? '');

        return $this->nextIssues;
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $url, ?string $payload = null): array
    {
        $this->requestedUrls[] = $url;

        if (str_contains($url, '/changelog')) {
            return ['values' => $this->nextChangelog, 'isLast' => true];
        }

        return $this->nextIssue;
    }
}
