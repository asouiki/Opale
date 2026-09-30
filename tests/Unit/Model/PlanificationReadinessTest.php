<?php

namespace Tests\Unit\Model;

use App\Model\Planification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de Planification::computeReadiness() — % de préparation du backlog des sprints à venir
 * (page /planification).
 *
 * Règle métier : un sujet est « non prêt » tant que son statut appartient à la catégorie d'affinage
 * du workflow, SAUF le DERNIER statut de cette catégorie, qui marque le passage à « prêt à planifier ».
 * `statusRank` étant déjà indexé sur affinage + sprint + terminé (SprintTicketBuilder), la méthode est
 * pure et statique : aucun appel Jira, aucun mock nécessaire.
 */
class PlanificationReadinessTest extends TestCase
{
    /**
     * @param  list<int> $ranks Rangs de statut des tickets du bucket
     * @return list<array{statusRank:int}>
     */
    private function issuesWithRanks(array $ranks): array
    {
        return array_map(static fn (int $rank): array => ['statusRank' => $rank], $ranks);
    }

    #[Test]
    public function test_computeReadiness_tousAuDernierStatutDAffinage_retourneCentPourCent(): void
    {
        // refinementCount = 4 (Backlog, En rédaction, A affiner, A planifier) → dernier rang = 3.
        $issues = $this->issuesWithRanks([3, 3, 3]);

        $result = Planification::computeReadiness($issues, 4);

        $this->assertSame(['total' => 3, 'ready' => 3, 'percent' => 100.0], $result);
    }

    #[Test]
    public function test_computeReadiness_ticketsEnCoursDAffinage_sontComptesNonPrets(): void
    {
        // Rangs 0, 1, 2 : avant le dernier statut d'affinage (rang 3) → non prêts.
        $issues = $this->issuesWithRanks([0, 1, 2]);

        $result = Planification::computeReadiness($issues, 4);

        $this->assertSame(['total' => 3, 'ready' => 0, 'percent' => 0.0], $result);
    }

    #[Test]
    public function test_computeReadiness_ticketDemarre_estComptePret(): void
    {
        // Rang 5 : au-delà de l'affinage (déjà dans le sprint) → prêt (déjà démarré).
        $issues = $this->issuesWithRanks([5]);

        $result = Planification::computeReadiness($issues, 4);

        $this->assertSame(['total' => 1, 'ready' => 1, 'percent' => 100.0], $result);
    }

    #[Test]
    public function test_computeReadiness_statutHorsWorkflow_estComptePret(): void
    {
        // Statut absent du workflow (jamais rencontré par SprintTicketBuilder) → PHP_INT_MAX.
        // Il n'appartient précisément pas à la catégorie affinage : compté prêt.
        $issues = $this->issuesWithRanks([PHP_INT_MAX]);

        $result = Planification::computeReadiness($issues, 4);

        $this->assertSame(['total' => 1, 'ready' => 1, 'percent' => 100.0], $result);
    }

    #[Test]
    public function test_computeReadiness_listeVide_retournePourcentNull(): void
    {
        $result = Planification::computeReadiness([], 4);

        $this->assertSame(['total' => 0, 'ready' => 0, 'percent' => null], $result);
    }

    #[Test]
    public function test_computeReadiness_workflowSansStatutDAffinage_retourneCentPourCent(): void
    {
        // refinementCount = 0 → dernier rang = -1 : tout rang >= -1 (donc tout, y compris 0) est prêt.
        $issues = $this->issuesWithRanks([0, 1, 2]);

        $result = Planification::computeReadiness($issues, 0);

        $this->assertSame(['total' => 3, 'ready' => 3, 'percent' => 100.0], $result);
    }

    #[Test]
    public function test_computeReadiness_melange_calculeLePourcentageArrondi(): void
    {
        // 32 sujets, 5 en cours d'affinage (rangs 0-2, non prêts) → 27/32 = 84.375 → 84.4.
        $issues = array_merge(
            $this->issuesWithRanks([0, 1, 2, 0, 1]), // 5 non prêts
            $this->issuesWithRanks(array_fill(0, 27, 3)) // 27 prêts (dernier statut d'affinage)
        );

        $result = Planification::computeReadiness($issues, 4);

        $this->assertSame(32, $result['total']);
        $this->assertSame(27, $result['ready']);
        $this->assertSame(84.4, $result['percent']);
    }
}
