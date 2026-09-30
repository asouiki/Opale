<?php

namespace Tests\Unit\Model;

use App\Model\Sprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de Sprint::getSprintGoalsCompletion() : complétion par objectif « objectif_N » dérivée des
 * groupes d'objectifs (done/total/percent). Seuls les objectifs numérotés sont retenus ; « terminé »
 * suit le flag isDone figé à la date de référence du sprint.
 */
class SprintGoalsCompletionTest extends TestCase
{
    /**
     * @param list<array{label: string, title: string, issues: list<array<string, mixed>>}> $objectiveGroups
     */
    private function sprintWithObjectives(array $objectiveGroups): Sprint
    {
        return Sprint::fromSnapshot(['sprintId' => '1', 'objectiveGroups' => $objectiveGroups]);
    }

    #[Test]
    public function test_getSprintGoalsCompletion_objectifsNumerotes_calculeDoneTotalPercent(): void
    {
        $sprint = $this->sprintWithObjectives([
            ['label' => 'objectif_1', 'title' => 'A', 'issues' => [
                ['isDone' => true], ['isDone' => true], ['isDone' => false],
            ]],
            ['label' => 'objectif_2', 'title' => 'B', 'issues' => [
                ['isDone' => true],
            ]],
        ]);

        $this->assertSame([
            'objectif_1' => ['done' => 2, 'total' => 3, 'percent' => 66.7],
            'objectif_2' => ['done' => 1, 'total' => 1, 'percent' => 100.0],
        ], $sprint->getSprintGoalsCompletion());
    }

    #[Test]
    public function test_getSprintGoalsCompletion_etiquetteGoalNumerotee_estComptee(): void
    {
        // Famille « goal » (goal_N et préfixe libre sprint_goal_N) comptée au même titre qu'objectif_N.
        $sprint = $this->sprintWithObjectives([
            ['label' => 'goal_1', 'title' => 'A', 'issues' => [
                ['isDone' => true], ['isDone' => false],
            ]],
            ['label' => 'sprint_goal_2', 'title' => 'B', 'issues' => [
                ['isDone' => true],
            ]],
        ]);

        $this->assertSame([
            'goal_1'        => ['done' => 1, 'total' => 2, 'percent' => 50.0],
            'sprint_goal_2' => ['done' => 1, 'total' => 1, 'percent' => 100.0],
        ], $sprint->getSprintGoalsCompletion());
    }

    #[Test]
    public function test_getSprintGoalsCompletion_separateurLibreAvantLeNumero_estCompte(): void
    {
        // Séparateur avant le numéro optionnel et libre (aucun / tiret / espace) accepté pour objectif et goal.
        $sprint = $this->sprintWithObjectives([
            ['label' => 'objectif1', 'title' => 'A', 'issues' => [['isDone' => true]]],
            ['label' => 'goal-2', 'title' => 'B', 'issues' => [['isDone' => false]]],
            ['label' => 'objectif 3', 'title' => 'C', 'issues' => [['isDone' => true]]],
        ]);

        $completion = $sprint->getSprintGoalsCompletion();

        $this->assertArrayHasKey('objectif1', $completion);
        $this->assertArrayHasKey('goal-2', $completion);
        $this->assertArrayHasKey('objectif 3', $completion);
    }

    #[Test]
    public function test_getSprintGoalsCompletion_ignoreLesObjectifsNonNumerotes(): void
    {
        // « objectif_transverse » et « goal » nu ne sont pas de la forme <préfixe>_<N> : exclus du décompte.
        $sprint = $this->sprintWithObjectives([
            ['label' => 'objectif_transverse', 'title' => 'T', 'issues' => [['isDone' => true]]],
            ['label' => 'goal', 'title' => 'G', 'issues' => [['isDone' => true]]],
            ['label' => 'objectif_1', 'title' => 'A', 'issues' => [['isDone' => false]]],
        ]);

        $completion = $sprint->getSprintGoalsCompletion();

        $this->assertArrayHasKey('objectif_1', $completion);
        $this->assertArrayNotHasKey('objectif_transverse', $completion);
        $this->assertArrayNotHasKey('goal', $completion);
    }

    #[Test]
    public function test_getSprintGoalsCompletion_sansGroupeObjectif_retourneVide(): void
    {
        $this->assertSame([], $this->sprintWithObjectives([])->getSprintGoalsCompletion());
    }

    #[Test]
    public function test_getSprintGoalsCompletion_objectifSansTicket_percentZeroSansDivisionParZero(): void
    {
        $sprint = $this->sprintWithObjectives([
            ['label' => 'objectif_1', 'title' => 'A', 'issues' => []],
        ]);

        $this->assertSame(
            ['objectif_1' => ['done' => 0, 'total' => 0, 'percent' => 0.0]],
            $sprint->getSprintGoalsCompletion()
        );
    }
}
