<?php

namespace Tests\Unit\View;

use App\Model\Feature;
use App\View\FeatureView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests de la règle de dépassement d'échéance / Jalon de la page Feature.
 *
 * Règle métier vérifiée (FeatureView::isTargetDateOverdue) :
 *  - Feature NON terminée : dépassée si la date objectif (duedate ou Jalon) est
 *    antérieure à aujourd'hui.
 *  - Feature terminée      : dépassée uniquement si la date de complétion réelle
 *    (doneDate, dérivée des done_statuses) est postérieure à la date objectif.
 *    Une complétion le jour même ou avant n'est PAS un dépassement.
 *
 * Le faux positif corrigé : une Feature terminée AVANT son Jalon était signalée
 * dépassée car getResolutionDateTime() retombait sur la date du jour faute de
 * doneDate. La logique s'appuie désormais sur doneDate (sans fallback).
 *
 * Isolation : FakeConfig injecté → aucun appel réseau, workflow déterministe.
 */
class FeatureViewOverdueTest extends TestCase
{
    /** Workflow déterministe commun (FakeConfig ignore le type d'issue). */
    private function workflow(): array
    {
        return [
            'refinement_statuses' => ['To Do'],
            'sprint_statuses'     => ['À faire', 'In Progress'],
            'done_statuses'       => ['Done', 'Terminé'],  // deux statuts done pour couvrir le cas done→done
            'waiting_statuses'    => [],
        ];
    }

    /**
     * Construit une FeatureView isolée (sans réseau) :
     *  - Feature instanciée sans constructeur (qui appellerait Jira) ;
     *  - featureData (statut, Jalon, duedate) injecté ;
     *  - selfAsIssue construit avec un changelog synthétique pour produire la doneDate ;
     *  - FakeConfig injecté dans la View pour un workflow déterministe.
     *
     * @param string      $status        Statut courant de la Feature.
     * @param string|null $jalon          Jalon (Y-m-d) ou null.
     * @param string|null $duedate        Échéance (Y-m-d) ou null.
     * @param string|null $doneTransition Date ISO de transition vers "Done", ou null (pas de doneDate).
     */
    /**
     * @param array<array{date: string, from: string, to: string}> $transitions
     */
    private function makeView(
        string $status,
        ?string $jalon = null,
        ?string $duedate = null,
        ?string $doneTransition = null,
        array $transitions = []
    ): FeatureView {
        $config = new FakeConfig([], $this->workflow());

        // Ticket Feature lui-même → calcule sa doneDate via le changelog.
        $builder = (new IssueBuilder($config))
            ->withStatus($status)
            ->withCreatedDate('2025-09-01T08:00:00+02:00');

        // Compatibilité avec les appels existants (paramètre scalaire).
        if ($doneTransition !== null) {
            $builder = $builder->withStatusTransition($doneTransition, 'In Progress', 'Done');
        }
        // Transitions multiples pour les cas complexes.
        foreach ($transitions as $t) {
            $builder = $builder->withStatusTransition($t['date'], $t['from'], $t['to']);
        }
        $selfAsIssue = $builder->build();

        // Feature sans constructeur (évite l'appel Jira).
        $feature = (new ReflectionClass(Feature::class))->newInstanceWithoutConstructor();
        $this->setPrivate($feature, 'featureData', [
            'fields' => [
                'status'            => ['name' => $status],
                'customfield_10075' => $jalon,
                'duedate'           => $duedate,
            ],
        ]);
        $this->setPrivate($feature, 'selfAsIssue', $selfAsIssue);

        $view = new FeatureView($feature);
        // Injecte le workflow déterministe dans la View (sinon new Config() réel).
        $this->setProtected($view, 'config', $config);

        return $view;
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        (new ReflectionProperty($obj::class, $prop))->setValue($obj, $value);
    }

    private function setProtected(object $obj, string $prop, mixed $value): void
    {
        // La propriété est déclarée sur la classe parente (AbstractCollectionView) : la réflexion sur
        // l'instance la résout aussi bien, sans dépendre de get_parent_class() (qui peut valoir false).
        (new ReflectionProperty($obj, $prop))->setValue($obj, $value);
    }

    // ===================================================================
    // Non-régression — Feature NON terminée
    // ===================================================================

    #[Test]
    public function test_non_terminee_jalon_passe_est_overdue(): void
    {
        $view = $this->makeView('In Progress', jalon: '2020-01-01');

        $this->assertTrue($view->isJalonOverdue());
        $this->assertTrue($view->isAnyTargetOverdue());
    }

    #[Test]
    public function test_non_terminee_duedate_passee_est_overdue(): void
    {
        $view = $this->makeView('In Progress', duedate: '2020-01-01');

        $this->assertTrue($view->isDueDateOverdue());
        $this->assertTrue($view->isAnyTargetOverdue());
    }

    #[Test]
    public function test_non_terminee_jalon_et_duedate_futurs_non_overdue(): void
    {
        $view = $this->makeView('In Progress', jalon: '2099-01-01', duedate: '2099-01-01');

        $this->assertFalse($view->isJalonOverdue());
        $this->assertFalse($view->isDueDateOverdue());
        $this->assertFalse($view->isAnyTargetOverdue());
    }

    #[Test]
    public function test_non_terminee_sans_dates_non_overdue(): void
    {
        $view = $this->makeView('In Progress');

        $this->assertFalse($view->isAnyTargetOverdue());
    }

    // ===================================================================
    // Feature terminée — correctif du faux positif
    // ===================================================================

    #[Test]
    public function test_terminee_avant_le_jalon_non_overdue(): void
    {
        // Cas réel signalé : Done le 23/09, Jalon le 30/09 → PAS de dépassement.
        $view = $this->makeView('Done', jalon: '2025-09-30', doneTransition: '2025-09-23T09:00:00+02:00');

        $this->assertFalse($view->isJalonOverdue());
        $this->assertFalse($view->isAnyTargetOverdue());
    }

    #[Test]
    public function test_terminee_apres_le_jalon_est_overdue(): void
    {
        $view = $this->makeView('Done', jalon: '2025-09-30', doneTransition: '2025-10-05T09:00:00+02:00');

        $this->assertTrue($view->isJalonOverdue());
        $this->assertTrue($view->isAnyTargetOverdue());
    }

    #[Test]
    public function test_terminee_le_jour_du_jalon_non_overdue(): void
    {
        $view = $this->makeView('Done', jalon: '2025-09-30', doneTransition: '2025-09-30T18:00:00+02:00');

        $this->assertFalse($view->isJalonOverdue());
    }

    #[Test]
    public function test_terminee_sans_donedate_non_overdue(): void
    {
        // Terminée mais doneDate inconnue → on ne conclut pas au dépassement
        // (et surtout pas via la date du jour).
        $view = $this->makeView('Done', jalon: '2025-09-30', doneTransition: null);

        $this->assertFalse($view->isJalonOverdue());
        $this->assertSame('—', $view->getSelfEndDate());
    }

    // ===================================================================
    // Cas réel : transition done→done (Done → Terminé)
    // ===================================================================

    #[Test]
    public function test_terminee_avec_transition_done_vers_done_non_overdue(): void
    {
        // Cas réel signalé : Done le 23/09/2025, Jalon le 30/09/2025.
        // Puis transition Done→Terminé le 29/05/2026 (rename de statut done→done).
        // La transition done→done ne doit pas écraser la doneDate du 23/09
        // → pas de dépassement.
        $view = $this->makeView('Terminé', jalon: '2025-09-30', transitions: [
            ['date' => '2025-09-10T09:00:00+02:00', 'from' => 'To Do',      'to' => 'In Progress'],
            ['date' => '2025-09-23T09:00:00+02:00', 'from' => 'In Progress', 'to' => 'Done'],
            ['date' => '2026-05-29T10:00:00+02:00', 'from' => 'Done',        'to' => 'Terminé'],
        ]);

        $this->assertFalse($view->isJalonOverdue());
        $this->assertFalse($view->isAnyTargetOverdue());
        // La date de fin affichée doit être le 23/09/2025, pas le 29/05/2026.
        $this->assertSame('23/09/2025', $view->getSelfEndDate());
    }
}
