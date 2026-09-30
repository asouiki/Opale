<?php

namespace Tests\Unit\Model;

use App\Model\FeatureBreakdown;
use App\Model\Issue;
use App\Model\JiraFields;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de FeatureBreakdown : total des Story Points tous types confondus, détection des bugs par le
 * mot-clef « bug » (nom de type ET étiquettes) et répartition par type de ticket.
 *
 * Les Issue sont hydratées via Issue::fromProjection() : aucun accès Jira, aucun rejeu de changelog.
 */
class FeatureBreakdownTest extends TestCase
{
    /**
     * Construit un ticket enfant minimal (type, Story Points, étiquettes).
     *
     * @param string[] $labels Étiquettes Jira du ticket
     */
    private function issue(string $type, ?float $storyPoints, array $labels = [], string $key = 'ABC-1'): Issue
    {
        return Issue::fromProjection([
            'data' => [
                'key'    => $key,
                'fields' => [
                    'project'                => ['key' => 'ABC'],
                    'issuetype'              => ['name' => $type],
                    'labels'                 => $labels,
                    JiraFields::STORY_POINTS => $storyPoints,
                ],
            ],
        ]);
    }

    #[Test]
    public function test_totalStoryPoints_tousTypesConfondus_ticketNonEstimeCompteZero(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Story', 5.0),
            $this->issue('Bug', 2.0),
            $this->issue('Spike', null),      // non estimé → 0
            $this->issue('Sous-tâche', 0.5),
        ]);

        $this->assertSame(4, $breakdown['issuesCount']);
        $this->assertSame(7.5, $breakdown['totalStoryPoints']);
    }

    #[Test]
    public function test_bugs_toutTypeContenantBug_estCompte(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Bug', 1.0),
            $this->issue('Bug Prod', 2.0),
            $this->issue('Bug Qualif', 3.0),
            $this->issue('Sous-tâche Bug', 1.0),
            $this->issue('Story', 8.0),       // non compté
        ]);

        $this->assertSame(4, $breakdown['bugCount']);
        $this->assertSame(7.0, $breakdown['bugStoryPoints']);
    }

    #[Test]
    public function test_ventilationEtiquettes_seulesCellesContenantBug_sontRetenues(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Bug', 1.0, ['BUG_Fonctionnel', 'objectif_1']),
            $this->issue('Bug', 1.0, ['BUG_Fonctionnel']),
            $this->issue('Bug', 1.0, ['BUG_Technique']),
            $this->issue('Bug', 1.0, ['Bug_Conception']),
        ]);

        $this->assertSame(
            ['BUG_Fonctionnel' => 2, 'BUG_Technique' => 1, 'Bug_Conception' => 1],
            $breakdown['bugsByLabel']
        );
        // L'étiquette « objectif_1 » ne porte pas le mot-clef : elle n'apparaît pas.
        $this->assertArrayNotHasKey('objectif_1', $breakdown['bugsByLabel']);
    }

    #[Test]
    public function test_bugSansEtiquetteBug_compteDansLeTotal_maisPasDansLaVentilation(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Bug', 1.0, ['BUG_Technique'], 'ABC-1'),
            $this->issue('Bug', 1.0, [], 'ABC-2'),                 // aucune étiquette
            $this->issue('Bug', 1.0, ['nouvelle_prio'], 'ABC-3'),  // étiquette sans mot-clef
        ]);

        // La ventilation n'est pas une partition : 3 bugs, mais une seule étiquette « bug » portée.
        $this->assertSame(3, $breakdown['bugCount']);
        $this->assertSame(['BUG_Technique' => 1], $breakdown['bugsByLabel']);
    }

    #[Test]
    public function test_bugPortantDeuxEtiquettesBug_compteDansChacune_maisUneSeuleFoisAuTotal(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Bug', 2.0, ['BUG_Fonctionnel', 'BUG_Technique']),
        ]);

        $this->assertSame(1, $breakdown['bugCount']);
        $this->assertSame(['BUG_Fonctionnel' => 1, 'BUG_Technique' => 1], $breakdown['bugsByLabel']);
    }

    #[Test]
    public function test_repartitionParType_cumuleNbEtSp_trieParNombreDecroissant(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([
            $this->issue('Bug', 1.0),
            $this->issue('Story', 5.0),
            $this->issue('Story', 3.0),
            $this->issue('Story', null),
        ]);

        $this->assertSame(
            [
                ['type' => 'Story', 'count' => 3, 'storyPoints' => 8.0],
                ['type' => 'Bug', 'count' => 1, 'storyPoints' => 1.0],
            ],
            $breakdown['byType']
        );
    }

    #[Test]
    public function test_collectionVide_structureNeutre(): void
    {
        $breakdown = FeatureBreakdown::fromIssues([]);

        $this->assertSame(0, $breakdown['issuesCount']);
        $this->assertSame(0.0, $breakdown['totalStoryPoints']);
        $this->assertSame(0, $breakdown['bugCount']);
        $this->assertSame(0.0, $breakdown['bugStoryPoints']);
        $this->assertSame([], $breakdown['bugsByLabel']);
        $this->assertSame([], $breakdown['byType']);
    }
}
