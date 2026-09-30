<?php

namespace Tests\Unit\Model;

use App\Model\Timeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Utils\FakeConfig;

/**
 * Test différentiel du comptage arithmétique des jours ouvrés (Timeline::countBusinessDaysInRange()).
 *
 * Ce calcul alimente TOUTES les métriques de durée de l'application (lead time, cycle time, temps par
 * statut, attentes). Il parcourait l'intervalle jour par jour ; il est désormais résolu par arithmétique
 * (semaines complètes × 5, puis reliquat, moins les fériés trouvés par dichotomie).
 *
 * La preuve exigée n'est pas « quelques cas passent » mais « le nouveau calcul donne EXACTEMENT le même
 * résultat que l'ancien, partout ». On confronte donc l'implémentation à une référence naïve — copie
 * fidèle de la boucle d'origine — sur des milliers d'intervalles, en couvrant explicitement les pièges :
 * week-ends, jours fériés en semaine ou le week-end, changements d'heure, années bissextiles, et
 * intervalles inversés.
 */
class BusinessDaysRangeTest extends TestCase
{
    /** Jours fériés de test : en semaine, le week-end, et autour des changements d'heure. */
    private const HOLIDAYS = [
        '01/01/2026', // jeudi
        '06/04/2026', // lundi de Pâques
        '01/05/2026', // vendredi
        '08/05/2026', // vendredi
        '14/05/2026', // jeudi (Ascension)
        '25/05/2026', // lundi (Pentecôte)
        '14/07/2026', // mardi
        '15/08/2026', // SAMEDI — ne doit jamais être soustrait
        '01/11/2026', // DIMANCHE — ne doit jamais être soustrait
        '11/11/2026', // mercredi
        '25/12/2026', // vendredi
        '29/03/2026', // dimanche du passage à l'heure d'été
        '25/10/2026', // dimanche du retour à l'heure d'hiver
    ];

    private Timeline $timeline;
    private ReflectionMethod $countInRange;
    private ReflectionMethod $dayNumber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timeline     = new Timeline(new FakeConfig(self::HOLIDAYS));
        $this->countInRange = new ReflectionMethod(Timeline::class, 'countBusinessDaysInRange');
        $this->dayNumber    = new ReflectionMethod(Timeline::class, 'dayNumber');
    }

    /**
     * Invoque la méthode privée sous test, en convertissant les bornes en numéros de jour — la
     * conversion fait partie du contrat testé.
     */
    private function subject(\DateTime $from, \DateTime $to): int
    {
        return (int) $this->countInRange->invoke(
            $this->timeline,
            (int) $this->dayNumber->invoke(null, $from),
            (int) $this->dayNumber->invoke(null, $to)
        );
    }

    /**
     * Référence naïve : reproduit fidèlement la boucle jour par jour d'origine.
     * Volontairement inefficace — c'est l'oracle, pas le code de production.
     */
    private function reference(\DateTime $from, \DateTime $to): int
    {
        $holidays = array_flip(self::HOLIDAYS);

        $current = (clone $from)->setTime(0, 0, 0);
        $last    = (clone $to)->setTime(0, 0, 0);

        $businessDays = 0;
        while ($current <= $last) {
            $isWeekend = ((int) $current->format('N')) >= 6;
            $isHoliday = isset($holidays[$current->format('d/m/Y')]);

            if (!$isWeekend && !$isHoliday) {
                $businessDays++;
            }

            $current->modify('+1 day');
        }

        return $businessDays;
    }

    #[Test]
    public function test_arithmetique_identiqueALaReference_surTousLesIntervallesDUnAn(): void
    {
        // Balayage exhaustif : toutes les dates de départ de 2026, toutes les amplitudes de 0 à 40 jours.
        // Couvre chaque jour de la semaine, chaque férié, et les deux changements d'heure.
        $start = new \DateTime('2026-01-01 00:00:00');

        for ($startOffset = 0; $startOffset < 365; $startOffset++) {
            $from = (clone $start)->modify("+$startOffset day");

            for ($span = 0; $span <= 40; $span++) {
                $to = (clone $from)->modify("+$span day");

                $this->assertSame(
                    $this->reference($from, $to),
                    $this->subject($from, $to),
                    sprintf('Écart du %s au %s', $from->format('d/m/Y'), $to->format('d/m/Y'))
                );
            }
        }
    }

    #[Test]
    public function test_arithmetique_identiqueALaReference_surDeLonguesAmplitudes(): void
    {
        // Le cas qui motivait l'optimisation : des tickets vieux de plusieurs mois à plusieurs années.
        $from = new \DateTime('2026-02-17 00:00:00');

        foreach ([60, 90, 180, 365, 400, 730, 1095] as $span) {
            $to = (clone $from)->modify("+$span day");

            $this->assertSame(
                $this->reference($from, $to),
                $this->subject($from, $to),
                "Écart sur une amplitude de $span jours"
            );
        }
    }

    #[Test]
    public function test_bornesInversees_retournentZero(): void
    {
        $from = new \DateTime('2026-05-20 00:00:00');
        $to   = new \DateTime('2026-05-19 00:00:00');

        $this->assertSame(0, $this->subject($from, $to));
    }

    #[Test]
    public function test_memeJourOuvre_retourneUn(): void
    {
        $day = new \DateTime('2026-05-20 14:00:00'); // mercredi

        $this->assertSame(1, $this->subject($day, clone $day));
    }

    #[Test]
    public function test_memeJourFerie_retourneZero(): void
    {
        $day = new \DateTime('2026-05-01 14:00:00'); // vendredi férié

        $this->assertSame(0, $this->subject($day, clone $day));
    }

    #[Test]
    public function test_ferieTombantUnWeekEnd_nEstPasSoustraitDeuxFois(): void
    {
        // 15/08/2026 est un samedi : déjà exclu comme week-end. Le soustraire en tant que férié
        // retirerait un jour ouvré inexistant et fausserait toutes les durées enjambant août.
        $from = new \DateTime('2026-08-10 00:00:00'); // lundi
        $to   = new \DateTime('2026-08-21 00:00:00'); // vendredi

        $this->assertSame($this->reference($from, $to), $this->subject($from, $to));
        $this->assertSame(10, $this->subject($from, $to)); // deux semaines pleines, aucun férié en semaine
    }

    #[Test]
    public function test_anneeBissextile_traverseLe29Fevrier(): void
    {
        $from = new \DateTime('2024-02-26 00:00:00');
        $to   = new \DateTime('2024-03-04 00:00:00');

        $this->assertSame($this->reference($from, $to), $this->subject($from, $to));
    }
}
