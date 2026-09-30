<?php

namespace Tests\Unit\Model;

use App\Model\Feature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests de la (dé)sérialisation d'une Feature pour le cache partagé (FeatureMetricsCacheService) :
 * Feature::toProjection() / fromProjection() doivent restituer métadonnées, ticket Feature lui-même,
 * tickets enfants et agrégats — sans rejouer le changelog ni rappeler Jira.
 */
class FeatureProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // fromProjection() appelle bootServices() → new JiraService() lit ces variables (sans appel
        // réseau). Valeurs factices posées uniquement si absentes (ne masque pas un .env chargé).
        $_ENV['JIRA_BASE_URL']  ??= 'http://feature-projection-test.local';
        $_ENV['JIRA_EMAIL']     ??= 'test@example.com';
        $_ENV['JIRA_API_TOKEN'] ??= 'test-token';
    }

    /**
     * Projection minimale d'un ticket enfant (type « Story » pour survivre au filtrage métier).
     *
     * @return array<string, mixed>
     */
    private function childProjection(string $key, float $waitingTotal): array
    {
        return [
            'data' => [
                'key'    => $key,
                'fields' => [
                    'summary'   => 'Ticket enfant ' . $key,
                    'issuetype' => ['name' => 'Story', 'iconUrl' => ''],
                    'status'    => ['name' => 'Done', 'statusCategory' => ['colorName' => 'green']],
                ],
            ],
            'leadTime'         => 0.0,
            'cycleTime'        => 0.0,
            'waitingTimes'     => ['En Attente' => $waitingTotal],
            'waitingTimeTotal' => $waitingTotal,
        ];
    }

    #[Test]
    public function test_fromProjection_restitue_feature_self_et_agregats(): void
    {
        $projection = [
            'featureData' => [
                'id'     => '10001',
                'key'    => 'FEAT-1',
                'fields' => [
                    'summary' => 'Ma feature',
                    'status'  => ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']],
                ],
            ],
            'selfAsIssue' => [
                'data' => [
                    'key'    => 'FEAT-1',
                    'fields' => [
                        'summary'   => 'Ma feature',
                        'issuetype' => ['name' => 'Feature', 'iconUrl' => ''],
                        'status'    => ['name' => 'Terminé', 'statusCategory' => ['colorName' => 'green']],
                    ],
                ],
                'leadTime'  => 12.0,
                'cycleTime' => 7.0,
                // Dates d'ancrage d'un ticket Done : indispensables pour que getLeadTime/getCycleTime
                // restituent les durées FIGÉES (une valeur « en cours » ne serait rendue que sans doneDate).
                'createdDate'         => '2026-01-01T09:00:00+0000',
                'firstInProgressDate' => '2026-01-05T09:00:00+0000',
                'doneDate'            => '2026-01-15T09:00:00+0000',
            ],
            // Deux enfants : temps d'attente (union) 2j et 3j → total collection = 5j.
            'issues' => [
                $this->childProjection('C-1', 2.0),
                $this->childProjection('C-2', 3.0),
            ],
        ];

        $feature = Feature::fromProjection($projection);

        // Métadonnées Feature.
        $this->assertSame('FEAT-1', $feature->getKey());
        $this->assertSame('Ma feature', $feature->getName());
        $this->assertTrue($feature->isDone());

        // Ticket Feature lui-même (selfAsIssue) restitué avec ses métriques propres.
        $this->assertNotNull($feature->getSelfAsIssue());
        $this->assertSame(12.0, $feature->getSelfAsIssue()->getLeadTime());
        $this->assertSame(7.0, $feature->getSelfAsIssue()->getCycleTime());

        // Enfants hydratés + agrégats recalculés (somme des unions par ticket).
        $this->assertSame(2, $feature->getIssuesCount());
        $this->assertSame(5.0, $feature->getTotalTimeSpentInWaiting());
        $this->assertSame(2.5, $feature->getAverageTimeSpentInWaiting());
    }

    #[Test]
    public function test_toProjection_retire_changelog_et_embarque_self_et_enfants(): void
    {
        $builder = new IssueBuilder(new FakeConfig());
        $self    = $builder->withStatus('Terminé')->build();
        $child1  = $builder->withStatus('Done')->build();
        $child2  = $builder->withStatus('Done')->build();

        // Feature sans constructeur (évite l'appel Jira) ; on injecte l'état à sérialiser.
        $feature = (new ReflectionClass(Feature::class))->newInstanceWithoutConstructor();
        $this->setProp($feature, 'featureData', [
            'key'       => 'FEAT-9',
            'changelog' => ['histories' => [['id' => '1']]],
            'fields'    => ['summary' => 'Feature à sérialiser'],
        ]);
        $this->setProp($feature, 'selfAsIssue', $self);
        // allIssues = ensemble complet sérialisé (cf. toProjection).
        $this->setProp($feature, 'allIssues', [$child1, $child2]);

        $projection = $feature->toProjection();

        // Le changelog (volumineux) est retiré des métadonnées.
        $this->assertArrayNotHasKey('changelog', $projection['featureData']);
        $this->assertSame('FEAT-9', $projection['featureData']['key']);

        // Le ticket Feature lui-même et les enfants sont embarqués sous forme de projections.
        $this->assertIsArray($projection['selfAsIssue']);
        $this->assertArrayNotHasKey('changelog', $projection['selfAsIssue']['data']);
        $this->assertCount(2, $projection['issues']);
    }

    /**
     * Écrit une propriété (privée ou protégée héritée) via réflexion.
     */
    private function setProp(object $obj, string $prop, mixed $value): void
    {
        (new ReflectionProperty($obj, $prop))->setValue($obj, $value);
    }
}
