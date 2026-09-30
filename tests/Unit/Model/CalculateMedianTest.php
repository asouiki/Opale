<?php

namespace Tests\Unit\Model;

use App\Model\AbstractIssueCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Utils\FakeIssueCollection;

/**
 * Tests de AbstractIssueCollection::calculateMedian().
 *
 * Couvre le calcul de la médiane sur des cas représentatifs (impair, pair,
 * non trié, doublons, valeur unique) et le cas limite du tableau vide, qui
 * doit renvoyer 0.0 (float).
 */
class CalculateMedianTest extends TestCase
{
    /**
     * Invoque la méthode protégée calculateMedian() sur une sous-classe concrète
     * minimale instanciée sans constructeur (aucun appel Jira).
     *
     * @param array $values Valeurs numériques
     * @return float Médiane calculée
     */
    private function median(array $values): float
    {
        $collection = (new ReflectionClass(FakeIssueCollection::class))
            ->newInstanceWithoutConstructor();

        return (new ReflectionMethod(AbstractIssueCollection::class, 'calculateMedian'))
            ->invoke($collection, $values);
    }

    #[Test]
    public function test_calculateMedian_tableauVide_retourneZeroFloat(): void
    {
        $result = $this->median([]);

        // Tableau vide → médiane 0.0 (float).
        $this->assertSame(0.0, $result);
    }

    #[Test]
    public function test_calculateMedian_nombreImpair_retourneValeurCentrale(): void
    {
        // [1, 5, 9] → valeur du milieu = 5
        $this->assertSame(5.0, $this->median([1.0, 5.0, 9.0]));
    }

    #[Test]
    public function test_calculateMedian_nombrePair_retourneMoyenneDesDeuxCentrales(): void
    {
        // [2, 4, 6, 8] → (4 + 6) / 2 = 5
        $this->assertSame(5.0, $this->median([2.0, 4.0, 6.0, 8.0]));
    }

    #[Test]
    public function test_calculateMedian_valeursNonTriees_trieAvantCalcul(): void
    {
        // Désordre [9, 1, 5] → trié [1, 5, 9] → 5
        $this->assertSame(5.0, $this->median([9.0, 1.0, 5.0]));
    }

    #[Test]
    public function test_calculateMedian_avecDoublons_lesPrendEnCompte(): void
    {
        // [3, 3, 3, 9] → (3 + 3) / 2 = 3
        $this->assertSame(3.0, $this->median([3.0, 3.0, 3.0, 9.0]));
    }

    #[Test]
    public function test_calculateMedian_valeurUnique_retourneCetteValeur(): void
    {
        $this->assertSame(7.0, $this->median([7.0]));
    }
}
