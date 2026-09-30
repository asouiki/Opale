<?php

namespace Tests\Unit\Service;

use App\Service\AbsenceMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'association nom fichier ↔ membre (correspondance exacte normalisée : casse/accents ignorés).
 */
class AbsenceMatcherTest extends TestCase
{
    /** @return list<array{id: string, name: string}> */
    private function roster(): array
    {
        return [
            ['id' => 'm1', 'name' => 'Camille'],
            ['id' => 'm2', 'name' => 'Loïc'],
            ['id' => 'm3', 'name' => 'Jérôme'],
            ['id' => 'm4', 'name' => 'Hugues-Arnaud'],
        ];
    }

    #[Test]
    public function test_match_ignoreCasseEtAccents(): void
    {
        $result = (new AbsenceMatcher())->match(['camille', 'Loic', 'JEROME'], $this->roster());

        $this->assertSame(['camille' => 'm1', 'Loic' => 'm2', 'JEROME' => 'm3'], $result['matched']);
        $this->assertSame([], $result['unmatched']);
    }

    #[Test]
    public function test_match_nomAvecPrefixeNonReconnu(): void
    {
        // Correspondance exacte : « Lamot Hugues-Arnaud » ≠ « Hugues-Arnaud » → non matché (à corriger à la main).
        $result = (new AbsenceMatcher())->match(['Lamot Hugues-Arnaud', 'Camille'], $this->roster());

        $this->assertSame(['Camille' => 'm1'], $result['matched']);
        $this->assertSame(['Lamot Hugues-Arnaud'], $result['unmatched']);
    }
}
