<?php

namespace Tests\Unit\Model;

use App\Model\SprintTicketBuilder;
use App\Model\Timeline;
use DateTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests de SprintTicketBuilder::wasInSprintAt() : reconstruction du PÉRIMÈTRE du sprint à une date passée.
 *
 * Règle métier couverte : quand on observe un sprint à une date antérieure (photo « à date »), la composition
 * actuelle du sprint est fausse dans les deux sens — elle a perdu les tickets retirés depuis, et gagné ceux ajoutés
 * après la date. Le rejeu du changelog « Sprint » rétablit l'appartenance réelle à l'instant observé.
 *
 * Cas d'usage à l'origine : un reliquat déplacé vers le sprint suivant AVANT la clôture, qui vide le sprint de ses
 * tickets non terminés et fausse définitivement ses métriques de clôture.
 */
class SprintScopeAtDateTest extends TestCase
{
    private FakeConfig $config;
    private IssueBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config  = new FakeConfig();
        $this->builder = new IssueBuilder($this->config);
    }

    private function makeBuilder(string $sprintId = '100'): SprintTicketBuilder
    {
        return new SprintTicketBuilder(
            $this->config,
            new Timeline($this->config),
            $sprintId,
            'Sprint',
            new DateTime('2025-03-01T00:00:00+00:00')
        );
    }

    private function at(string $iso = '2025-03-15T00:00:00+00:00'): DateTime
    {
        return new DateTime($iso);
    }

    // =====================================================================
    // Absence de changelog « Sprint » : repli sur l'appartenance actuelle
    // =====================================================================

    #[Test]
    public function test_wasInSprintAt_sansChangelogSprint_replieSurAppartenanceActuelle(): void
    {
        // Aucune transition tracée : l'objet Issue ne porte pas le champ « sprint », seul l'appelant sait d'où vient
        // le ticket (API Agile du sprint = présent ; Sprint Report = retiré).
        $issue = $this->builder->build();

        $this->assertTrue($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
        $this->assertFalse($this->makeBuilder()->wasInSprintAt($issue, $this->at(), false));
    }

    // =====================================================================
    // Tickets encore rattachés au sprint aujourd'hui
    // =====================================================================

    #[Test]
    public function test_wasInSprintAt_entreAvantLaDate_retourneTrue(): void
    {
        $issue = $this->builder
            ->withSprintChange('2025-02-25T08:00:00+00:00', '', '100')
            ->build();

        $this->assertTrue($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
    }

    #[Test]
    public function test_wasInSprintAt_ajouteApresLaDate_retourneFalse(): void
    {
        // Le ticket est dans le sprint aujourd'hui, mais n'y était pas encore à la date observée :
        // il doit sortir du périmètre reconstruit.
        $issue = $this->builder
            ->withSprintChange('2025-03-20T08:00:00+00:00', '', '100')
            ->build();

        $this->assertFalse($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
    }

    // =====================================================================
    // Tickets retirés du sprint (retrouvés via le Sprint Report)
    // =====================================================================

    #[Test]
    public function test_wasInSprintAt_retireApresLaDate_retourneTrue(): void
    {
        // LE cas d'usage : reliquat déplacé vers le sprint suivant après la date observée.
        // Absent du sprint aujourd'hui, il doit être réintégré au périmètre reconstruit.
        $issue = $this->builder
            ->withSprintChange('2025-02-25T08:00:00+00:00', '', '100')
            ->withSprintChange('2025-03-20T08:00:00+00:00', '100', '101')
            ->build();

        $this->assertTrue($this->makeBuilder()->wasInSprintAt($issue, $this->at(), false));
    }

    #[Test]
    public function test_wasInSprintAt_retireAvantLaDate_retourneFalse(): void
    {
        $issue = $this->builder
            ->withSprintChange('2025-02-25T08:00:00+00:00', '', '100')
            ->withSprintChange('2025-03-05T08:00:00+00:00', '100', '101')
            ->build();

        $this->assertFalse($this->makeBuilder()->wasInSprintAt($issue, $this->at(), false));
    }

    #[Test]
    public function test_wasInSprintAt_retirePuisReintegreAvantLaDate_retourneTrue(): void
    {
        $issue = $this->builder
            ->withSprintChange('2025-02-25T08:00:00+00:00', '', '100')
            ->withSprintChange('2025-03-05T08:00:00+00:00', '100', '101')
            ->withSprintChange('2025-03-10T08:00:00+00:00', '101', '100')
            ->build();

        $this->assertTrue($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
    }

    // =====================================================================
    // Sprints multiples et bornes
    // =====================================================================

    #[Test]
    public function test_wasInSprintAt_affectationMultiple_detecteLeSprintCourant(): void
    {
        // Jira liste plusieurs IDs quand un ticket appartient à plusieurs sprints : la présence du sprint analysé
        // dans la liste suffit.
        $issue = $this->builder
            ->withSprintChange('2025-02-25T08:00:00+00:00', '', '99, 100')
            ->build();

        $this->assertTrue($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
        $this->assertFalse($this->makeBuilder('101')->wasInSprintAt($issue, $this->at(), true));
    }

    #[Test]
    public function test_wasInSprintAt_dateExactementSurLaTransition_prendEnCompteLaTransition(): void
    {
        // Borne incluse : une transition à l'instant exact de l'observation est réputée déjà appliquée.
        $issue = $this->builder
            ->withSprintChange('2025-03-15T00:00:00+00:00', '100', '')
            ->build();

        $this->assertFalse($this->makeBuilder()->wasInSprintAt($issue, $this->at(), true));
    }
}
