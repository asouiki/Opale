<?php

namespace Tests\Unit\Service;

use App\Service\PlanningCapacitySuggester;
use App\Service\TeamConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la capacité suggérée pour le prochain sprint (champ « Capacité totale brute » de
 * /planification).
 *
 * On isole la config d'équipe dans un répertoire temporaire injecté au repository pour ne jamais
 * toucher la vraie config. Le suggester ne faisant aucun appel Jira (la liste des sprints lui est
 * fournie), tout est testable sans mock.
 */
class PlanningCapacitySuggesterTest extends TestCase
{
    private const PROJECT = 'ABC';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/planning_suggester_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function repo(): TeamConfigRepository
    {
        return new TeamConfigRepository($this->tmpDir);
    }

    private function suggester(): PlanningCapacitySuggester
    {
        return new PlanningCapacitySuggester($this->repo());
    }

    /**
     * Écrit une grille de capacité minimale mais « exploitable » pour un sprint : sans présence, le
     * repository considère la grille comme vide et retire l'entrée.
     */
    private function givenCapacity(string $sprintId, float $capaTotal): void
    {
        $this->repo()->setSprintCapacity(self::PROJECT, $sprintId, [
            'members' => ['m1' => ['presence' => []]],
            'totals'  => ['presenceTotal' => 90, 'capa' => ['Dev' => $capaTotal], 'capaTotal' => $capaTotal],
        ]);
    }

    /**
     * @return array{id:string, name:string, state:string, startDate:?string, ignored:bool}
     */
    private function sprint(string $id, string $state, ?string $startDate, bool $ignored = false): array
    {
        return [
            'id'        => $id,
            'name'      => 'Sprint ' . $id,
            'state'     => $state,
            'startDate' => $startDate,
            'ignored'   => $ignored,
        ];
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable($days . ' days'))->format('Y-m-d');
    }

    #[Test]
    public function test_suggest_capaEtVelocitePresentes_retourneCapaciteArrondieAuDemi(): void
    {
        $this->givenCapacity('27440', 50.5);
        $this->repo()->setAvgVelocity(self::PROJECT, 0.26);

        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('27440', 'future', $this->inDays(11)),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('27440', $result['sprintId']);
        $this->assertSame('Sprint 27440', $result['sprintName']);
        $this->assertSame(50.5, $result['capaTotal']);
        $this->assertSame(0.26, $result['avgVelocity']);
        // 50.5 × 0.26 = 13.13 → 13
        $this->assertSame(13.0, $result['suggestedCapacity']);
    }

    #[Test]
    public function test_suggest_arrondi_trancheUnDemiExactVersLeBas(): void
    {
        // Vélocité à 1 : la capacité brute traverse le calcul telle quelle, seul l'arrondi est éprouvé.
        $this->repo()->setAvgVelocity(self::PROJECT, 1.0);

        $expected = [
            '9.1'  => [9.1, 9.0],
            '9.24' => [9.24, 9.0],
            '9.25' => [9.25, 9.0],  // demi exact → borne prudente
            '9.26' => [9.26, 9.5],
            '9.45' => [9.45, 9.5],
            '9.5'  => [9.5, 9.5],   // déjà sur un demi → inchangé
        ];

        foreach ($expected as $label => [$capaTotal, $suggested]) {
            $this->givenCapacity('100', $capaTotal);

            $result = $this->suggester()->suggest(self::PROJECT, [
                $this->sprint('100', 'future', $this->inDays(3)),
            ]);

            $this->assertNotNull($result);
            $this->assertSame($suggested, $result['suggestedCapacity'], 'Arrondi de ' . $label);
        }
    }

    #[Test]
    public function test_suggest_prochainSprintIgnore_estQuandMemeRetenu(): void
    {
        $this->givenCapacity('27440', 50.5);
        $this->repo()->setAvgVelocity(self::PROJECT, 0.26);

        // Un sprint exclu des métriques d'équipe reste la cible réelle de la planification.
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('27440', 'future', $this->inDays(11), true),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('27440', $result['sprintId']);
        $this->assertSame(13.0, $result['suggestedCapacity']);
    }

    #[Test]
    public function test_suggest_plusieursSprintsFuturs_retientLeStartDateLePlusProche(): void
    {
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('30', 'future', $this->inDays(30)),
            $this->sprint('5', 'future', $this->inDays(5)),
            $this->sprint('12', 'future', $this->inDays(12)),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('5', $result['sprintId']);
    }

    #[Test]
    public function test_suggest_sprintFuturCommencantAujourdhui_estRetenu(): void
    {
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('42', 'future', $this->inDays(0)),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('42', $result['sprintId']);
    }

    #[Test]
    public function test_suggest_sprintFuturCommencantHier_estIgnore(): void
    {
        // Sprint « fictif » (regroupement de PI, backlog « prio ») : à venir mais déjà daté dans le passé.
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('42', 'future', $this->inDays(-1)),
        ]);

        $this->assertNull($result);
    }

    #[Test]
    public function test_suggest_sprintFuturSansStartDate_estIgnore(): void
    {
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('42', 'future', null),
        ]);

        $this->assertNull($result);
    }

    #[Test]
    public function test_suggest_sprintActifOuClos_nEstJamaisRetenu(): void
    {
        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('1', 'active', $this->inDays(1)),
            $this->sprint('2', 'closed', $this->inDays(2)),
        ]);

        $this->assertNull($result);
    }

    #[Test]
    public function test_suggest_listeVide_retourneNull(): void
    {
        $this->assertNull($this->suggester()->suggest(self::PROJECT, []));
    }

    #[Test]
    public function test_suggest_sansGrilleDeCapacite_retourneLeSprintAvecSuggestionNulle(): void
    {
        $this->repo()->setAvgVelocity(self::PROJECT, 0.26);

        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('27440', 'future', $this->inDays(11)),
        ]);

        // Le sprint reste exposé : l'IHM doit pouvoir dire CE QUI manque, et le CTA d'affectation
        // au prochain sprint doit rester opérant.
        $this->assertNotNull($result);
        $this->assertSame('27440', $result['sprintId']);
        $this->assertNull($result['capaTotal']);
        $this->assertSame(0.26, $result['avgVelocity']);
        $this->assertNull($result['suggestedCapacity']);
    }

    #[Test]
    public function test_suggest_sansVelocite_retourneLeSprintAvecSuggestionNulle(): void
    {
        $this->givenCapacity('27440', 50.5);

        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('27440', 'future', $this->inDays(11)),
        ]);

        $this->assertNotNull($result);
        $this->assertSame(50.5, $result['capaTotal']);
        $this->assertNull($result['avgVelocity']);
        $this->assertNull($result['suggestedCapacity']);
    }

    #[Test]
    public function test_suggest_capaTotalZero_estTraiteCommeAbsent(): void
    {
        $this->givenCapacity('27440', 0.0);
        $this->repo()->setAvgVelocity(self::PROJECT, 0.26);

        $result = $this->suggester()->suggest(self::PROJECT, [
            $this->sprint('27440', 'future', $this->inDays(11)),
        ]);

        $this->assertNotNull($result);
        $this->assertNull($result['capaTotal']);
        $this->assertNull($result['suggestedCapacity']);
    }
}
