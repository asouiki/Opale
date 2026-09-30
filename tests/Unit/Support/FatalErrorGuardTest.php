<?php

namespace Tests\Unit\Support;

use App\Support\FatalErrorGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique pure du filet anti-fatal (FatalErrorGuard) :
 * - isFatal()     : distingue les erreurs qui interrompent le script des warnings/notices bénins
 * - userMessage() : traduit le message technique en message actionnable (temps / mémoire / générique)
 *
 * Le handler de shutdown lui-même (headers, echo, rendu HTML) relève d'un test d'intégration.
 */
class FatalErrorGuardTest extends TestCase
{
    #[Test]
    public function test_isFatal_typesFataux_retourneTrue(): void
    {
        $this->assertTrue(FatalErrorGuard::isFatal(E_ERROR));       // ex: max_execution_time / mémoire
        $this->assertTrue(FatalErrorGuard::isFatal(E_PARSE));
        $this->assertTrue(FatalErrorGuard::isFatal(E_CORE_ERROR));
        $this->assertTrue(FatalErrorGuard::isFatal(E_COMPILE_ERROR));
        $this->assertTrue(FatalErrorGuard::isFatal(E_USER_ERROR));
    }

    #[Test]
    public function test_isFatal_typesNonFataux_retourneFalse(): void
    {
        $this->assertFalse(FatalErrorGuard::isFatal(E_WARNING));
        $this->assertFalse(FatalErrorGuard::isFatal(E_NOTICE));
        $this->assertFalse(FatalErrorGuard::isFatal(E_DEPRECATED));
        $this->assertFalse(FatalErrorGuard::isFatal(E_USER_WARNING));
    }

    #[Test]
    public function test_userMessage_depassementTemps_messageSprints(): void
    {
        $message = FatalErrorGuard::userMessage('Maximum execution time of 30 seconds exceeded', '/team-metrics');

        $this->assertStringContainsString('temps maximum', $message);
        $this->assertStringContainsString('sprints', $message);
    }

    #[Test]
    public function test_userMessage_depassementMemoire_messageMemoire(): void
    {
        $message = FatalErrorGuard::userMessage('Allowed memory size of 134217728 bytes exhausted', '/team-metrics');

        $this->assertStringContainsString('mémoire', $message);
        $this->assertStringContainsString('sprints', $message);
    }

    #[Test]
    public function test_userMessage_depassementTempsSurAnalyseLlm_messageSansConseilSprints(): void
    {
        $message = FatalErrorGuard::userMessage(
            'Maximum execution time of 30 seconds exceeded',
            '/api/internal/projects/ABC/sprints/42/analysis'
        );

        $this->assertStringContainsString('temps maximum', $message);
        $this->assertStringContainsString('modèle local', $message);
        $this->assertStringNotContainsString('sprints', $message);
    }

    #[Test]
    public function test_userMessage_analyseLlmAvecQueryString_messageSansConseilSprints(): void
    {
        $message = FatalErrorGuard::userMessage(
            'Maximum execution time of 30 seconds exceeded',
            '/api/internal/projects/ABC/team-metrics/analysis?debug=1'
        );

        $this->assertStringContainsString('modèle local', $message);
    }

    #[Test]
    public function test_userMessage_autreFatal_messageGenerique(): void
    {
        $message = FatalErrorGuard::userMessage('Call to undefined function foo()', '/sprint');

        $this->assertStringContainsString('erreur inattendue', $message);
    }
}
