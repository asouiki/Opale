<?php

namespace Tests\Unit\Service;

use App\Service\TeamConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du hub de configuration par projet (<KEY>_team_config.json).
 *
 * On isole l'écriture dans un répertoire temporaire injecté au constructeur pour
 * ne jamais toucher la vraie config. On se concentre sur les sprints ignorés et
 * sur la coexistence des différentes clés dans un même fichier (pas d'écrasement).
 */
class TeamConfigRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/team_config_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function repo(): TeamConfigRepository
    {
        return new TeamConfigRepository($this->tmpDir);
    }

    #[Test]
    public function test_getIgnoredSprints_fichierAbsent_retourneTableauVide(): void
    {
        $this->assertSame([], $this->repo()->getIgnoredSprints('ABC'));
        $this->assertSame([], $this->repo()->getIgnoredSprintNames('ABC'));
    }

    #[Test]
    public function test_setIgnoredSprints_persisteEtRelit(): void
    {
        $this->repo()->setIgnoredSprints('ABC', [
            ['id' => '22370', 'name' => 'ABC prio'],
            ['id' => '22371', 'name' => 'ABC PI9'],
        ]);

        $this->assertSame(
            ['ABC prio', 'ABC PI9'],
            $this->repo()->getIgnoredSprintNames('ABC')
        );
    }

    #[Test]
    public function test_getIgnoredSprintNames_ignoreLesEntreesSansNom(): void
    {
        $this->repo()->setIgnoredSprints('ABC', [
            ['id' => '1', 'name' => 'Réel'],
            ['id' => '2', 'name' => ''],
        ]);

        $this->assertSame(['Réel'], $this->repo()->getIgnoredSprintNames('ABC'));
    }

    #[Test]
    public function test_lesCles_coexistentSansSEcraser(): void
    {
        $repo = $this->repo();
        $repo->setPreferredBoardId('ABC', 123);
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setWorkflow('ABC', ['sprint_statuses' => ['En cours']]);
        $repo->setIgnoredSprints('ABC', [['id' => '9', 'name' => 'Fictif']]);
        $repo->setAvgVelocity('ABC', 0.26);
        $repo->setPlanningMargin('ABC', 30);

        // Chaque écriture ne touche que sa propre clé.
        $this->assertSame(123, $repo->getPreferredBoardId('ABC'));
        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertTrue($repo->hasWorkflow('ABC'));
        $this->assertSame(['Fictif'], $repo->getIgnoredSprintNames('ABC'));
        $this->assertSame(0.26, $repo->getAvgVelocity('ABC'));
        $this->assertSame(30, $repo->getPlanningMargin('ABC'));
    }

    #[Test]
    public function test_getAvgVelocity_fichierAbsent_retourneNull(): void
    {
        $this->assertNull($this->repo()->getAvgVelocity('ABC'));
    }

    #[Test]
    public function test_setAvgVelocity_persisteEtRelit(): void
    {
        $repo = $this->repo();
        $repo->setAvgVelocity('ABC', 0.26);

        $this->assertSame(0.26, $repo->getAvgVelocity('ABC'));
    }

    #[Test]
    public function test_getAvgVelocity_valeurNulleOuNegative_estTraiteeCommeAbsente(): void
    {
        $repo = $this->repo();
        $repo->setAvgVelocity('ABC', 0.0);

        $this->assertNull($repo->getAvgVelocity('ABC'));
    }

    #[Test]
    public function test_setAvgVelocity_nEcrasePasLaGrilleDeCapacite(): void
    {
        $repo = $this->repo();
        $repo->setSprintCapacity('ABC', '27440', [
            'members' => ['m1' => ['presence' => ['2026-08-10' => 0]]],
            'totals'  => ['presenceTotal' => 93, 'capa' => ['Dev' => 19], 'capaTotal' => 50.5],
        ]);

        $repo->setAvgVelocity('ABC', 0.26);

        // L'écriture automatique déclenchée par /team-metrics ne doit rien perdre du reste du fichier.
        $this->assertSame(50.5, $repo->getCapacityManDays('ABC', '27440'));
        $this->assertSame(0.26, $repo->getAvgVelocity('ABC'));
    }

    #[Test]
    public function test_getPlanningMargin_absente_retourneVingt(): void
    {
        $this->assertSame(20, $this->repo()->getPlanningMargin('ABC'));
    }

    #[Test]
    public function test_setPlanningMargin_persisteEtRelit(): void
    {
        $repo = $this->repo();
        $repo->setPlanningMargin('ABC', 30);

        $this->assertSame(30, $repo->getPlanningMargin('ABC'));
    }

    #[Test]
    public function test_getPlanningMargin_valeurHorsBornes_estRamenee(): void
    {
        $repo = $this->repo();
        $repo->setPlanningMargin('ABC', 150);
        $this->assertSame(100, $repo->getPlanningMargin('ABC'));

        $repo->setPlanningMargin('ABC', -10);
        $this->assertSame(0, $repo->getPlanningMargin('ABC'));
    }

    #[Test]
    public function test_issueTypes_absent_hasFalseEtListeVide(): void
    {
        $repo = $this->repo();
        $this->assertFalse($repo->hasIssueTypes('ABC'));
        $this->assertSame([], $repo->getIssueTypes('ABC'));
    }

    #[Test]
    public function test_setIssueTypes_persisteEtMarquePresence(): void
    {
        $repo = $this->repo();
        $repo->setIssueTypes('ABC', ['Story', 'Bug']);

        $this->assertTrue($repo->hasIssueTypes('ABC'));
        $this->assertSame(['Story', 'Bug'], $repo->getIssueTypes('ABC'));
    }

    #[Test]
    public function test_setIssueTypes_listeVide_resteMarqueeCommePresente(): void
    {
        // Tout retirer = liste présente mais vide (≠ absence → pas de fallback global).
        $repo = $this->repo();
        $repo->setIssueTypes('ABC', []);

        $this->assertTrue($repo->hasIssueTypes('ABC'));
        $this->assertSame([], $repo->getIssueTypes('ABC'));
    }

    #[Test]
    public function test_isoleParProjet(): void
    {
        $repo = $this->repo();
        $repo->setIgnoredSprints('ABC', [['id' => '1', 'name' => 'A']]);
        $repo->setIgnoredSprints('XYZ', [['id' => '2', 'name' => 'X']]);

        $this->assertSame(['A'], $repo->getIgnoredSprintNames('ABC'));
        $this->assertSame(['X'], $repo->getIgnoredSprintNames('XYZ'));
    }

    #[Test]
    public function test_getTeamMembers_fichierAbsent_retourneTableauVide(): void
    {
        $this->assertSame([], $this->repo()->getTeamMembers('ABC'));
    }

    #[Test]
    public function test_setTeamMembers_persisteEtRelit(): void
    {
        $members = [
            ['id' => 'm1', 'name' => 'Pierre', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
            ['id' => 'm2', 'name' => 'Jérôme', 'role' => 'BA', 'entryDate' => '2026-01-01', 'exitDate' => '2026-06-30'],
        ];
        $this->repo()->setTeamMembers('ABC', $members);

        $this->assertSame($members, $this->repo()->getTeamMembers('ABC'));
    }

    #[Test]
    public function test_getSprintCapacity_absent_retourneTableauVide(): void
    {
        $this->assertSame([], $this->repo()->getSprintCapacity('ABC', '12345'));
    }

    #[Test]
    public function test_setSprintCapacity_persisteEtRelit(): void
    {
        $capacity = [
            'members' => [
                'm1' => ['presence' => ['2026-01-08' => 0.5], 'events' => 2.0, 'ratio' => 80.0],
            ],
            'totals' => [
                'presenceTotal' => 10.0,
                'capa'          => ['Dev' => 8.0, 'BA' => 0.0, 'QA' => 0.0],
                'capaTotal'     => 8.0,
            ],
        ];
        $this->repo()->setSprintCapacity('ABC', '12345', $capacity);

        $this->assertSame($capacity, $this->repo()->getSprintCapacity('ABC', '12345'));
    }

    #[Test]
    public function test_setSprintCapacity_sansMembre_retireLEntreeSansToucherLesAutres(): void
    {
        $repo     = $this->repo();
        $capacity = ['members' => ['m1' => ['events' => 1.0]]];
        $repo->setSprintCapacity('ABC', '111', $capacity);
        $repo->setSprintCapacity('ABC', '222', $capacity);

        // Grille sans aucun membre → l'entrée du sprint 111 est retirée, 222 reste intact.
        $repo->setSprintCapacity('ABC', '111', ['members' => []]);

        $this->assertSame([], $repo->getSprintCapacity('ABC', '111'));
        $this->assertSame($capacity, $repo->getSprintCapacity('ABC', '222'));
    }

    #[Test]
    public function test_setSprintCapacity_totauxSansAbsence_avecPresence_estPersistee(): void
    {
        // Grille « tout le monde présent » : aucun membre en écart, mais une capacité d'équipe réelle.
        $capacity = [
            'totals' => [
                'presenceTotal' => 64.0,
                'capa'          => ['Dev' => 13.0, 'BA' => 13.0, 'QA' => 12.0],
                'capaTotal'     => 38.0,
            ],
        ];
        $repo = $this->repo();
        $repo->setSprintCapacity('ABC', '29269', $capacity);

        $this->assertSame($capacity, $repo->getSprintCapacity('ABC', '29269'));
        $this->assertSame(38.0, $repo->getCapacityManDays('ABC', '29269'));
    }

    #[Test]
    public function test_setSprintCapacity_totauxSansPresence_retireLEntree(): void
    {
        $repo = $this->repo();
        // Roster vide (presenceTotal 0) → grille sans capacité exploitable → non persistée.
        $repo->setSprintCapacity('ABC', '29269', [
            'totals' => ['presenceTotal' => 0.0, 'capa' => ['Dev' => 0.0, 'BA' => 0.0, 'QA' => 0.0], 'capaTotal' => 0.0],
        ]);

        $this->assertSame([], $repo->getSprintCapacity('ABC', '29269'));
    }

    #[Test]
    public function test_isCorrupted_fichierAbsent_retourneFalse(): void
    {
        // Absent → projet neuf, régénération légitime (pas une corruption).
        $this->assertFalse($this->repo()->isCorrupted('ABC'));
    }

    #[Test]
    public function test_isCorrupted_contenuVide_retourneFalse(): void
    {
        // 0 octet, {}, [] et blancs seuls sont des états « vides » régénérables, pas des corruptions.
        foreach (['', '{}', '[]', "  \r\n "] as $content) {
            $this->writeRaw('ABC', $content);
            $this->assertFalse(
                $this->repo()->isCorrupted('ABC'),
                'contenu attendu non corrompu: ' . var_export($content, true)
            );
        }
    }

    #[Test]
    public function test_isCorrupted_jsonValide_retourneFalse(): void
    {
        $this->repo()->setIssueTypes('ABC', ['Story', 'Bug']);
        $this->assertFalse($this->repo()->isCorrupted('ABC'));
    }

    #[Test]
    public function test_isCorrupted_contenuNonVideNonParsable_retourneTrue(): void
    {
        // Objet non fermé (cas réel de la troncature d'écriture) → corrompu.
        $this->writeRaw('ABC', '{"issueTypes": ["Story"],');
        $this->assertTrue($this->repo()->isCorrupted('ABC'));
    }

    /**
     * Écrit un contenu brut (potentiellement invalide) dans le fichier de config du projet,
     * pour simuler un fichier vide ou corrompu sans passer par le repository.
     */
    private function writeRaw(string $projectKey, string $content): void
    {
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }
        file_put_contents($this->tmpDir . '/' . $projectKey . '_team_config.json', $content);
    }

    #[Test]
    public function test_membersEtCapacity_coexistentAvecLesAutresCles(): void
    {
        $repo = $this->repo();
        $repo->setPreferredBoardId('ABC', 123);
        $repo->setManDays('ABC', '10496', 25.0);
        $repo->setTeamMembers('ABC', [
            ['id' => 'm1', 'name' => 'Pierre', 'role' => 'Dev', 'entryDate' => '', 'exitDate' => ''],
        ]);
        $repo->setSprintCapacity('ABC', '10496', ['members' => ['m1' => ['ratio' => 50.0]]]);

        // Aucune écriture n'écrase les autres clés du fichier.
        $this->assertSame(123, $repo->getPreferredBoardId('ABC'));
        $this->assertSame(25.0, $repo->getManDays('ABC', '10496'));
        $this->assertCount(1, $repo->getTeamMembers('ABC'));
        $this->assertSame(['members' => ['m1' => ['ratio' => 50.0]]], $repo->getSprintCapacity('ABC', '10496'));
    }
}
