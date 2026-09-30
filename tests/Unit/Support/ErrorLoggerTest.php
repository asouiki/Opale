<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ErrorLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Couvre le journal des erreurs bloquantes : format horodaté, rattachement du détail technique et
 * garde-fous contre la saturation disque (troncature, purge au-delà de la rétention).
 */
class ErrorLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = ErrorLogger::LOG_DIR . '/error-' . date('Y-m-d') . '.log';
        $this->removeLogs();
    }

    protected function tearDown(): void
    {
        $this->removeLogs();
    }

    /**
     * Supprime les journaux du jour pour repartir d'un état propre.
     */
    private function removeLogs(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    #[Test]
    public function test_critical_exceptionSimple_ecritUneLigneHorodatee(): void
    {
        ErrorLogger::critical(new RuntimeException('Cache injoignable'));

        $content = (string) file_get_contents($this->logFile);

        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Cache injoignable', $content);
    }

    #[Test]
    public function test_critical_avecDetail_joutLeDetailSousLEntree(): void
    {
        ErrorLogger::critical(new RuntimeException('Réponse invalide'), 'Chemin visé : /var/data/x.json');

        $content = (string) file_get_contents($this->logFile);

        $this->assertStringContainsString('detail:', $content);
        $this->assertStringContainsString('/var/data/x.json', $content);
    }

    #[Test]
    public function test_critical_detailMultiligne_estRameneSurUneSeuleLigne(): void
    {
        // Une réponse d'API brute est multiligne : le journal doit rester exploitable ligne par ligne.
        ErrorLogger::critical(new RuntimeException('Erreur'), "Array\n(\n    [a] => 1\n)");

        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));

        $this->assertCount(2, $lines, 'Une entrée = 1 ligne d\'erreur + 1 ligne de détail');
    }

    #[Test]
    public function test_critical_detailTresVolumineux_estTronque(): void
    {
        // Garde-fou disque : une réponse Jira peut peser plusieurs Mo.
        ErrorLogger::critical(new RuntimeException('Erreur'), str_repeat('x', 10000));

        $content = (string) file_get_contents($this->logFile);

        $this->assertStringContainsString('[…tronqué]', $content);
        $this->assertLessThan(4000, strlen($content));
    }

    #[Test]
    public function test_critical_appelsMultiples_ajouteSansEcraser(): void
    {
        ErrorLogger::critical(new RuntimeException('Première'));
        ErrorLogger::critical(new RuntimeException('Seconde'));

        $content = (string) file_get_contents($this->logFile);

        $this->assertStringContainsString('Première', $content);
        $this->assertStringContainsString('Seconde', $content);
    }

    #[Test]
    public function test_critical_exceptionAvecCause_journaliseLaCauseReelle(): void
    {
        // Cas motivant : le message de tête est générique (destiné à l'utilisateur) et masque le
        // vrai diagnostic. Sans la cause, un 401 est indistinguable d'un timeout ou d'un rate-limit.
        ErrorLogger::critical(new RuntimeException(
            'Connexion à Jira impossible : identifiants manquants ou invalides.',
            0,
            new RuntimeException('Requête Jira : erreur HTTP 401 : Basic auth is not allowed')
        ));

        $content = (string) file_get_contents($this->logFile);

        $this->assertStringContainsString('cause:', $content);
        $this->assertStringContainsString('erreur HTTP 401', $content);
    }

    #[Test]
    public function test_critical_causesImbriquees_sontToutesJournaliseesDansLOrdre(): void
    {
        ErrorLogger::critical(new RuntimeException(
            'Tete',
            0,
            new RuntimeException('Cause intermediaire', 0, new RuntimeException('Cause racine'))
        ));

        $content = (string) file_get_contents($this->logFile);

        // De la plus proche à la plus lointaine : l'ordre porte le sens (on remonte la pile des causes).
        $this->assertLessThan(
            strpos($content, 'Cause racine'),
            strpos($content, 'Cause intermediaire'),
            'La cause immédiate doit précéder la cause racine'
        );
    }

    #[Test]
    public function test_critical_chaineDeCausesTresLongue_estBorneeEnProfondeur(): void
    {
        // Garde-fou : une chaîne anormalement longue (ou cyclique) ne doit pas noyer le journal.
        $cause = new RuntimeException('cause-0');
        for ($i = 1; $i <= 12; $i++) {
            $cause = new RuntimeException('cause-' . $i, 0, $cause);
        }

        ErrorLogger::critical($cause);

        $content = (string) file_get_contents($this->logFile);

        $this->assertSame(5, substr_count($content, '    cause: '), 'Profondeur bornée à MAX_CAUSE_DEPTH');
    }

    #[Test]
    public function test_critical_exceptionSansCause_nAjouteAucuneLigneDeCause(): void
    {
        ErrorLogger::critical(new RuntimeException('Sans cause'));

        $content = (string) file_get_contents($this->logFile);

        $this->assertStringNotContainsString('cause:', $content);
    }

    #[Test]
    public function test_critical_journalPerime_estPurgeALaPremiereEcritureDuJour(): void
    {
        // Journal daté au-delà de la rétention (30 j) : doit disparaître à la première écriture du jour.
        $expired = ErrorLogger::LOG_DIR . '/error-2000-01-01.log';
        if (!is_dir(ErrorLogger::LOG_DIR)) {
            mkdir(ErrorLogger::LOG_DIR, 0775, true);
        }
        file_put_contents($expired, 'ancien');
        touch($expired, time() - (40 * 86400));

        ErrorLogger::critical(new RuntimeException('Déclencheur'));

        $this->assertFileDoesNotExist($expired);
        $this->assertFileExists($this->logFile);
    }
}
