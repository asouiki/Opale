<?php

namespace Tests\Unit\Model;

use App\Model\Issue;
use App\Model\Timeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Utils\FakeConfig;

/**
 * Tests du calcul « en cours » du Lead Time et du Cycle Time (Issue::getLeadTime / getCycleTime).
 *
 * Règle métier : tant qu'un ticket n'a pas atteint un statut de catégorie Done, on n'affiche pas 0
 * mais l'âge courant — création → aujourd'hui pour le Lead, 1er passage « en cours » → aujourd'hui
 * pour le Cycle. Un ticket Done restitue ses durées FIGÉES ; un ticket jamais démarré (Affinage /
 * To Do) n'a pas de cycle (0). L'« aujourd'hui » est figé via le seam Issue::now() (FixedClockIssue).
 */
class IssueRunningTimeTest extends TestCase
{
    private Timeline $timeline;

    protected function setUp(): void
    {
        parent::setUp();
        // Aucun jour férié → jours ouvrés = tous les jours sauf week-ends (déterministe, sans réseau).
        $this->timeline = new Timeline(new FakeConfig());
    }

    #[Test]
    public function test_getLeadTime_ticketOuvert_retourneAgeCreationVersAujourdhui(): void
    {
        $now   = '2026-01-09 17:00:00';
        $issue = $this->makeIssue('2026-01-05 09:00:00', null, null, $now);

        $expected = $this->timeline->calculateBusinessDays(
            new \DateTime('2026-01-05 09:00:00'),
            new \DateTime($now)
        );

        $this->assertGreaterThan(0.0, $issue->getLeadTime());
        $this->assertSame($expected, $issue->getLeadTime());
    }

    #[Test]
    public function test_getLeadTime_ticketOuvert_croitAvecLeTemps(): void
    {
        $tot  = $this->makeIssue('2026-01-05 09:00:00', null, null, '2026-01-09 17:00:00');
        $tard = $this->makeIssue('2026-01-05 09:00:00', null, null, '2026-02-09 17:00:00');

        // Le même ticket « vieillit » : plus « aujourd'hui » est tardif, plus le Lead en cours est grand.
        $this->assertGreaterThan($tot->getLeadTime(), $tard->getLeadTime());
    }

    #[Test]
    public function test_getLeadTime_ticketDone_retourneDureeFigee_ignoreAujourdhui(): void
    {
        // « aujourd'hui » très lointain : doit être ignoré car le ticket est Done (durée figée).
        $issue = $this->makeIssue('2026-01-05 09:00:00', '2026-01-06 09:00:00', '2026-01-15 09:00:00', '2030-01-01 09:00:00');
        $this->setProp($issue, 'leadTime', 42.0);

        $this->assertSame(42.0, $issue->getLeadTime());
    }

    #[Test]
    public function test_getLeadTime_sansDateCreation_retourneZero(): void
    {
        $issue = $this->makeIssue(null, null, null, '2026-01-09 17:00:00');

        $this->assertSame(0.0, $issue->getLeadTime());
    }

    #[Test]
    public function test_getCycleTime_ticketDemarreNonTermine_retourneAgeDepuisEnCours(): void
    {
        $now   = '2026-01-09 17:00:00';
        $issue = $this->makeIssue('2026-01-05 09:00:00', '2026-01-06 09:00:00', null, $now);

        $expected = $this->timeline->calculateBusinessDays(
            new \DateTime('2026-01-06 09:00:00'),
            new \DateTime($now)
        );

        $this->assertGreaterThan(0.0, $issue->getCycleTime());
        $this->assertSame($expected, $issue->getCycleTime());
    }

    #[Test]
    public function test_getCycleTime_jamaisDemarre_retourneZero(): void
    {
        // Ticket en Affinage : créé, jamais passé « en cours », pas Done → pas de cycle time.
        $issue = $this->makeIssue('2026-01-05 09:00:00', null, null, '2026-01-09 17:00:00');

        $this->assertSame(0.0, $issue->getCycleTime());
    }

    #[Test]
    public function test_getCycleTime_ticketDone_retourneDureeFigee_ignoreAujourdhui(): void
    {
        $issue = $this->makeIssue('2026-01-05 09:00:00', '2026-01-06 09:00:00', '2026-01-15 09:00:00', '2030-01-01 09:00:00');
        $this->setProp($issue, 'cycleTime', 8.0);

        $this->assertSame(8.0, $issue->getCycleTime());
    }

    /**
     * Construit une Issue sans constructeur (aucun appel Jira) avec des dates d'ancrage contrôlées et
     * un « maintenant » figé via FixedClockIssue::now().
     */
    private function makeIssue(?string $created, ?string $firstInProgress, ?string $done, string $now): FixedClockIssue
    {
        $issue = (new ReflectionClass(FixedClockIssue::class))->newInstanceWithoutConstructor();

        $this->setProp($issue, 'timeline', $this->timeline);
        $this->setProp($issue, 'createdDate', $created !== null ? new \DateTime($created) : null);
        $this->setProp($issue, 'firstInProgressDate', $firstInProgress !== null ? new \DateTime($firstInProgress) : null);
        $this->setProp($issue, 'doneDate', $done !== null ? new \DateTime($done) : null);
        $issue->fixedNow = new \DateTime($now);

        return $issue;
    }

    private function setProp(object $obj, string $prop, mixed $value): void
    {
        (new ReflectionProperty($obj, $prop))->setValue($obj, $value);
    }
}

/**
 * Issue à horloge figée : neutralise Issue::now() pour rendre les durées « en cours » déterministes.
 */
class FixedClockIssue extends Issue
{
    public \DateTime $fixedNow;

    protected function now(): \DateTime
    {
        return $this->fixedNow;
    }
}
