<?php

namespace Tests\Unit\Service;

use App\Service\JiraService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests du préchargement groupé des tickets enfants (JiraService::prefetchChildrenByParent()).
 *
 * Enjeu : l'onglet Features instanciait une Feature par ligne, chacune déclenchant son propre
 * `parent = {key}`. À cache froid sur une fenêtre large, ces requêtes sérialisées faisaient dépasser
 * max_execution_time. Le préchargement les remplace par une requête `parent IN (...)` unique.
 *
 * Les deux propriétés à ne jamais casser :
 *   1. la répartition — chaque Feature ne doit recevoir QUE ses propres enfants ;
 *   2. le repli — une Feature non préchargée doit continuer à fonctionner par requête unitaire,
 *      sans quoi tout appelant hors de l'onglet (page /feature) perdrait ses tickets.
 *
 * Le seam est callSearchApiPost(), surchargé ici pour rejouer des réponses sans aucun accès réseau.
 */
class ParentPrefetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Le constructeur de JiraService lit ces variables (sans appel réseau).
        $_ENV['JIRA_BASE_URL']  ??= 'http://parent-prefetch-test.local';
        $_ENV['JIRA_EMAIL']     ??= 'test@example.com';
        $_ENV['JIRA_API_TOKEN'] ??= 'test-token';
    }

    protected function tearDown(): void
    {
        // La table de préchargement est STATIQUE (cf. JiraService) : sans ce reset, un test hériterait
        // des enfants préchargés par le précédent et passerait pour de mauvaises raisons.
        $prop = new ReflectionProperty(JiraService::class, 'prefetchedChildren');
        $prop->setValue(null, []);

        parent::tearDown();
    }

    /**
     * Fabrique une issue brute minimale, rattachée à un parent.
     *
     * `changelog.total <= count(histories)` pour que completeIssueChangelog() considère l'historique
     * complet et ne déclenche aucune requête supplémentaire.
     *
     * @return array<string, mixed>
     */
    private function issue(string $key, string $parentKey): array
    {
        return [
            'id'        => (string) crc32($key),
            'key'       => $key,
            'fields'    => [
                'summary' => 'Ticket ' . $key,
                'parent'  => ['key' => $parentKey],
            ],
            'changelog' => ['total' => 0, 'histories' => []],
        ];
    }

    #[Test]
    public function test_prefetch_chaqueFeatureNeRecoitQueSesPropresEnfants(): void
    {
        $jira = new PrefetchRecordingJiraService();
        $jira->nextIssues = [
            $this->issue('ABC-10', 'ABC-1'),
            $this->issue('ABC-11', 'ABC-1'),
            $this->issue('ABC-20', 'ABC-2'),
        ];

        $jira->prefetchChildrenByParent(['ABC-1', 'ABC-2']);

        $this->assertSame(['ABC-10', 'ABC-11'], array_column($jira->getIssuesByParent('ABC-1'), 'key'));
        $this->assertSame(['ABC-20'], array_column($jira->getIssuesByParent('ABC-2'), 'key'));
    }

    #[Test]
    public function test_prefetch_uneSeuleRequetePourPlusieursFeatures(): void
    {
        $jira = new PrefetchRecordingJiraService();
        $jira->nextIssues = [$this->issue('ABC-10', 'ABC-1')];

        $jira->prefetchChildrenByParent(['ABC-1', 'ABC-2', 'ABC-3']);
        $jira->getIssuesByParent('ABC-1');
        $jira->getIssuesByParent('ABC-2');
        $jira->getIssuesByParent('ABC-3');

        // Une requête groupée, et AUCUNE requête unitaire ensuite : c'est tout l'objet du lot.
        $this->assertCount(1, $jira->submittedJql);
        $this->assertStringContainsString('parent IN (', $jira->submittedJql[0]);
    }

    #[Test]
    public function test_prefetch_featureSansEnfant_neRetombePasSurUneRequeteUnitaire(): void
    {
        // Piège : sans marquage explicite des parents préchargés, une Feature sans enfant serait
        // indiscernable d'une Feature non préchargée et redéclencherait une requête — soit exactement
        // le N+1 qu'on supprime, sur le pire cas (fenêtre pleine de Features sans tickets).
        $jira = new PrefetchRecordingJiraService();
        $jira->nextIssues = [];

        $jira->prefetchChildrenByParent(['ABC-1']);

        $this->assertSame([], $jira->getIssuesByParent('ABC-1'));
        $this->assertCount(1, $jira->submittedJql);
    }

    #[Test]
    public function test_sansPrefetch_retombeSurLaRequeteUnitaire(): void
    {
        // Comportement historique préservé : la page /feature n'utilise pas le préchargement.
        $jira = new PrefetchRecordingJiraService();
        $jira->nextIssues = [$this->issue('ABC-10', 'ABC-1')];

        $issues = $jira->getIssuesByParent('ABC-1');

        $this->assertSame(['ABC-10'], array_column($issues, 'key'));
        $this->assertCount(1, $jira->submittedJql);
        $this->assertStringContainsString('parent = ABC-1', $jira->submittedJql[0]);
    }

    #[Test]
    public function test_prefetch_parentNonPrecharge_resteServiParRequeteUnitaire(): void
    {
        $jira = new PrefetchRecordingJiraService();
        $jira->nextIssues = [$this->issue('ABC-10', 'ABC-1')];
        $jira->prefetchChildrenByParent(['ABC-1']);

        $jira->nextIssues = [$this->issue('ABC-90', 'ABC-9')];
        $issues = $jira->getIssuesByParent('ABC-9');

        $this->assertSame(['ABC-90'], array_column($issues, 'key'));
        $this->assertCount(2, $jira->submittedJql);
        $this->assertStringContainsString('parent = ABC-9', $jira->submittedJql[1]);
    }

    #[Test]
    public function test_prefetch_listeVide_neDeclencheAucuneRequete(): void
    {
        $jira = new PrefetchRecordingJiraService();

        $jira->prefetchChildrenByParent([]);

        $this->assertSame([], $jira->submittedJql);
    }
}

/**
 * Doublure rejouant une réponse de recherche fixe et enregistrant les JQL soumis, sans accès réseau.
 */
class PrefetchRecordingJiraService extends JiraService
{
    /** @var list<string> JQL soumis, dans l'ordre. */
    public array $submittedJql = [];

    /** @var array<int, array<string, mixed>> Issues renvoyées par la prochaine recherche. */
    public array $nextIssues = [];

    /**
     * @param  array<string, mixed>            $payload
     * @return array<int, array<string, mixed>>
     */
    public function callSearchApiPost(array $payload): array
    {
        $this->submittedJql[] = (string) ($payload['jql'] ?? '');

        return $this->nextIssues;
    }
}
