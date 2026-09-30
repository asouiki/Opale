<?php

namespace Tests\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests du calcul de doneDate par Timeline::buildStatusTimeline.
 *
 * Règle : doneDate est mise à jour uniquement quand le statut source (fromString)
 * N'EST PAS dans done_statuses. Cela garantit que :
 *  - une transition done→done (ex. "Done" → "Terminé") ne réinitialise pas la date ;
 *  - un aller-retour légitime (done→non-done→done) met bien à jour la doneDate.
 *
 * Le workflow de test utilise deux statuts done distincts ("Done" et "Terminé")
 * pour couvrir tous les cas de figure.
 */
class DoneDateTest extends TestCase
{
    private IssueBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new FakeConfig([], [
            'refinement_statuses' => ['To Do'],
            'sprint_statuses'     => ['À faire', 'In Progress', 'En cours'],
            'done_statuses'       => ['Done', 'Terminé'],
            'waiting_statuses'    => ['En Attente'],
        ]);
        $this->builder = new IssueBuilder($config);
    }

    // =========================================================================
    // Passage unique vers done
    // =========================================================================

    #[Test]
    public function test_transition_unique_vers_done_set_donedate(): void
    {
        // Refinement → Sprint → Done : cas nominal, doneDate = date de la transition Done.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-23T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Transition unique vers Done → doneDate = date de la transition'
        );
    }

    #[Test]
    public function test_sans_transition_done_donedate_est_nulle(): void
    {
        // Ticket toujours en Sprint, jamais passé en Done.
        $issue = $this->builder
            ->withStatus('In Progress')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->build();

        $this->assertNull(
            $issue->getDoneDate(false),
            'Aucune transition vers Done → doneDate doit être null'
        );
    }

    // =========================================================================
    // Transition done → done (le bug corrigé)
    // =========================================================================

    #[Test]
    public function test_transition_done_vers_done_ne_modifie_pas_donedate(): void
    {
        // Cas réel signalé : In Progress → Done (23/09) → Terminé (29/05/2026).
        // La transition Done→Terminé ne doit pas écraser la doneDate du 23/09.
        $issue = $this->builder
            ->withStatus('Terminé')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->withStatusTransition('2026-05-29T10:00:00+02:00', 'Done', 'Terminé')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-23T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Transition done→done (Done→Terminé) ne doit pas écraser la doneDate initiale'
        );
    }

    #[Test]
    public function test_chaine_done_vers_done_multiple_garde_premier(): void
    {
        // Refinement → Sprint → Done → Terminé → Done (trois statuts done successifs).
        // Seule la première entrée dans done (depuis Sprint) doit compter.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->withStatusTransition('2025-10-15T09:00:00+02:00', 'Done', 'Terminé')
            ->withStatusTransition('2025-11-01T09:00:00+02:00', 'Terminé', 'Done')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-23T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Chaîne done→done→done : seul le premier passage depuis non-done compte'
        );
    }

    // =========================================================================
    // Aller-retour légitime (réouverture puis re-done)
    // =========================================================================

    #[Test]
    public function test_aller_retour_done_sprint_done_met_a_jour_donedate(): void
    {
        // Done → réouvert (In Progress) → Done à nouveau.
        // La seconde transition non-done→done doit mettre à jour doneDate.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->withStatusTransition('2025-09-25T09:00:00+02:00', 'Done', 'In Progress')  // réouverture
            ->withStatusTransition('2025-10-05T09:00:00+02:00', 'In Progress', 'Done')         // re-done
            ->build();

        $this->assertEquals(
            new \DateTime('2025-10-05T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Réouverture puis re-done → doneDate = date du second passage en Done'
        );
    }

    #[Test]
    public function test_aller_retour_done_refinement_done_met_a_jour_donedate(): void
    {
        // Done → retour en refinement (To Do) → Sprint → Done.
        // Même logique : le retour depuis un statut non-done doit mettre à jour doneDate.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->withStatusTransition('2025-09-25T09:00:00+02:00', 'Done', 'To Do')        // retour refinement
            ->withStatusTransition('2025-09-26T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-10-05T09:00:00+02:00', 'In Progress', 'Done')        // re-done
            ->build();

        $this->assertEquals(
            new \DateTime('2025-10-05T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Retour en refinement puis re-done → doneDate = date du second passage en Done'
        );
    }

    // =========================================================================
    // Catégories de statuts intermédiaires
    // =========================================================================

    #[Test]
    public function test_passage_par_waiting_avant_done(): void
    {
        // Refinement → Sprint → Waiting → Sprint → Done.
        // Le statut waiting ne doit pas affecter doneDate.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-05T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'In Progress', 'En Attente')
            ->withStatusTransition('2025-09-12T09:00:00+02:00', 'En Attente', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-23T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Passage par Waiting ne doit pas affecter doneDate'
        );
        $this->assertNotNull(
            $issue->getFirstInProgressDate(false),
            'firstInProgressDate doit être set après la transition In Progress'
        );
    }

    #[Test]
    public function test_transition_directe_refinement_vers_done(): void
    {
        // To Do → Done directement (sans passer par Sprint).
        // Cas moins courant mais valide.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'To Do', 'Done')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-23T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'Transition directe refinement→done doit set doneDate'
        );
    }

    #[Test]
    public function test_done_vers_done_puis_aller_retour_met_a_jour_date(): void
    {
        // Done → Terminé (done→done, ignoré) → In Progress (réouverture) → Done.
        // Le troisième passage en Done (depuis non-done) doit mettre à jour doneDate.
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->withStatusTransition('2025-09-23T09:00:00+02:00', 'In Progress', 'Done')
            ->withStatusTransition('2025-09-24T09:00:00+02:00', 'Done', 'Terminé')      // done→done ignoré
            ->withStatusTransition('2025-09-25T09:00:00+02:00', 'Terminé', 'In Progress')  // réouverture
            ->withStatusTransition('2025-10-05T09:00:00+02:00', 'In Progress', 'Done')        // re-done légitime
            ->build();

        $this->assertEquals(
            new \DateTime('2025-10-05T09:00:00+02:00'),
            $issue->getDoneDate(false),
            'done→done ignoré mais aller-retour ultérieur met bien à jour doneDate'
        );
    }
}
