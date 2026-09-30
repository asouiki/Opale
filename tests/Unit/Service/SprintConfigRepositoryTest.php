<?php

namespace Tests\Unit\Service;

use App\Service\SprintConfigRepository;
use App\Service\TeamConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la persistance des réglages par sprint (jours/homme).
 *
 * Le repository écrit un fichier JSON par projet (<KEY>_sprints_config.json). On
 * l'isole dans un répertoire temporaire injecté au constructeur pour ne jamais
 * toucher la vraie config du projet.
 */
class SprintConfigRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/sprint_config_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Nettoyage : supprime les fichiers générés puis le répertoire temporaire.
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function repo(): SprintConfigRepository
    {
        return new SprintConfigRepository($this->tmpDir);
    }

    /**
     * Persiste une grille de capacité minimale (un membre + un total capaTotal) pour un sprint.
     */
    private function seedCapacity(string $projectKey, string $sprintId, float $capaTotal): void
    {
        (new TeamConfigRepository($this->tmpDir))->setSprintCapacity($projectKey, $sprintId, [
            'members' => ['u1' => ['presence' => ['2025-01-01' => 0.5]]],
            'totals'  => [
                'presenceTotal' => $capaTotal,
                'capa'          => ['Dev' => $capaTotal, 'BA' => 0.0, 'QA' => 0.0],
                'capaTotal'     => $capaTotal,
            ],
        ]);
    }

    #[Test]
    public function test_getManDays_fichierAbsent_retourneNull(): void
    {
        $this->assertNull($this->repo()->getManDays('ABC', '10496'));
    }

    #[Test]
    public function test_getAll_fichierAbsent_retourneTableauVide(): void
    {
        $this->assertSame([], $this->repo()->getAll('ABC'));
    }

    #[Test]
    public function test_setManDays_creeLaValeur_etGetManDaysLaRelit(): void
    {
        $this->repo()->setManDays('ABC', '10496', 25.0);

        $this->assertSame(25.0, $this->repo()->getManDays('ABC', '10496'));
    }

    #[Test]
    public function test_setManDays_plusieursSprints_coexistentSansSeToucher(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setManDays('ABC', '10497', 28.0);

        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertSame(28.0, $repo->getManDays('ABC', '10497'));
    }

    #[Test]
    public function test_setManDays_valeurNull_effaceLaSaisieDuSprint(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setManDays('ABC', '10497', 28.0);

        $repo->setManDays('ABC', '10496', null);

        $this->assertNull($repo->getManDays('ABC', '10496'));
        // L'autre sprint n'est pas affecté par l'effacement.
        $this->assertSame(28.0, $repo->getManDays('ABC', '10497'));
    }

    #[Test]
    public function test_setManDays_valeurNegativeOuZero_effaceLaSaisie(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);

        $repo->setManDays('ABC', '10496', 0.0);
        $this->assertNull($repo->getManDays('ABC', '10496'));

        $repo->setManDays('ABC', '10496', -3.0);
        $this->assertNull($repo->getManDays('ABC', '10496'));
    }

    #[Test]
    public function test_setManDays_ecraseLaValeurPrecedente(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setManDays('ABC', '10496', 30.0);

        $this->assertSame(30.0, $repo->getManDays('ABC', '10496'));
    }

    #[Test]
    public function test_setManDays_isoleParProjet(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setManDays('XYZ', '10496', 12.0);

        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertSame(12.0, $repo->getManDays('XYZ', '10496'));
    }

    #[Test]
    public function test_getManDays_grilleCapacite_primeSurSaisieManuelle(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $this->seedCapacity('ABC', '10496', 18.5);

        $this->assertSame(18.5, $repo->getManDays('ABC', '10496'));
        $this->assertTrue($repo->hasCapacityManDays('ABC', '10496'));
    }

    #[Test]
    public function test_getManDays_grilleCapaciteSansSaisie_retourneCapaTotal(): void
    {
        $repo = $this->repo();
        $this->seedCapacity('ABC', '10496', 18.5);

        $this->assertSame(18.5, $repo->getManDays('ABC', '10496'));
    }

    #[Test]
    public function test_getManDays_sansGrille_repliSurSaisieManuelle(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);

        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertFalse($repo->hasCapacityManDays('ABC', '10496'));
    }

    #[Test]
    public function test_getManDays_capaTotalZero_repliSurSaisieManuelle(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $this->seedCapacity('ABC', '10496', 0.0);

        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertFalse($repo->hasCapacityManDays('ABC', '10496'));
    }

    #[Test]
    public function test_getAll_grilleCapacite_surchargeManDaysEtPositionneLeDrapeau(): void
    {
        $repo = $this->repo();
        $repo->setManDays('ABC', '10496', 25.0);
        $this->seedCapacity('ABC', '10496', 18.5);

        $this->assertSame(
            ['10496' => ['manDays' => 18.5, 'manDaysFromCapacity' => true]],
            $repo->getAll('ABC')
        );
    }
}
