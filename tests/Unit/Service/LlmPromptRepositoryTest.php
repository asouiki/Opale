<?php

namespace Tests\Unit\Service;

use App\Service\LlmPromptRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests du chargement des prompts versionnés : présence effective des prompts livrés et refus
 * explicite (exception) d'un prompt manquant, pour que l'appelant ne parte jamais avec un prompt vide.
 */
class LlmPromptRepositoryTest extends TestCase
{
    #[Test]
    public function test_load_promptLivre_retourneSonContenu(): void
    {
        $content = (new LlmPromptRepository())->load('llm_sprint_prompt.md');

        $this->assertNotSame('', trim($content));
        $this->assertStringContainsString('Scrum Master', $content);
    }

    #[Test]
    public function test_load_fichierInexistant_leveUneException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt système IA introuvable ou vide');

        (new LlmPromptRepository())->load('llm_prompt_qui_nexiste_pas.md');
    }
}
