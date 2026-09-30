<?php

namespace Tests\Unit\Model;

use App\Model\Issue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la (dé)sérialisation d'une Issue pour le cache partagé : Issue::toProjection() /
 * fromProjection() doivent restituer les valeurs calculées sans rejouer le changelog.
 */
class IssueProjectionTest extends TestCase
{
    #[Test]
    public function test_fromProjection_restitueLesValeursCalculees(): void
    {
        $projection = [
            'data' => [
                'key'    => 'ABC-1',
                'fields' => [
                    'summary'        => 'Mon ticket',
                    'issuetype'      => ['name' => 'Story', 'iconUrl' => 'i.png'],
                    'priority'       => ['name' => 'High', 'iconUrl' => 'p.png'],
                    'status'         => ['name' => 'Done', 'statusCategory' => ['colorName' => 'green']],
                    'created'        => '2026-01-01T08:00:00.000+0000',
                    'resolutiondate' => '2026-01-12T08:00:00.000+0000',
                ],
            ],
            'leadTime'              => 8.0,
            'cycleTime'             => 5.0,
            'timeByStatus'          => ['In Progress' => 5.0],
            'workflowTimeBreakdown' => ['refinement' => 1.0, 'sprint' => 4.0, 'other' => 0.0, 'waiting' => 2.0],
            'waitingTimes'          => ['attente-externe' => 2.0],
            'revertCount'           => ['To Do' => 1],
            'createdDate'           => '2026-01-01T08:00:00+00:00',
            'firstInProgressDate'   => '2026-01-05T08:00:00+00:00',
            'doneDate'              => '2026-01-12T08:00:00+00:00',
            'firstAddToSprintDate'  => '2026-01-03T08:00:00+00:00',
            'firstSprint'           => 'Sprint 7',
        ];

        $issue = Issue::fromProjection($projection);

        $this->assertSame('ABC-1', $issue->getKey());
        $this->assertSame('Mon ticket', $issue->getSummary());
        $this->assertSame('Story', $issue->getIssueTypeName());
        $this->assertSame('High', $issue->getPriorityName());
        $this->assertSame('Done', $issue->getStatusName());
        $this->assertSame('green', $issue->getStatusCategoryColor());
        $this->assertSame(8.0, $issue->getLeadTime());
        $this->assertSame(5.0, $issue->getCycleTime());
        $this->assertSame(['attente-externe' => 2.0], $issue->getWaitingTimes());
        $this->assertSame(['To Do' => 1], $issue->getRevertCount());
        $this->assertSame(4.0, $issue->getTimeSpentInSprint());
        $this->assertSame('Sprint 7', $issue->getFirstSprint());
        $this->assertSame('01/01/2026', $issue->getCreatedDate());
        $this->assertSame('05/01/2026', $issue->getFirstInProgressDate());
        $this->assertSame('12/01/2026', $issue->getDoneDate());
        $this->assertSame('03/01/2026', $issue->getFirstAddToSprintDate());
    }

    #[Test]
    public function test_toProjection_retireLeChangelog(): void
    {
        $issue = Issue::fromProjection([
            'data'     => ['key' => 'ABC-2', 'changelog' => ['histories' => [['id' => '1']]], 'fields' => []],
            'leadTime' => 0.0,
            'cycleTime' => 0.0,
        ]);

        $projection = $issue->toProjection();

        $this->assertArrayNotHasKey('changelog', $projection['data']);
        $this->assertSame('ABC-2', $projection['data']['key']);
    }
}
