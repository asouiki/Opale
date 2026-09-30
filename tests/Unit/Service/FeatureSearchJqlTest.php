<?php

namespace Tests\Unit\Service;

use App\Service\JiraService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du JQL construit par JiraService::getFeaturesByProject().
 *
 * C'est la pièce la plus risquée de la sélection des Features : un JQL malformé casse l'onglet entier,
 * et une clause trop restrictive fait disparaître des lignes en silence. Le seam est la méthode publique
 * callSearchApiGet(), surchargée ici pour capturer la requête sans aucun accès réseau.
 */
class FeatureSearchJqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Le constructeur de JiraService lit ces variables (sans appel réseau).
        $_ENV['JIRA_BASE_URL']  ??= 'http://feature-jql-test.local';
        $_ENV['JIRA_EMAIL']     ??= 'test@example.com';
        $_ENV['JIRA_API_TOKEN'] ??= 'test-token';
    }

    #[Test]
    public function test_avecStatuts_filtreSurLesEntreesDansCesStatuts(): void
    {
        $jira = new RecordingJiraService();
        $jira->getFeaturesByProject('ABC', '', '2026-01-12', '2026-08-31', ['En cours', 'Terminé(e)']);

        $this->assertStringContainsString(
            'status CHANGED TO ("En cours", "Terminé(e)") DURING ("2026-01-12", "2026-08-31 23:59")',
            $jira->lastJql
        );
        // La borne glissante arbitraire a disparu au profit de la fenêtre demandée.
        $this->assertStringNotContainsString('created >= -365d', $jira->lastJql);
    }

    #[Test]
    public function test_sansStatutsExploitables_repliSurUnJqlSansValeurAValider(): void
    {
        // Statuts indisponibles (Jira injoignable) : plutôt que d'échouer, on élargit. Un JQL sans valeur
        // ne peut pas être refusé pour cause de libellé inconnu.
        $jira = new RecordingJiraService();
        $jira->getFeaturesByProject('ABC', '', '2026-01-12', '2026-08-31');

        $this->assertStringContainsString(
            'status CHANGED DURING ("2026-01-12", "2026-08-31 23:59")',
            $jira->lastJql
        );
        $this->assertStringNotContainsString('CHANGED TO', $jira->lastJql);
    }

    #[Test]
    public function test_champNonSearchable_jamaisDansLeJql(): void
    {
        // Régressions vécues : `customfield_10244` et `statuscategorychangedate` font échouer la requête
        // ENTIÈRE en 400, soit zéro Feature affichée.
        $jira = new RecordingJiraService();
        $jira->getFeaturesByProject('ABC', 'Tesa S', '2026-01-12', '2026-08-31', ['En cours']);

        $this->assertStringNotContainsString('statuscategorychangedate', $jira->lastJql);
        // Champ personnalisé : forme JQL `cf[id]`, jamais l'identifiant REST `customfield_id`.
        $this->assertStringNotContainsString('customfield_', $jira->lastJql);
        $this->assertStringContainsString('cf[10244] = "Tesa S"', $jira->lastJql);
    }

    #[Test]
    public function test_sansFenetre_conserveLaBorneDeFraicheur(): void
    {
        // Cas du sélecteur de la page /feature : pas de période, juste les Features récentes.
        $jira = new RecordingJiraService();
        $jira->getFeaturesByProject('ABC');

        $this->assertStringContainsString('created >= -365d', $jira->lastJql);
        $this->assertStringNotContainsString('CHANGED TO', $jira->lastJql);
    }

    #[Test]
    public function test_datesInvalides_neProduisentAucuneClauseDeFenetre(): void
    {
        $jira = new RecordingJiraService();
        $jira->getFeaturesByProject('ABC', '', 'hier', '2026-08-31');

        // Plutôt qu'un JQL approximatif, on retombe sur la borne de fraîcheur.
        $this->assertStringContainsString('created >= -365d', $jira->lastJql);
        $this->assertStringNotContainsString('DURING', $jira->lastJql);
    }
}

/**
 * Doublure capturant le JQL soumis, sans aucun appel réseau.
 */
class RecordingJiraService extends JiraService
{
    public string $lastJql = '';

    /**
     * @param  string[]             $fields
     * @return array<int, mixed>            Aucune issue : seul le JQL soumis nous intéresse
     */
    public function callSearchApiGet(string $jql, array $fields = [], int $maxResults = 100): array
    {
        $this->lastJql = $jql;

        return [];
    }
}
