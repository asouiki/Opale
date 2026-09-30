<?php

namespace App\Service;

/**
 * DayEventsPropagator
 *
 * Reporte le modèle d'événements d'un sprint (colonne « Évén. » de /capacity : planif, démo, rétro…) sur d'autres sprints,
 * puis recalcule leurs totaux (\App\Service\CapacityCalculator) pour que les jours/homme restent justes sans rouvrir chaque
 * sprint dans l'IHM.
 *
 * Règles métier :
 * - Correspondance par position : le n-ième jour lun→ven du sprint source est reporté sur le n-ième jour lun→ven du sprint
 *   cible (les jours fériés comptent comme des positions, pour que « dernier jour du sprint » reste le dernier jour).
 * - Seuls les sprints de même durée (même nombre de jours lun→ven) sont modifiés ; les autres sont ignorés et signalés.
 * - Un événement qui tombe sur un jour férié du sprint cible est ignoré (le jour vaut déjà 0).
 * - Les événements déjà saisis sur le sprint cible sont entièrement remplacés par le modèle.
 * Le choix des sprints cibles (suivants non clôturés) revient à l'IHM, seule à connaître l'état Jira des sprints.
 */
class DayEventsPropagator
{
    /**
     * @param list<string> $holidays Jours fériés (Y-m-d)
     */
    public function __construct(
        private readonly TeamConfigRepository $repository,
        private readonly array $holidays,
    ) {
    }

    /**
     * Applique le modèle d'événements du sprint source à chaque sprint cible, recalcule ses totaux et persiste sa grille.
     *
     * @param array<string, float>                                         $sourceEvents Événements du sprint source (Y-m-d → 0.5|1)
     * @param list<array{id: string, startDate: string, endDate: string}> $targets      Sprints cibles (dates Y-m-d)
     *
     * @return array{applied: list<string>, skipped: list<string>} Ids des sprints modifiés / ignorés (durée différente)
     *
     * @throws \RuntimeException Si l'écriture de la configuration échoue
     */
    public function propagate(string $projectKey, array $sourceEvents, string $sourceStart, string $sourceEnd, array $targets): array
    {
        $roster     = array_values($this->repository->getTeamMembers($projectKey));
        $calculator = new CapacityCalculator();
        $applied    = [];
        $skipped    = [];

        foreach ($targets as $target) {
            $events = $this->project($sourceEvents, $sourceStart, $sourceEnd, $target['startDate'], $target['endDate']);
            if ($events === null) {
                $skipped[] = $target['id'];
                continue;
            }

            $capacity = $this->repository->getSprintCapacity($projectKey, $target['id']);
            unset($capacity['dayEvents']);
            if ($events !== []) {
                $capacity['dayEvents'] = $events;
            }
            $capacity['totals'] = $calculator->computeTotals($roster, $capacity, $target['startDate'], $target['endDate'], $this->holidays);

            $this->repository->setSprintCapacity($projectKey, $target['id'], $capacity);
            $applied[] = $target['id'];
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Projette le modèle d'événements du sprint source sur la fenêtre d'un sprint cible, position par position.
     * Retourne null si les deux sprints n'ont pas le même nombre de jours lun→ven (report impossible sans ambiguïté).
     *
     * @param  array<string, float>      $sourceEvents Événements du sprint source (Y-m-d → valeur)
     * @return array<string, float>|null Événements du sprint cible (Y-m-d → valeur), hors jours fériés
     */
    public function project(array $sourceEvents, string $sourceStart, string $sourceEnd, string $targetStart, string $targetEnd): ?array
    {
        $sourceDays = $this->weekdays($sourceStart, $sourceEnd);
        $targetDays = $this->weekdays($targetStart, $targetEnd);
        if ($sourceDays === [] || count($sourceDays) !== count($targetDays)) {
            return null;
        }

        $holidaySet = array_flip($this->holidays);
        $events     = [];
        foreach ($sourceDays as $position => $sourceDay) {
            $value     = $sourceEvents[$sourceDay] ?? 0.0;
            $targetDay = $targetDays[$position];
            if ($value > 0 && !isset($holidaySet[$targetDay])) {
                $events[$targetDay] = $value;
            }
        }

        return $events;
    }

    /**
     * Jours lun→ven de la fenêtre d'un sprint, fériés compris (ils occupent une position dans le modèle).
     *
     * @return list<string> Dates Y-m-d
     */
    private function weekdays(string $startDate, string $endDate): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($start === false || $end === false) {
            return [];
        }

        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') < 6) {
                $days[] = $d->format('Y-m-d');
            }
        }

        return $days;
    }
}
