<?php

namespace Tests\Unit\Controller;

use App\Controller\CapacityImportController;
use App\Service\ParsedImport;
use App\Service\TeamConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests de la fusion de présence bornée à la plage couverte (le fichier fait foi sur ses dates couvertes).
 *
 * Règles : sur les jours éditables couverts, une absence du fichier devient 0/0.5 et une date non listée
 * (présent) efface l'override existant ; les dates hors de cette liste ne sont jamais touchées.
 */
class CapacityImportControllerTest extends TestCase
{
    private function controller(): CapacityImportController
    {
        return new CapacityImportController();
    }

    #[Test]
    public function test_mergePresence_appliqueAbsenceEtDemiJournee(): void
    {
        $merge = $this->controller()->mergePresence(
            [],
            ['2026-06-16' => 0.0, '2026-06-17' => 0.5],
            ['2026-06-15', '2026-06-16', '2026-06-17']
        );

        // 06-15 vide (présent) → non stocké ; 06-16 absent ; 06-17 demi.
        $this->assertSame(['2026-06-16' => 0.0, '2026-06-17' => 0.5], $merge['presence']);
        $this->assertSame(['2026-06-16', '2026-06-17'], $merge['applied']);
    }

    #[Test]
    public function test_mergePresence_caseVideEffaceAbsenceManuelleDansLaPlage(): void
    {
        $merge = $this->controller()->mergePresence(
            ['2026-06-15' => 0.0], // absence saisie à la main
            [],                    // fichier : rien sur 06-15 (présent)
            ['2026-06-15']
        );

        $this->assertSame([], $merge['presence']);
        $this->assertSame([], $merge['applied']);
    }

    #[Test]
    public function test_mergePresence_horsPlagePreserve(): void
    {
        // 2026-07-01 (hors des dates éditables fournies) doit rester intact malgré l'import.
        $merge = $this->controller()->mergePresence(
            ['2026-07-01' => 0.0],
            ['2026-06-16' => 0.0],
            ['2026-06-15', '2026-06-16']
        );

        $this->assertSame(['2026-07-01' => 0.0, '2026-06-16' => 0.0], $merge['presence']);
        $this->assertSame(['2026-06-16'], $merge['applied']);
    }

    #[Test]
    public function test_apply_sprintCouvertSansAbsence_persisteGrillePleineCapacite(): void
    {
        $tmpDir = sys_get_temp_dir() . '/capimport_' . uniqid('', true);

        try {
            // Roster : une ressource Dev pleinement présente (aucune borne d'entrée/sortie).
            (new TeamConfigRepository($tmpDir))->setTeamMembers('ABC', [
                ['id' => 'm1', 'name' => 'Alice', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
            ]);

            // Fichier importé : Alice est lue mais SANS aucune absence sur le sprint couvert.
            $parsed = new ParsedImport(
                names: ['Alice'],
                absencesByName: [],
                coveredDates: $this->datesBetween('2026-06-15', '2026-06-26'),
                sheetsRead: ['Feuil1'],
            );
            $sprints = [['id' => '100', 'startDate' => '2026-06-15', 'endDate' => '2026-06-26']];

            (new ReflectionMethod(CapacityImportController::class, 'apply'))
                ->invoke(new CapacityImportController($tmpDir), 'ABC', $parsed, $sprints);

            // Régression : le sprint couvert sans absence doit persister une grille « tout le monde présent »
            // (capacité pleine) au lieu de voir sa grille supprimée.
            $capacity = (new TeamConfigRepository($tmpDir))->getSprintCapacity('ABC', '100');
            $this->assertArrayHasKey('totals', $capacity);
            $this->assertGreaterThan(0.0, $capacity['totals']['presenceTotal'] ?? 0.0);
            $this->assertGreaterThan(0.0, $capacity['totals']['capaTotal'] ?? 0.0);
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    #[Test]
    public function test_apply_cellulePrevisionnelle_persisteLeMarqueurSansChangerLaPresence(): void
    {
        $tmpDir = sys_get_temp_dir() . '/capimport_' . uniqid('', true);

        try {
            (new TeamConfigRepository($tmpDir))->setTeamMembers('ABC', [
                ['id' => 'm1', 'name' => 'Alice', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
            ]);

            // 06-16 « P » (prévisionnel) et 06-17 « A » : les deux valent 0, seul le 06-16 porte le marqueur.
            $parsed = new ParsedImport(
                names: ['Alice'],
                absencesByName: ['Alice' => [
                    ['date' => '2026-06-16', 'value' => 0.0, 'forecast' => true],
                    ['date' => '2026-06-17', 'value' => 0.0, 'forecast' => false],
                ]],
                coveredDates: $this->datesBetween('2026-06-15', '2026-06-26'),
                sheetsRead: ['Feuil1'],
            );
            $sprints = [['id' => '100', 'startDate' => '2026-06-15', 'endDate' => '2026-06-26']];

            (new ReflectionMethod(CapacityImportController::class, 'apply'))
                ->invoke(new CapacityImportController($tmpDir), 'ABC', $parsed, $sprints);

            $member = (new TeamConfigRepository($tmpDir))->getSprintCapacity('ABC', '100')['members']['m1'] ?? [];
            $this->assertSame(['2026-06-16' => 0.0, '2026-06-17' => 0.0], $member['presence'] ?? []);
            $this->assertSame(['2026-06-16'], $member['forecast'] ?? []);
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    #[Test]
    public function test_apply_jourPlusPrevisionnelDansLeFichier_retireLeMarqueur(): void
    {
        $tmpDir = sys_get_temp_dir() . '/capimport_' . uniqid('', true);

        try {
            $repo = new TeamConfigRepository($tmpDir);
            $repo->setTeamMembers('ABC', [
                ['id' => 'm1', 'name' => 'Alice', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
            ]);
            // Marqueur issu d'un import précédent, sur un jour que le nouveau fichier ne déclare plus prévisionnel.
            $repo->setSprintCapacity('ABC', '100', [
                'members' => ['m1' => ['presence' => ['2026-06-16' => 0.0], 'forecast' => ['2026-06-16']]],
                'totals'  => ['presenceTotal' => 9.0, 'capa' => ['Dev' => 9.0, 'BA' => 0.0, 'QA' => 0.0], 'capaTotal' => 9.0],
            ]);

            $parsed = new ParsedImport(
                names: ['Alice'],
                absencesByName: ['Alice' => [['date' => '2026-06-16', 'value' => 0.0, 'forecast' => false]]],
                coveredDates: $this->datesBetween('2026-06-15', '2026-06-26'),
                sheetsRead: ['Feuil1'],
            );
            $sprints = [['id' => '100', 'startDate' => '2026-06-15', 'endDate' => '2026-06-26']];

            (new ReflectionMethod(CapacityImportController::class, 'apply'))
                ->invoke(new CapacityImportController($tmpDir), 'ABC', $parsed, $sprints);

            $member = (new TeamConfigRepository($tmpDir))->getSprintCapacity('ABC', '100')['members']['m1'] ?? [];
            $this->assertSame(['2026-06-16' => 0.0], $member['presence'] ?? []);
            $this->assertArrayNotHasKey('forecast', $member);
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    /**
     * Liste toutes les dates ISO (Y-m-d) de la plage [start, end] incluse.
     *
     * @return list<string>
     */
    private function datesBetween(string $start, string $end): array
    {
        $dates = [];
        $day   = new \DateTimeImmutable($start);
        $last  = new \DateTimeImmutable($end);
        while ($day <= $last) {
            $dates[] = $day->format('Y-m-d');
            $day     = $day->modify('+1 day');
        }

        return $dates;
    }

    /** Supprime récursivement un répertoire temporaire de test. */
    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rmrf($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
