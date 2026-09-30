<?php

namespace Tests\Unit\Service;

use App\Service\DayEventsPropagator;
use App\Service\TeamConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du report du modèle d'événements (colonne « Évén. » de /capacity) sur les futurs sprints.
 *
 * Sprint source de référence : lun 05/01 → ven 16/01/2026 (10 jours lun→ven), avec 0,5 le 1er jour, 0,5 le mercredi de la
 * 2e semaine (position 7) et 1 le dernier jour (position 9).
 */
class DayEventsPropagatorTest extends TestCase
{
    /** @var array<string, float> */
    private const SOURCE_EVENTS = ['2026-01-05' => 0.5, '2026-01-14' => 0.5, '2026-01-16' => 1.0];

    #[Test]
    public function test_project_sprintDeMemeDuree_reporteLesEvenementsPositionParPosition(): void
    {
        $events = $this->propagator([])->project(self::SOURCE_EVENTS, '2026-01-05', '2026-01-16', '2026-01-19', '2026-01-30');

        $this->assertSame(['2026-01-19' => 0.5, '2026-01-28' => 0.5, '2026-01-30' => 1.0], $events);
    }

    #[Test]
    public function test_project_sprintCibleDemarrantUnJeudi_conserveLesPositions(): void
    {
        // jeu 22/01 → mer 04/02 : 10 jours lun→ven, dernier jour = mer 04/02.
        $events = $this->propagator([])->project(self::SOURCE_EVENTS, '2026-01-05', '2026-01-16', '2026-01-22', '2026-02-04');

        $this->assertSame(['2026-01-22' => 0.5, '2026-02-02' => 0.5, '2026-02-04' => 1.0], $events);
    }

    #[Test]
    public function test_project_jourCibleFerie_ignoreLEvenement(): void
    {
        // Lundi de Pâques 06/04/2026 = 1er jour du sprint cible → l'événement de la position 0 est perdu.
        $events = $this->propagator(['2026-04-06'])->project(self::SOURCE_EVENTS, '2026-01-05', '2026-01-16', '2026-04-06', '2026-04-17');

        $this->assertSame(['2026-04-15' => 0.5, '2026-04-17' => 1.0], $events);
    }

    #[Test]
    public function test_project_dureeDifferente_retourneNull(): void
    {
        $events = $this->propagator([])->project(self::SOURCE_EVENTS, '2026-01-05', '2026-01-16', '2026-02-02', '2026-02-06');

        $this->assertNull($events);
    }

    #[Test]
    public function test_project_sourceSansEvenement_retourneListeVide(): void
    {
        $events = $this->propagator([])->project([], '2026-01-05', '2026-01-16', '2026-01-19', '2026-01-30');

        $this->assertSame([], $events);
    }

    #[Test]
    public function test_propagate_remplaceLesEvenementsEtRecalculeLesTotaux_ignoreLesDureesDifferentes(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dayevents_' . uniqid('', true);

        try {
            $repo = new TeamConfigRepository($tmpDir);
            $repo->setTeamMembers('ABC', [
                ['id' => 'm1', 'name' => 'Alice', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
            ]);
            // Grille existante du sprint cible : une absence (préservée) et un événement (remplacé).
            $repo->setSprintCapacity('ABC', '200', [
                'members'   => ['m1' => ['presence' => ['2026-01-21' => 0.0]]],
                'dayEvents' => ['2026-01-20' => 1.0],
            ]);

            $result = (new DayEventsPropagator(new TeamConfigRepository($tmpDir), []))->propagate('ABC', self::SOURCE_EVENTS, '2026-01-05', '2026-01-16', [
                ['id' => '200', 'startDate' => '2026-01-19', 'endDate' => '2026-01-30'],
                ['id' => '300', 'startDate' => '2026-02-02', 'endDate' => '2026-02-06'],
            ]);

            $this->assertSame(['applied' => ['200'], 'skipped' => ['300']], $result);

            $capacity = (new TeamConfigRepository($tmpDir))->getSprintCapacity('ABC', '200');
            $this->assertSame(['2026-01-19' => 0.5, '2026-01-28' => 0.5, '2026-01-30' => 1.0], $capacity['dayEvents'] ?? []);
            $this->assertSame(['2026-01-21' => 0.0], $capacity['members']['m1']['presence'] ?? []);
            // 10 jours − 1 absence = 9 de présence ; événements 0,5 + 0,5 + 1 = 2 → capa 7.
            $this->assertSame(
                ['presenceTotal' => 9.0, 'capa' => ['Dev' => 7.0, 'BA' => 0.0, 'QA' => 0.0], 'capaTotal' => 7.0],
                $capacity['totals'] ?? null
            );
            $this->assertSame([], (new TeamConfigRepository($tmpDir))->getSprintCapacity('ABC', '300'));
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    /**
     * @param list<string> $holidays
     */
    private function propagator(array $holidays): DayEventsPropagator
    {
        return new DayEventsPropagator(new TeamConfigRepository(sys_get_temp_dir() . '/dayevents_unused'), $holidays);
    }

    /** Supprime récursivement un répertoire temporaire de test. */
    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
