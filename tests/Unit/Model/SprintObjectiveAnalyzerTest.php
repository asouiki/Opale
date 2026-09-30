<?php

namespace Tests\Unit\Model;

use App\Model\SprintObjectiveAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeConfig;

/**
 * Tests du parsing des objectifs de sprint (SprintObjectiveAnalyzer, extrait de Sprint — lot A2 V2) :
 * - parseObjectivesFromGoal() : extraction des lignes de liste du goal (1-base)
 * - buildObjectiveTitle()     : correspondance étiquette « objectif_N » → titre lisible
 *
 * Le regroupement complet (buildGroups) reste caractérisé via Sprint::computeObjectiveGroups
 * dans SprintAggregatesTest (preuve de délégation à comportement constant).
 */
class SprintObjectiveAnalyzerTest extends TestCase
{
    private SprintObjectiveAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SprintObjectiveAnalyzer(new FakeConfig());
    }

    #[Test]
    public function test_parseObjectives_reconnaitLesFormatsDeListeEtIgnoreLIntro(): void
    {
        $goal = "Objectifs du sprint :\n"   // ligne d'intro sans marqueur → ignorée
            . "* Mettre en prod la feature ABC\n"
            . "- Réduire la dette technique\n"
            . '1. Stabiliser la CI';

        $this->assertSame([
            1 => 'Mettre en prod la feature ABC',
            2 => 'Réduire la dette technique',
            3 => 'Stabiliser la CI',
        ], $this->analyzer->parseObjectivesFromGoal($goal));
    }

    #[Test]
    public function test_parseObjectives_goalAbsent_retourneTableauVide(): void
    {
        $this->assertSame([], $this->analyzer->parseObjectivesFromGoal(null));
    }

    #[Test]
    public function test_buildObjectiveTitle_mappeLIndexVersLObjectif(): void
    {
        $objectives = [1 => 'Premier', 2 => 'Deuxième'];

        // objectif_2 → 2e objectif du goal
        $this->assertSame('Deuxième', $this->analyzer->buildObjectiveTitle('objectif_2', $objectives));
        // Famille « goal » : goal_N et préfixe libre sprint_goal_N mappent l'index au même titre qu'objectif_N.
        $this->assertSame('Premier', $this->analyzer->buildObjectiveTitle('goal_1', $objectives));
        $this->assertSame('Deuxième', $this->analyzer->buildObjectiveTitle('sprint_goal_2', $objectives));
        // Séparateur avant le numéro optionnel et libre (aucun / tiret / espace) pour les 3 familles.
        $this->assertSame('Premier', $this->analyzer->buildObjectiveTitle('objectif1', $objectives));
        $this->assertSame('Premier', $this->analyzer->buildObjectiveTitle('objectif-1', $objectives));
        $this->assertSame('Deuxième', $this->analyzer->buildObjectiveTitle('objectif 2', $objectives));
        $this->assertSame('Premier', $this->analyzer->buildObjectiveTitle('goal-1', $objectives));
        $this->assertSame('Deuxième', $this->analyzer->buildObjectiveTitle('sprint_goal 2', $objectives));
        // index hors plage → repli « Objectif N »
        $this->assertSame('Objectif 5', $this->analyzer->buildObjectiveTitle('objectif_5', $objectives));
        // étiquette sans index → repli ucfirst
        $this->assertSame('Autre_label', $this->analyzer->buildObjectiveTitle('autre_label', $objectives));
    }
}
