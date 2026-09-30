<?php

namespace Tests\Unit\Service;

use App\Service\LlmAnalysisCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cache fichier des synthèses IA.
 *
 * Le répertoire de stockage est une constante de classe : les tests écrivent donc dans le
 * vrai répertoire de cache, mais sous des identifiants uniques préfixés (phpunit-*) qui ne
 * peuvent entrer en collision avec un sprint réel, et sont supprimés en tearDown.
 */
class LlmAnalysisCacheServiceTest extends TestCase
{
    private const PROJECT_KEY = 'PHPUNIT';

    private string $sprintId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sprintId = 'phpunit-' . uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath());
        parent::tearDown();
    }

    #[Test]
    public function test_put_then_get_returns_analysis_model_and_extra_prompt(): void
    {
        $cache = new LlmAnalysisCacheService();
        $cache->put($this->sprintId, 'Synthèse du sprint.', 'mistral', self::PROJECT_KEY, 'Focus sur la vélocité');

        $hit = $cache->get($this->sprintId, self::PROJECT_KEY);

        $this->assertNotNull($hit);
        $this->assertSame('Synthèse du sprint.', $hit['analysis']);
        $this->assertSame('mistral', $hit['model']);
        $this->assertSame('Focus sur la vélocité', $hit['extraPrompt']);
        $this->assertGreaterThan(0, $hit['cached_at']);
    }

    #[Test]
    public function test_put_without_extra_prompt_returns_empty_string(): void
    {
        $cache = new LlmAnalysisCacheService();
        $cache->put($this->sprintId, 'Synthèse du sprint.', 'mistral', self::PROJECT_KEY);

        $hit = $cache->get($this->sprintId, self::PROJECT_KEY);

        $this->assertNotNull($hit);
        $this->assertSame('', $hit['extraPrompt']);
    }

    #[Test]
    public function test_get_legacy_entry_without_extra_prompt_field_returns_empty_string(): void
    {
        // Entrée écrite avant l'ajout du champ : elle doit rester lisible (pas d'invalidation).
        $this->writeRawCache([
            'cached_at'        => time(),
            'analysis_version' => LlmAnalysisCacheService::ANALYSIS_VERSION,
            'model'            => 'mistral',
            'analysis'         => 'Ancienne synthèse.',
        ]);

        $hit = (new LlmAnalysisCacheService())->get($this->sprintId, self::PROJECT_KEY);

        $this->assertNotNull($hit);
        $this->assertSame('Ancienne synthèse.', $hit['analysis']);
        $this->assertSame('', $hit['extraPrompt']);
    }

    #[Test]
    public function test_get_with_obsolete_analysis_version_returns_null(): void
    {
        $this->writeRawCache([
            'cached_at'        => time(),
            'analysis_version' => LlmAnalysisCacheService::ANALYSIS_VERSION + 1,
            'model'            => 'mistral',
            'analysis'         => 'Synthèse produite par une autre version.',
        ]);

        $this->assertNull((new LlmAnalysisCacheService())->get($this->sprintId, self::PROJECT_KEY));
    }

    #[Test]
    public function test_get_without_cache_file_returns_null(): void
    {
        $this->assertNull((new LlmAnalysisCacheService())->get($this->sprintId, self::PROJECT_KEY));
    }

    /**
     * Écrit un contenu de cache brut, pour simuler une entrée non produite par put().
     *
     * @param array<string, int|string> $content
     */
    private function writeRawCache(array $content): void
    {
        if (!is_dir(LlmAnalysisCacheService::CACHE_DIR)) {
            mkdir(LlmAnalysisCacheService::CACHE_DIR, 0o777, true);
        }
        file_put_contents($this->cachePath(), json_encode($content, JSON_THROW_ON_ERROR));
    }

    /** Reproduit la convention de nommage interne du service. */
    private function cachePath(): string
    {
        $safeId = preg_replace('/[^a-z0-9_-]/i', '', $this->sprintId);

        return LlmAnalysisCacheService::CACHE_DIR . '/analysis_' . self::PROJECT_KEY . '_' . $safeId . '.json';
    }
}
