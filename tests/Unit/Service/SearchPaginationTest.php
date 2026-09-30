<?php

namespace Tests\Unit\Service;

use App\Service\JiraService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la pagination par curseur de JiraService::callSearchApiGet().
 *
 * L'endpoint de recherche plafonne chaque page à 100 issues et signale la suite par `nextPageToken`.
 * Sans boucle, toute recherche dépassant 100 résultats était tronquée SANS erreur — des Features ou des
 * Releases disparaissaient de l'analyse en silence. Le seam est request(), surchargée pour rejouer une
 * série de pages sans réseau.
 */
class SearchPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JIRA_BASE_URL']  ??= 'http://search-pagination-test.local';
        $_ENV['JIRA_EMAIL']     ??= 'test@example.com';
        $_ENV['JIRA_API_TOKEN'] ??= 'test-token';
    }

    #[Test]
    public function test_plusieursPages_sontConcatenees_jusquAuDernierCurseur(): void
    {
        $jira = new PagedJiraService([
            ['issues' => [['key' => 'ABC-1'], ['key' => 'ABC-2']], 'nextPageToken' => 'p2'],
            ['issues' => [['key' => 'ABC-3']],                     'nextPageToken' => 'p3'],
            ['issues' => [['key' => 'ABC-4']]], // plus de curseur → fin
        ]);

        $keys = array_column($jira->callSearchApiGet('project = ABC'), 'key');

        $this->assertSame(['ABC-1', 'ABC-2', 'ABC-3', 'ABC-4'], $keys);
        $this->assertSame(3, $jira->callCount);
    }

    #[Test]
    public function test_curseur_estRenvoyeALaRequeteSuivante(): void
    {
        $jira = new PagedJiraService([
            ['issues' => [['key' => 'ABC-1']], 'nextPageToken' => 'jeton-page-2'],
            ['issues' => [['key' => 'ABC-2']]],
        ]);

        $jira->callSearchApiGet('project = ABC');

        $this->assertStringNotContainsString('nextPageToken', $jira->urls[0], 'La 1re page ne porte pas de curseur');
        $this->assertStringContainsString('nextPageToken=jeton-page-2', $jira->urls[1]);
    }

    #[Test]
    public function test_pageUnique_neDeclenchePasDeSecondAppel(): void
    {
        $jira = new PagedJiraService([
            ['issues' => [['key' => 'ABC-1']]],
        ]);

        $jira->callSearchApiGet('project = ABC');

        $this->assertSame(1, $jira->callCount);
    }

    #[Test]
    public function test_post_pagineAussi_etRenvoieLeCurseurDansLeCorps(): void
    {
        // Régression vécue : une Release de 133 tickets n'en remontait que 100, faussant tous ses cumuls.
        // `maxResults` dans le payload ne lève PAS le plafond de 100 de l'endpoint.
        $jira = new PagedJiraService([
            ['issues' => [['key' => 'ABC-1']], 'nextPageToken' => 'p2'],
            ['issues' => [['key' => 'ABC-2']]],
        ]);

        $keys = array_column($jira->callSearchApiPost(['jql' => 'project = ABC', 'maxResults' => 133]), 'key');

        $this->assertSame(['ABC-1', 'ABC-2'], $keys);
        $this->assertStringNotContainsString('nextPageToken', (string) $jira->bodies[0]);
        $this->assertStringContainsString('"nextPageToken":"p2"', (string) $jira->bodies[1]);
    }

    #[Test]
    public function test_curseurRamenantUnePageVide_arreteLeParcours(): void
    {
        // Garde-fou anti boucle infinie : un curseur qui persiste mais ne ramène plus rien.
        $jira = new PagedJiraService([
            ['issues' => [['key' => 'ABC-1']], 'nextPageToken' => 'p2'],
            ['issues' => [],                   'nextPageToken' => 'p3'],
        ]);

        $keys = array_column($jira->callSearchApiGet('project = ABC'), 'key');

        $this->assertSame(['ABC-1'], $keys);
        $this->assertSame(2, $jira->callCount);
    }
}

/**
 * Doublure rejouant une file de réponses de recherche et enregistrant les URL appelées.
 */
class PagedJiraService extends JiraService
{
    public int $callCount = 0;

    /** @var list<string> */
    public array $urls = [];

    /** @var list<string|null> Corps JSON soumis (variante POST). */
    public array $bodies = [];

    /** @var list<array<string, mixed>> */
    private array $pages;

    /** @param list<array<string, mixed>> $pages */
    public function __construct(array $pages)
    {
        parent::__construct();
        $this->pages = $pages;
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $url, ?string $payload = null): array
    {
        $this->urls[]   = $url;
        $this->bodies[] = $payload;
        $this->callCount++;

        return array_shift($this->pages) ?? ['issues' => []];
    }
}
