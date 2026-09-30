<?php

namespace Tests\Unit\Support;

use App\Support\JsonFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la passerelle JSON sur fichier (lecture décodée + écriture atomique).
 */
class JsonFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/jsonfile_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    // =====================================================================
    // read()
    // =====================================================================

    #[Test]
    public function test_read_fichierAbsent_retourneTableauVide(): void
    {
        $this->assertSame([], JsonFile::read($this->dir . '/absent.json'));
    }

    #[Test]
    public function test_read_jsonInvalide_retourneTableauVide(): void
    {
        $path = $this->dir . '/bad.json';
        JsonFile::write($path, 'pas du json {{{');

        $this->assertSame([], JsonFile::read($path));
    }

    #[Test]
    public function test_read_jsonValide_retourneTableauDecode(): void
    {
        $path = $this->dir . '/ok.json';
        JsonFile::write($path, json_encode(['a' => 1, 'b' => ['c' => 2]], JSON_THROW_ON_ERROR));

        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], JsonFile::read($path));
    }

    // =====================================================================
    // write()
    // =====================================================================

    #[Test]
    public function test_write_creeLeRepertoireParent_etEcritLeContenu(): void
    {
        // Le répertoire n'existe pas encore : write() doit le créer.
        $path = $this->dir . '/nested/deep/cache.json';

        $ok = JsonFile::write($path, json_encode(['x' => 42], JSON_THROW_ON_ERROR));

        $this->assertTrue($ok);
        $this->assertFileExists($path);
        $this->assertSame(['x' => 42], JsonFile::read($path));
    }

    #[Test]
    public function test_write_ecraseLeContenuPrecedent(): void
    {
        $path = $this->dir . '/c.json';
        JsonFile::write($path, json_encode(['v' => 1], JSON_THROW_ON_ERROR));
        JsonFile::write($path, json_encode(['v' => 2], JSON_THROW_ON_ERROR));

        $this->assertSame(['v' => 2], JsonFile::read($path));
    }

    #[Test]
    public function test_write_neLaissePasDeFichierTemporaire(): void
    {
        // L'écriture atomique passe par un .tmp renommé : aucun résidu ne doit subsister.
        $path = $this->dir . '/atomic.json';
        JsonFile::write($path, json_encode(['ok' => true], JSON_THROW_ON_ERROR));

        $residus = glob($this->dir . '/*.tmp.*') ?: [];
        $this->assertSame([], $residus);
    }

    #[Test]
    public function test_write_puis_read_roundtrip_preserveLeContenu(): void
    {
        $path    = $this->dir . '/roundtrip.json';
        $payload = ['updatedAt' => 1234, 'items' => ['a', 'b'], 'nested' => ['k' => 'é/ç']];

        JsonFile::write($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame($payload, JsonFile::read($path));
    }
}
