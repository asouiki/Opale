<?php

namespace Tests\Unit\Service;

use App\Model\Config;
use App\Service\GovApiService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests du filtrage des jours fériés sur la plage d'années pertinente.
 *
 * La logique réelle vit dans Config::getNonWorkingDays() : elle appelle
 * GovApiService::fetchAllHolidays() puis ne conserve que les dates de la plage
 * [année_courante - 5 ; année_courante]. Les dates sont au format d/m/Y
 * (normalisées par GovApiService), l'année étant extraite via substr($date, -4).
 *
 * Pour éviter tout appel HTTP sans refondre Config (qui instancie GovApiService
 * en dur), on amorce le cache mémoire statique de GovApiService par réflexion :
 * fetchAllHolidays() le renvoie alors directement.
 */
class GovApiServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // Les deux caches mémoire sont statiques → on les réinitialise pour ne pas polluer
        // les autres tests (isolation). Config::$nonWorkingDaysCache mémoïse le résultat déjà
        // filtré de getNonWorkingDays() : sans ce reset, le test suivant hériterait de la liste
        // amorcée par celui-ci au lieu de sa propre donnée seedée.
        $prop = new ReflectionProperty(GovApiService::class, 'memoryCache');
        $prop->setValue(null, []);

        $configCache = new ReflectionProperty(Config::class, 'nonWorkingDaysCache');
        $configCache->setValue(null, null);

        parent::tearDown();
    }

    /**
     * Amorce le cache mémoire statique de GovApiService pour la zone métropole.
     *
     * @param array<string, string> $holidays [date d/m/Y => libellé]
     */
    private function seedHolidaysCache(array $holidays): void
    {
        $prop = new ReflectionProperty(GovApiService::class, 'memoryCache');
        $prop->setValue(null, [GovApiService::ZONE_METROPOLE => $holidays]);
    }

    #[Test]
    public function test_getNonWorkingDays_excludeLesAnneesHorsPlage(): void
    {
        // Plage retenue : [année_courante - 4 ; année_courante + 1] (l'année suivante est incluse pour couvrir
        // le dépassement décembre → janvier lors d'un import d'absences).
        $currentYear = (int) date('Y');
        $minYear     = $currentYear - 4;
        $maxYear     = $currentYear + 1;

        // Dates au format d/m/Y (comme en production après normalisation).
        $tooOld    = '01/01/' . ($minYear - 1); // hors plage (trop ancien)
        $oldest    = '01/01/' . $minYear;       // borne basse incluse
        $recent    = '01/05/' . $currentYear;   // année en cours incluse
        $future    = '01/01/' . $maxYear;       // année suivante incluse
        $tooFuture = '01/01/' . ($maxYear + 1); // hors plage (trop futur)

        $this->seedHolidaysCache([
            $tooOld    => 'Jour de l\'An (hors plage)',
            $oldest    => 'Jour de l\'An',
            $recent    => 'Fête du Travail',
            $future    => 'Jour de l\'An (année suivante)',
            $tooFuture => 'Futur (hors plage)',
        ]);

        $nonWorkingDays = (new Config())->getNonWorkingDays();

        $this->assertContains($oldest, $nonWorkingDays);
        $this->assertContains($recent, $nonWorkingDays);
        $this->assertContains($future, $nonWorkingDays);
        $this->assertNotContains($tooOld, $nonWorkingDays);
        $this->assertNotContains($tooFuture, $nonWorkingDays);
    }

    #[Test]
    public function test_getNonWorkingDays_retourneUnTableauPlatDeDates(): void
    {
        $currentYear = (int) date('Y');

        $this->seedHolidaysCache([
            '01/01/' . $currentYear => 'Jour de l\'An',
            '01/05/' . $currentYear => 'Fête du Travail',
        ]);

        $nonWorkingDays = (new Config())->getNonWorkingDays();

        // Tableau plat réindexé (array_values) de dates, sans les libellés.
        $this->assertSame([0, 1], array_keys($nonWorkingDays));
        $this->assertContains('01/01/' . $currentYear, $nonWorkingDays);
    }

    #[Test]
    public function test_getNonWorkingDays_listeVide_retourneTableauVide(): void
    {
        $this->seedHolidaysCache([]);

        $this->assertSame([], (new Config())->getNonWorkingDays());
    }
}
