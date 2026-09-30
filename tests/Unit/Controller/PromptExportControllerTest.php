<?php

namespace Tests\Unit\Controller;

use App\Controller\PromptExportController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests de l'export du prompt IA : assemblage du contenu (prompt versionné + digest) et surtout
 * construction du nom de fichier, qui alimente l'en-tête Content-Disposition à partir de noms de
 * sprints Jira saisis librement (accents, espaces, guillemets, longueurs arbitraires).
 */
class PromptExportControllerTest extends TestCase
{
    private PromptExportController $controller;

    protected function setUp(): void
    {
        $this->controller = new PromptExportController();
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $method, array $args): string
    {
        $reflection = new ReflectionMethod(PromptExportController::class, $method);

        return (string) $reflection->invokeArgs($this->controller, $args);
    }

    // =====================================================================
    // buildFileName()
    // =====================================================================

    #[Test]
    public function test_buildFileName_sprintUnique_composeLeNomAttendu(): void
    {
        $name = $this->call('buildFileName', ['ABC', 'sprint', ['Sprint 42']]);

        $this->assertSame('prompt_abc_sprint_sprint-42.txt', $name);
    }

    #[Test]
    public function test_buildFileName_nomAccentue_estTranslittereEnAscii(): void
    {
        $name = $this->call('buildFileName', ['ABC', 'sprint', ['Sprint Été — Refonte Métriques']]);

        $this->assertSame('prompt_abc_sprint_sprint-ete-refonte-metriques.txt', $name);
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $name);
    }

    #[Test]
    public function test_buildFileName_caracteresDangereux_sontNeutralises(): void
    {
        // Un nom de sprint Jira contenant guillemet ou retour ligne ne doit pas pouvoir s'échapper
        // de l'en-tête Content-Disposition.
        $name = $this->call('buildFileName', ['ABC', 'sprint', ["Sprint \"X\"\r\nContent-Type: evil"]]);

        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString("\r", $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $name);
    }

    #[Test]
    public function test_buildFileName_plusieursSprints_lesConcatene(): void
    {
        $name = $this->call('buildFileName', ['ABC', 'sprints', ['Sprint 1', 'Sprint 2', 'Sprint 3']]);

        $this->assertSame('prompt_abc_sprints_sprint-1_sprint-2_sprint-3.txt', $name);
    }

    #[Test]
    public function test_buildFileName_selectionTropLongue_estTronqueeAvecUnSuffixeDeReste(): void
    {
        $names = array_map(static fn (int $i): string => 'Sprint Numero ' . $i . ' Refonte', range(1, 12));

        $name = $this->call('buildFileName', ['ABC', 'sprints', $names]);

        $this->assertLessThanOrEqual(120, strlen($name));
        $this->assertMatchesRegularExpression('/_et-\d+-autres\.txt$/', $name);
        // Le début de la sélection reste lisible : la troncature ne doit pas tout écraser.
        $this->assertStringContainsString('sprint-numero-1-refonte', $name);
    }

    #[Test]
    public function test_buildFileName_nomUniqueTresLong_estTronqueSansSuffixe(): void
    {
        $name = $this->call('buildFileName', ['ABC', 'sprint', [str_repeat('a', 300)]]);

        $this->assertLessThanOrEqual(120, strlen($name));
        $this->assertStringStartsWith('prompt_abc_sprint_', $name);
        $this->assertStringEndsWith('.txt', $name);
        $this->assertStringNotContainsString('-autres', $name);
    }

    #[Test]
    public function test_buildFileName_sansNomExploitable_retombeSurUnNomValide(): void
    {
        // Sprints sans nom (ou réduits à néant par le slug) : le nom doit rester un .txt valide.
        $name = $this->call('buildFileName', ['ABC', 'sprints', ['', '---']]);

        $this->assertSame('prompt_abc_sprints.txt', $name);
    }

    // =====================================================================
    // render()
    // =====================================================================

    #[Test]
    public function test_render_substitueLePerimetreEtConcateneLeDigest(): void
    {
        $content = $this->call('render', ['une sélection de 6 sprints', 'Nombre de sprints : 6']);

        $this->assertStringContainsString("issues d'une sélection de 6 sprints", $content);
        $this->assertStringNotContainsString('{{SCOPE}}', $content);
        $this->assertStringContainsString("## Métriques\n\nNombre de sprints : 6", $content);
        // Le prompt doit précéder les données : l'IA lit sa mission avant les chiffres.
        $this->assertLessThan(
            strpos($content, '## Métriques'),
            strpos($content, 'Scrum Master Senior')
        );
    }
}
