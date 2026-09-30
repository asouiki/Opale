<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Calculateur des agrégats d'en-tête du « PI en cours » — collaborateur extrait d'AbstractPiEntities
 * (lot A4 du chantier V2).
 *
 * À partir des lignes calculées (une par entité) et de la fenêtre temporelle, produit les compteurs
 * (créées / terminées / terminées hors fenêtre / total) et les moyennes-médianes (lead, cycle,
 * waiting, réalisation, efficacité). Sans état : reçoit les lignes, retourne le tableau de stats.
 *
 * @phpstan-import-type PiRow from AbstractPiEntities
 */
final class PiStatsCalculator
{
    /**
     * Calcule les compteurs et les moyennes/médianes sur l'ensemble des lignes listées.
     *
     * @param  list<PiRow> $items    Lignes calculées (une par entité de la fenêtre)
     * @param  string      $fromDate Borne basse de la fenêtre (Y-m-d, incluse)
     * @param  string      $toDate   Borne haute de la fenêtre (Y-m-d, incluse)
     * @return array<string, mixed>
     */
    public function build(array $items, string $fromDate, string $toDate): array
    {
        $lead = $cycle = $waiting = $waitingTotals = [];
        $realDays = $realPct = $effDays = $effPct = [];
        $nbCreated = $nbDone = $nbDoneOutOfWindow = $total = 0;

        foreach ($items as $item) {
            if ($this->isInWindow($item['startDate'], $fromDate, $toDate)) {
                $nbCreated++;
            }
            // « Terminées » = tous les items terminés présents dans la liste (peu importe la date de
            // clôture : un item démarré dans le PI mais clôturé après reste comptabilisé comme terminé).
            if ($item['isDone']) {
                $nbDone++;
                // Sous-ensemble : terminés dont la clôture tombe hors de la fenêtre sélectionnée.
                if (!$this->isInWindow($item['closeDate'], $fromDate, $toDate)) {
                    $nbDoneOutOfWindow++;
                }
            }

            $this->collect($lead, $item['leadTime']);
            $this->collect($cycle, $item['cycleTime']);
            $this->collect($waiting, $item['waitingAvg']);
            $this->collect($waitingTotals, $item['waitingTotal']);
            $this->collect($realDays, $item['realisationDays']);
            $this->collect($realPct, $item['realisationPct']);
            $this->collect($effDays, $item['efficaciteDays']);
            $this->collect($effPct, $item['efficacitePct']);
            $total++;
        }

        $realDaysStats = $this->avgMedian($realDays);
        $realPctStats  = $this->avgMedian($realPct);
        $effDaysStats  = $this->avgMedian($effDays);
        $effPctStats   = $this->avgMedian($effPct);

        return [
            'nbCreated'         => $nbCreated,
            'nbDone'            => $nbDone,
            'nbDoneOutOfWindow' => $nbDoneOutOfWindow,
            'total'             => $total,
            'lead'      => $this->avgMedian($lead),
            'cycle'     => $this->avgMedian($cycle),
            // Waiting : moyenne/médiane des moyennes par ticket + le plus gros total cumulé observé.
            'waiting'   => $this->avgMedian($waiting) + ['maxTotal' => $waitingTotals !== [] ? max($waitingTotals) : null],
            // Part de réalisation réelle = (Cycle − Waiting) / Lead (travail effectif).
            'realisation' => [
                'avgDays' => $realDaysStats['avg'],
                'avgPct'  => $realPctStats['avg'],
            ],
            // Efficacité totale = (Lead − Total Waiting Time) / Lead.
            'efficacite' => [
                'avgDays' => $effDaysStats['avg'],
                'avgPct'  => $effPctStats['avg'],
            ],
        ];
    }

    /**
     * Indique si une date Y-m-d tombe dans la fenêtre [fromDate, toDate] (bornes incluses).
     *
     * Réplique volontairement AbstractPiEntities::isInWindow (une ligne) : garder le calculateur
     * autonome évite de le coupler à l'agrégat pour ce seul test de bornes.
     */
    private function isInWindow(?string $ymd, string $fromDate, string $toDate): bool
    {
        return $ymd !== null && $ymd >= $fromDate && $ymd <= $toDate;
    }

    /**
     * Ajoute une valeur au tableau d'agrégation si elle est exploitable (non nulle).
     *
     * @param array<int, float> $bucket
     */
    private function collect(array &$bucket, ?float $value): void
    {
        if ($value !== null) {
            $bucket[] = $value;
        }
    }

    /**
     * Retourne moyenne et médiane d'un tableau de valeurs (0.0 si vide).
     *
     * @param  array<int, float> $values
     * @return array{avg: float, median: float}
     */
    private function avgMedian(array $values): array
    {
        if ($values === []) {
            return ['avg' => 0.0, 'median' => 0.0];
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $mid   = intdiv($count, 2);
        $median = ($count % 2 === 0) ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];

        return [
            'avg'    => round(array_sum($values) / $count, 2),
            'median' => round($median, 2),
        ];
    }
}
