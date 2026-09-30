<?php

namespace Tests\Unit\Service;

use App\Service\CapacityCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du recalcul serveur des totaux de capacité (miroir de la logique JS de l'onglet Capacité).
 *
 * Un scénario unique couvre l'essentiel des règles : ratio ≠ 100, demi-journée, événement plafonné par la
 * présence, exclusion PO de la capa (mais pas de la présence), membre sorti pendant le sprint (présent le
 * jour même de la sortie, borne incluse), membre entré en cours de sprint (tenure), et jour férié exclu.
 */
class CapacityCalculatorTest extends TestCase
{
    #[Test]
    public function test_computeTotals_scenarioComplet(): void
    {
        // Fenêtre lun 05/01 → ven 09/01 2026 ; 09/01 férié → jours ouvrés retenus : 05, 06, 07, 08.
        $members = [
            ['id' => 'a', 'role' => 'Dev'],                            // ratio 80, présent tous les jours
            ['id' => 'b', 'role' => 'BA'],                             // absent le 06, demi le 07
            ['id' => 'c', 'role' => 'PO'],                             // présent, hors capa
            ['id' => 'd', 'role' => 'QA', 'exitDate' => '2026-01-05'], // sortie incluse → présent seulement le 05
            ['id' => 'e', 'role' => 'Dev', 'entryDate' => '2026-01-07'], // arrive le 07
        ];
        $capacity = [
            'members' => [
                'a' => ['ratio' => 80.0],
                'b' => ['presence' => ['2026-01-06' => 0.0, '2026-01-07' => 0.5]],
            ],
            'dayEvents' => ['2026-01-08' => 1.0],
        ];

        $totals = (new CapacityCalculator())->computeTotals(
            $members,
            $capacity,
            '2026-01-05',
            '2026-01-09',
            ['2026-01-09']
        );

        // Présence : Alice 4 + Bob 2.5 + Carol 4 + Dave 1 (05 uniquement, sortie incluse) + Eve 2 = 13.5.
        $this->assertEqualsWithDelta(13.5, $totals['presenceTotal'], 0.001);
        // Capa Dev : Alice (4-1)*0.8 = 2.4 + Eve (2-1)*1 = 1.0 → 3.4.
        $this->assertEqualsWithDelta(3.4, $totals['capa']['Dev'], 0.001);
        // Capa BA : Bob (2.5-1) = 1.5.
        $this->assertEqualsWithDelta(1.5, $totals['capa']['BA'], 0.001);
        // Capa QA : Dave 1 jour, aucun événement le 05 → 1.0.
        $this->assertEqualsWithDelta(1.0, $totals['capa']['QA'], 0.001);
        $this->assertEqualsWithDelta(5.9, $totals['capaTotal'], 0.001);
    }

    #[Test]
    public function test_computeTotals_grilleVide_presenceMaxEtCapaEgalePresence(): void
    {
        // Sans absence ni événement : chaque membre à capacité pleine (ratio 100 par défaut).
        $members = [['id' => 'a', 'role' => 'Dev'], ['id' => 'b', 'role' => 'QA']];

        $totals = (new CapacityCalculator())->computeTotals($members, [], '2026-01-05', '2026-01-06', []);

        // 2 jours ouvrés (lun+mar) × 2 membres = 4 jours présence ; capa = présence.
        $this->assertEqualsWithDelta(4.0, $totals['presenceTotal'], 0.001);
        $this->assertEqualsWithDelta(2.0, $totals['capa']['Dev'], 0.001);
        $this->assertEqualsWithDelta(2.0, $totals['capa']['QA'], 0.001);
        $this->assertEqualsWithDelta(4.0, $totals['capaTotal'], 0.001);
    }

    #[Test]
    public function test_computeTotals_sortieTemporaireDatesInversees_sprintChevauchePartiellement_joursAbsenceExclus(): void
    {
        // Sortie temporaire puis retour (exitDate < entryDate) : les deux bornes (08 et 14) restent des jours
        // de présence, seuls les jours strictement entre les deux sont absents. Fenêtre lun 05/01 → ven 16/01
        // (10 jours ouvrés) : seuls 09, 12, 13 sont strictement entre 08 et 14 → 7 jours présence (05,06,07,08,14,15,16).
        $members = [['id' => 'f', 'role' => 'Dev', 'exitDate' => '2026-01-08', 'entryDate' => '2026-01-14']];

        $totals = (new CapacityCalculator())->computeTotals($members, [], '2026-01-05', '2026-01-16', []);

        $this->assertEqualsWithDelta(7.0, $totals['presenceTotal'], 0.001);
        $this->assertEqualsWithDelta(7.0, $totals['capa']['Dev'], 0.001);
    }

    #[Test]
    public function test_computeTotals_sortieTemporaireDatesInversees_sprintDansAbsence_membreJamaisMasqueMaisJoursAZero(): void
    {
        // Même membre que le test précédent. Sprint jeu 08/01 → ven 09/01 : le membre n'est JAMAIS masqué
        // (contrairement au cas d'une sortie simple) — il apparaît avec le 08 présent (borne de sortie
        // incluse) et le 09 absent (strictement entre les deux dates).
        $members = [['id' => 'f', 'role' => 'Dev', 'exitDate' => '2026-01-08', 'entryDate' => '2026-01-14']];

        $totals = (new CapacityCalculator())->computeTotals($members, [], '2026-01-08', '2026-01-09', []);

        $this->assertEqualsWithDelta(1.0, $totals['presenceTotal'], 0.001);
        $this->assertEqualsWithDelta(1.0, $totals['capa']['Dev'], 0.001);
    }
}
