<?php

namespace Tests\Unit\Model;

use App\Model\JiraUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du constructeur d'URLs Jira (normalisation base rtrim + segments rawurlencodés).
 */
class JiraUrlTest extends TestCase
{
    private ?string $previousBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBaseUrl = $_ENV['JIRA_BASE_URL'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousBaseUrl === null) {
            unset($_ENV['JIRA_BASE_URL']);
        } else {
            $_ENV['JIRA_BASE_URL'] = $this->previousBaseUrl;
        }
        parent::tearDown();
    }

    #[Test]
    public function test_browse_construitLUrlBrowse(): void
    {
        $_ENV['JIRA_BASE_URL'] = 'https://exemple.atlassian.net';

        $this->assertSame('https://exemple.atlassian.net/browse/ABC-123', JiraUrl::browse('ABC-123'));
    }

    #[Test]
    public function test_browse_normaliseLeSlashFinalDeLaBase(): void
    {
        // Un slash final sur JIRA_BASE_URL ne doit pas produire de double slash.
        $_ENV['JIRA_BASE_URL'] = 'https://exemple.atlassian.net/';

        $this->assertSame('https://exemple.atlassian.net/browse/ABC-123', JiraUrl::browse('ABC-123'));
    }

    #[Test]
    public function test_browse_cleVide_donneLePrefixe(): void
    {
        // Utilisé par la vue Métriques d'équipe : préfixe complété côté client.
        $_ENV['JIRA_BASE_URL'] = 'https://exemple.atlassian.net';

        $this->assertSame('https://exemple.atlassian.net/browse/', JiraUrl::browse(''));
    }

    #[Test]
    public function test_version_construitLUrlFixVersion(): void
    {
        $_ENV['JIRA_BASE_URL'] = 'https://exemple.atlassian.net';

        $this->assertSame(
            'https://exemple.atlassian.net/projects/11547/versions/19075',
            JiraUrl::version('11547', '19075')
        );
    }
}
