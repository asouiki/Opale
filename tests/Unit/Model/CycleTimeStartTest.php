<?php

namespace Tests\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeConfig;
use Tests\Utils\IssueBuilder;

/**
 * Tests de la détection de la prise en charge (firstInProgressDate → cycle time)
 * par Timeline::buildStatusTimeline.
 *
 * Règle : la prise en charge correspond au PREMIER passage dans un statut de la
 * catégorie sprint_statuses AUTRE que le statut de tête (le plus haut = « non
 * démarré »). Les statuts sont normalisés au passage (To Do → À faire,
 * In Progress → En cours), pour rester indépendant du libellé natif Jira.
 *
 * Le workflow de test démarre par « À faire » (entrée non démarrée), suivi de
 * plusieurs statuts d'avancement pour couvrir les cas réels (ex. projet ABC où un
 * ticket peut passer directement de « À faire » à « Qualif En Cours »).
 */
class CycleTimeStartTest extends TestCase
{
    private IssueBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new FakeConfig([], [
            'refinement_statuses' => ['Backlog'],
            'sprint_statuses'     => ['À faire', 'En cours', 'Qualif En Cours'],
            'done_statuses'       => ['Done'],
            'waiting_statuses'    => [],
        ]);
        $this->builder = new IssueBuilder($config);
    }

    #[Test]
    public function test_passage_direct_vers_qualif_en_cours_set_firstInProgress(): void
    {
        // Cas ABC : « À faire » → « Qualif En Cours » → « Done », sans passer par
        // « En cours ». La prise en charge = entrée en « Qualif En Cours ».
        $issue = $this->builder
            ->withStatus('Done')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'À faire', 'Qualif En Cours')
            ->withStatusTransition('2025-09-22T09:00:00+02:00', 'Qualif En Cours', 'Done')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-10T09:00:00+02:00'),
            $issue->getFirstInProgressDate(false),
            'Entrée en « Qualif En Cours » (statut sprint ≠ tête) doit dater la prise en charge'
        );
        $this->assertGreaterThan(
            0,
            $issue->getCycleTime(),
            'Avec firstInProgressDate et doneDate, le cycle time doit être strictement positif'
        );
    }

    #[Test]
    public function test_statut_de_tete_ne_compte_pas_comme_prise_en_charge(): void
    {
        // Le ticket entre dans « À faire » (statut de tête = non démarré) et n'en
        // bouge plus : aucune prise en charge ne doit être datée.
        $issue = $this->builder
            ->withStatus('À faire')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'Backlog', 'À faire')
            ->build();

        $this->assertNull(
            $issue->getFirstInProgressDate(false),
            'Entrée dans le statut de tête « À faire » ne doit pas dater la prise en charge'
        );
        $this->assertSame(
            0.0,
            $issue->getCycleTime(),
            'Sans firstInProgressDate, le cycle time doit être nul'
        );
    }

    #[Test]
    public function test_premier_passage_en_cours_gagne(): void
    {
        // « À faire » → « En cours » → « Qualif En Cours » : la date retenue est
        // celle du PREMIER statut d'avancement (« En cours »).
        $issue = $this->builder
            ->withStatus('Qualif En Cours')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'À faire', 'En cours')
            ->withStatusTransition('2025-09-15T09:00:00+02:00', 'En cours', 'Qualif En Cours')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-10T09:00:00+02:00'),
            $issue->getFirstInProgressDate(false),
            'Le premier passage dans un statut d\'avancement doit gagner'
        );
    }

    #[Test]
    public function test_cas_ABC_done_natif_vs_config_normalisee(): void
    {
        // Régression ABC : la config est en libellés d'AFFICHAGE (« Terminé(e) »,
        // « À faire »…) tandis que le changelog Jira émet les libellés NATIFS bruts
        // (« To Do », « In Progress », « Terminé »/« Done »). Sans normalisation des deux
        // côtés, doneDate n'est jamais détectée → lead time & cycle time à 0 (bug observé).
        $config = new FakeConfig([], [
            'refinement_statuses' => ['Backlog'],
            'sprint_statuses'     => ['À faire', 'En cours', 'Qualif En Cours'],
            'done_statuses'       => ['Terminé(e)', 'Abandonné'],
            'waiting_statuses'    => [],
        ]);

        $issue = (new IssueBuilder($config))
            ->withStatus('Terminé(e)')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-08T09:00:00+02:00', 'To Do', 'In Progress') // natifs bruts
            ->withStatusTransition('2025-09-22T09:00:00+02:00', 'In Progress', 'Terminé')      // natif brut → Terminé(e)
            ->build();

        $this->assertNotNull(
            $issue->getDoneDate(false),
            'doneDate doit être détectée malgré le libellé natif « Terminé » (normalisé en « Terminé(e) »)'
        );
        $this->assertGreaterThan(
            0,
            $issue->getCycleTime(),
            'Cycle time doit être positif (prise en charge « In Progress » → done « Terminé »)'
        );
        $this->assertGreaterThan(
            0,
            $issue->getLeadTime(),
            'Lead time doit être positif (création → doneDate)'
        );
    }

    #[Test]
    public function test_statut_natif_in_progress_est_normalise(): void
    {
        // Workflow en libellés affichés (« À faire », « En cours ») mais changelog
        // en libellés natifs (« To Do », « In Progress ») : la normalisation doit
        // faire correspondre « In Progress » → « En cours » (statut sprint ≠ tête).
        $config = new FakeConfig([], [
            'refinement_statuses' => ['Backlog'],
            'sprint_statuses'     => ['À faire', 'En cours'],
            'done_statuses'       => ['Done'],
            'waiting_statuses'    => [],
        ]);

        $issue = (new IssueBuilder($config))
            ->withStatus('In Progress')
            ->withCreatedDate('2025-09-01T08:00:00+02:00')
            ->withStatusTransition('2025-09-10T09:00:00+02:00', 'To Do', 'In Progress')
            ->build();

        $this->assertEquals(
            new \DateTime('2025-09-10T09:00:00+02:00'),
            $issue->getFirstInProgressDate(false),
            '« In Progress » natif doit être normalisé en « En cours » et dater la prise en charge'
        );
    }
}
