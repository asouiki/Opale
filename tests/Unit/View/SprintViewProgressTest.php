<?php

namespace Tests\Unit\View;

use App\Model\StatusCategory;
use App\View\SprintView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Utils\FakeConfig;

/**
 * Tests des projections d'avancement de la page Sprint.
 *
 * Règles métier vérifiées :
 *  - SprintView::getProgressBadge() ramène les 5 catégories du workflow à 3 états lisibles
 *    (À faire / En cours / Terminé). Un ticket en attente est « En cours » : il a démarré, il est
 *    seulement suspendu. Le statut d'ENTRÉE du sprint est en revanche « À faire » bien qu'il appartienne
 *    à la catégorie `sprint` : il est reconnu soit par son libellé standard (« À faire », « To Do » après
 *    normalisation), soit par sa position (1er de `sprint_statuses`, convention de Timeline).
 *  - SprintView::getCategoryBreakdown() répartit une liste de tickets par catégorie pour alimenter la
 *    jauge de progression : catégories vides écartées, ordre du plus avancé au moins avancé, total des
 *    parts égal à 100 %, et catégorie inconnue rattachée à « Autre » (jamais perdue).
 *
 * Isolation : les deux méthodes sont pures (aucun accès au Sprint ni au réseau), la vue est donc
 * instanciée sans passer par son constructeur.
 */
class SprintViewProgressTest extends TestCase
{
    /**
     * Vue instanciée sans constructeur (aucun Sprint requis par les méthodes testées), avec un workflow
     * déterministe injecté : getProgressBadge() lit `sprint_statuses` pour situer le statut d'entrée.
     *
     * @param list<string> $sprintStatuses Statuts « sprint » du workflow, le 1er étant le non démarré
     */
    private function view(array $sprintStatuses = ['À faire', 'En cours', 'Revue Dév']): SprintView
    {
        $view = (new ReflectionClass(SprintView::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(SprintView::class, 'config'))->setValue($view, new FakeConfig([], [
            'refinement_statuses' => ['Backlog'],
            'sprint_statuses'     => $sprintStatuses,
            'done_statuses'       => ['Terminé'],
            'waiting_statuses'    => ['Bloqué'],
        ]));

        return $view;
    }

    /**
     * Fabrique un ticket réduit aux seules clés lues par getCategoryBreakdown().
     *
     * @return array<string, mixed>
     */
    private function ticket(string $category): array
    {
        return ['statusCategory' => $category];
    }

    // ==================== getProgressBadge ====================

    private const BADGE_TODO       = ['label' => 'À faire', 'class' => 'grey'];
    private const BADGE_IN_PROGRESS = ['label' => 'En cours', 'class' => 'orange'];
    private const BADGE_DONE       = ['label' => 'Terminé', 'class' => 'green'];

    #[Test]
    public function test_progress_badge_done_category_returns_green_done_label(): void
    {
        $this->assertSame(
            self::BADGE_DONE,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::DONE, 'status' => 'Terminé'])
        );
    }

    #[Test]
    public function test_progress_badge_started_sprint_status_returns_orange_in_progress_label(): void
    {
        $this->assertSame(
            self::BADGE_IN_PROGRESS,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'En cours'])
        );
    }

    #[Test]
    public function test_progress_badge_sprint_entry_status_is_not_started_yet(): void
    {
        // « À faire » appartient à sprint_statuses mais rien n'a démarré : badge gris, pas orange.
        $this->assertSame(
            self::BADGE_TODO,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'À faire'])
        );
    }

    #[Test]
    public function test_progress_badge_native_to_do_status_is_normalized_to_not_started(): void
    {
        // Le workflow ne déclare que « À faire » ; « To Do » est son libellé natif Jira équivalent.
        $this->assertSame(
            self::BADGE_TODO,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'To Do'])
        );
    }

    #[Test]
    public function test_progress_badge_atypical_workflow_entry_status_is_not_started(): void
    {
        // Workflow dont le statut d'entrée n'est pas un libellé standard : c'est sa position (1er de
        // sprint_statuses) qui le désigne comme non démarré.
        $view = $this->view(['A RELIRE EQUIPE', 'En cours']);

        $this->assertSame(
            self::BADGE_TODO,
            $view->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'A RELIRE EQUIPE'])
        );
        $this->assertSame(
            self::BADGE_IN_PROGRESS,
            $view->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'En cours'])
        );
    }

    #[Test]
    public function test_progress_badge_standard_entry_status_stays_not_started_on_atypical_workflow(): void
    {
        // « À faire » reste « À faire » même si le workflow place un autre statut en tête : l'exception
        // sur les libellés standards s'ajoute à la règle de position.
        $this->assertSame(
            self::BADGE_TODO,
            $this->view(['A RELIRE EQUIPE', 'À faire', 'En cours'])
                ->getProgressBadge(['statusCategory' => StatusCategory::SPRINT, 'status' => 'À faire'])
        );
    }

    #[Test]
    public function test_progress_badge_waiting_category_is_treated_as_in_progress(): void
    {
        // Un ticket bloqué a déjà démarré : il n'est pas « À faire ».
        $this->assertSame(
            self::BADGE_IN_PROGRESS,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::WAITING, 'status' => 'Bloqué'])
        );
    }

    #[Test]
    public function test_progress_badge_refinement_category_returns_grey_todo_label(): void
    {
        $this->assertSame(
            self::BADGE_TODO,
            $this->view()->getProgressBadge(['statusCategory' => StatusCategory::REFINEMENT, 'status' => 'Backlog'])
        );
    }

    #[Test]
    public function test_progress_badge_unknown_category_falls_back_to_todo(): void
    {
        // Workflow mal configuré : on affiche l'état le moins engageant plutôt que rien.
        $this->assertSame(
            self::BADGE_TODO,
            $this->view()->getProgressBadge(['statusCategory' => 'categorie-inconnue', 'status' => 'Inconnu'])
        );
    }

    #[Test]
    public function test_progress_badge_without_any_key_falls_back_to_todo(): void
    {
        $this->assertSame(self::BADGE_TODO, $this->view()->getProgressBadge([]));
    }

    // ==================== collapseTicketsLabel ====================

    #[Test]
    public function test_collapse_label_without_ticket_announces_nothing_to_show(): void
    {
        // Ne pas inviter à déplier un tableau vide : le clic serait perdu.
        $this->assertSame('Aucun ticket à afficher', $this->view()->collapseTicketsLabel(0));
    }

    #[Test]
    public function test_collapse_label_with_single_ticket_uses_singular(): void
    {
        $this->assertSame('Afficher le ticket', $this->view()->collapseTicketsLabel(1));
    }

    #[Test]
    public function test_collapse_label_with_several_tickets_shows_the_count(): void
    {
        $this->assertSame('Afficher les 7 tickets', $this->view()->collapseTicketsLabel(7));
    }

    // ==================== getCategoryBreakdown ====================

    #[Test]
    public function test_category_breakdown_empty_list_returns_empty_array(): void
    {
        $this->assertSame([], $this->view()->getCategoryBreakdown([]));
    }

    #[Test]
    public function test_category_breakdown_computes_percent_per_category(): void
    {
        $breakdown = $this->view()->getCategoryBreakdown([
            $this->ticket(StatusCategory::DONE),
            $this->ticket(StatusCategory::DONE),
            $this->ticket(StatusCategory::SPRINT),
            $this->ticket(StatusCategory::REFINEMENT),
        ]);

        $this->assertCount(3, $breakdown);
        $this->assertSame(StatusCategory::DONE, $breakdown[0]['key']);
        $this->assertSame('Terminé', $breakdown[0]['label']);
        $this->assertSame(2, $breakdown[0]['count']);
        $this->assertSame(50.0, $breakdown[0]['percent']);
        $this->assertSame(25.0, $breakdown[1]['percent']);
        $this->assertSame(25.0, $breakdown[2]['percent']);
    }

    #[Test]
    public function test_category_breakdown_orders_categories_from_most_to_least_advanced(): void
    {
        // Ordre d'entrée volontairement inverse de l'ordre attendu en sortie.
        $breakdown = $this->view()->getCategoryBreakdown([
            $this->ticket(StatusCategory::OTHER),
            $this->ticket(StatusCategory::WAITING),
            $this->ticket(StatusCategory::REFINEMENT),
            $this->ticket(StatusCategory::SPRINT),
            $this->ticket(StatusCategory::DONE),
        ]);

        $this->assertSame(
            [
                StatusCategory::DONE,
                StatusCategory::SPRINT,
                StatusCategory::REFINEMENT,
                StatusCategory::WAITING,
                StatusCategory::OTHER,
            ],
            array_column($breakdown, 'key')
        );
    }

    #[Test]
    public function test_category_breakdown_excludes_categories_without_ticket(): void
    {
        $breakdown = $this->view()->getCategoryBreakdown([
            $this->ticket(StatusCategory::DONE),
        ]);

        $this->assertCount(1, $breakdown);
        $this->assertSame(100.0, $breakdown[0]['percent']);
    }

    #[Test]
    public function test_category_breakdown_unknown_category_is_counted_as_other(): void
    {
        // Un statut hors workflow ne doit pas disparaître : la jauge resterait sous 100 %.
        $breakdown = $this->view()->getCategoryBreakdown([
            $this->ticket(StatusCategory::DONE),
            $this->ticket('statut-inconnu'),
        ]);

        $this->assertSame([StatusCategory::DONE, StatusCategory::OTHER], array_column($breakdown, 'key'));
        $this->assertSame(50.0, $breakdown[1]['percent']);
    }

    #[Test]
    public function test_category_breakdown_missing_category_key_is_counted_as_other(): void
    {
        $breakdown = $this->view()->getCategoryBreakdown([[], []]);

        $this->assertCount(1, $breakdown);
        $this->assertSame(StatusCategory::OTHER, $breakdown[0]['key']);
        $this->assertSame(2, $breakdown[0]['count']);
    }

    #[Test]
    public function test_category_breakdown_percents_sum_to_one_hundred(): void
    {
        $breakdown = $this->view()->getCategoryBreakdown([
            $this->ticket(StatusCategory::DONE),
            $this->ticket(StatusCategory::SPRINT),
            $this->ticket(StatusCategory::WAITING),
            $this->ticket(StatusCategory::REFINEMENT),
        ]);

        $this->assertSame(100.0, array_sum(array_column($breakdown, 'percent')));
    }
}
