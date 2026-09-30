<?php

namespace App\Service;

/**
 * CapacityCalculator
 *
 * Recalcule côté serveur les totaux d'un sprint (jours de présence, capa à faire par rôle et totale),
 * miroir exact de la logique JS de la page Capacité (views/components/capacity/capacityGrid.phtml).
 * Utilisé après un import d'absences pour que les totaux stockés — réutilisés par l'agrégation PI — restent
 * justes sans devoir rouvrir chaque sprint dans l'IHM.
 *
 * Règles répliquées :
 * - Jours ouvrés = lundi→vendredi de la fenêtre du sprint, hors fériés et hors période du membre (tenure).
 * - Présence quotidienne : valeur saisie (défaut 1), bornée par la grille.
 * - Événements du jour (planif, démo…) plafonnés par la présence : eventsOf = Σ min(event, présence).
 * - Capa d'un membre = max(0, (jours présence − events) × ratio/100). PO/SM comptent en présence mais pas en capa.
 *
 *
 * Règles métiers des dates d'entrée/sortie :
 *
 * Les dates sont toujours _incluses_. Date d'entrée le 29 : membre présent dès le 29 inclus. Date de sortie le 29 : membre présent jusqu'au 29 inclus.
 *
 * Cas 1 — ni entrée ni sortie : toujours présent.
 * inTenure = true toujours. overlapsWindow = true toujours.
 *
 * Cas 2 — entrée seule : présent à partir de la date d'entrée (incluse).
 * N'apparait pas dans les sprints < date d'entrée. Sprint d'entrée : jours précédents = 0, lecture seule.
 * inTenure(d) = d ≥ entry. overlapsWindow = false ssi end < entry (sprint entièrement avant l'entrée → retiré). (inchangé par rapport à l'existant)
 *
 * Cas 3 — sortie seule : présent jusqu'à la date de sortie incluse.
 * N'apparait pas dans les sprints > date de sortie. Sprint de sortie : jours suivants = 0, lecture seule.
 * inTenure(d) = d ≤ exit. overlapsWindow = false ssi start > exit (sprint entièrement après la sortie → retiré).
 *
 * Cas 4 — entrée ET sortie, entry ≤ exit (fenêtre normale) : présent sur [entry, exit] inclus des deux côtés.
 * N'apparait pas dans les sprints < date d'entrée ni dans les sprints > date de sortie.
 * Sprint d'entrée : jours précédents = 0, lecture seule.
 * Sprint de sortie : jours suivants = 0, lecture seule.
 * inTenure(d) = d ≥ entry ET d ≤ exit. overlapsWindow = false ssi end < entry OU start > exit (sprints 100% hors fenêtre → retirés).
 *
 * Cas 5 — entrée ET sortie, entry > exit (sortie temporaire puis retour) : présent dans toutes les grilles de sprint sans exception, absent uniquement strictement entre les deux dates.
 * Sprints entre les deux dates : présent dans la grille, mais présence = 0, lecture seule.
 * Sprint d'entrée : jours précédents = 0, lecture seule.
 * Sprint de sortie : jours suivants = 0, lecture seule.
 * inTenure(d) = d ≤ exit OU d ≥ entry (absent ssi exit < d < entry, bornes exclues côté absence — cohérent avec le fait que le jour de sortie et le jour d'entrée sont chacun un jour présent).
 * overlapsWindow = toujours true, jamais masqué.
 *
 */
class CapacityCalculator
{
    /** Rôles porteurs d'une capacité à faire (PO/SM comptent en présence mais pas en capa). */
    private const CAPA_ROLES = ['Dev', 'BA', 'QA'];

    /**
     * Calcule les totaux d'un sprint à partir du roster, de la grille de capacité et de la fenêtre du sprint.
     *
     * @param list<array{id: string, role?: string, entryDate?: string, exitDate?: string}>                        $members   Roster du projet
     * @param array{members?: array<string, array{presence?: array<string, float>, ratio?: float}>, dayEvents?: array<string, float>} $capacity  Grille stockée du sprint
     * @param string                                                                                               $startDate Début du sprint (Y-m-d)
     * @param string                                                                                               $endDate   Fin du sprint (Y-m-d)
     * @param list<string>                                                                                         $holidays  Jours fériés (Y-m-d)
     *
     * @return array{presenceTotal: float, capa: array<string, float>, capaTotal: float}
     */
    public function computeTotals(array $members, array $capacity, string $startDate, string $endDate, array $holidays): array
    {
        $weekdays     = $this->weekdays($startDate, $endDate, array_flip($holidays));
        $presenceGrid = $capacity['members'] ?? [];
        $dayEvents    = $capacity['dayEvents'] ?? [];

        $presenceTotal = 0.0;
        $capaByRole    = array_fill_keys(self::CAPA_ROLES, 0.0);

        foreach ($members as $member) {
            if (!$this->overlapsWindow($member, $startDate, $endDate)) {
                continue;
            }
            $memberId = $member['id'];
            $entry    = $presenceGrid[$memberId] ?? [];
            $presence = $entry['presence'] ?? [];
            $ratio    = $entry['ratio'] ?? 100.0;

            $sumPresence = 0.0;
            $sumEvents   = 0.0;
            foreach ($weekdays as $date) {
                if (!$this->inTenure($member, $date)) {
                    continue;
                }
                $value = $presence[$date] ?? 1.0;
                $sumPresence += $value;
                $sumEvents   += min($dayEvents[$date] ?? 0.0, $value);
            }

            $joursPresence = round($sumPresence, 2);
            $presenceTotal += $joursPresence;

            $role = $member['role'] ?? '';
            if (in_array($role, self::CAPA_ROLES, true)) {
                $capa = max(0.0, round(($joursPresence - round($sumEvents, 2)) * $ratio / 100, 2));
                $capaByRole[$role] += $capa;
            }
        }

        $capa = [];
        foreach (self::CAPA_ROLES as $role) {
            $capa[$role] = round($capaByRole[$role], 2);
        }

        return [
            'presenceTotal' => round($presenceTotal, 2),
            'capa'          => $capa,
            'capaTotal'     => round($capa['Dev'] + $capa['BA'] + $capa['QA'], 2),
        ];
    }

    /**
     * Liste les jours ouvrés (lundi→vendredi) de la fenêtre, hors fériés.
     *
     * @param  array<string, int> $holidaySet Ensemble des fériés (date => _) pour test O(1)
     * @return list<string>       Dates `Y-m-d` des jours ouvrés non fériés
     */
    private function weekdays(string $startDate, string $endDate, array $holidaySet): array
    {
        $days  = [];
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($start === false || $end === false) {
            return [];
        }

        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') >= 6) {
                continue; // week-end
            }
            $ymd = $d->format('Y-m-d');
            if (!isset($holidaySet[$ymd])) {
                $days[] = $ymd;
            }
        }

        return $days;
    }

    /**
     * Le membre recoupe-t-il la fenêtre [start, end] du sprint ? Présence sur [entryDate, exitDate] :
     * les deux bornes sont incluses (présent le jour même de l'entrée ET le jour même de la sortie). Si les
     * deux dates sont renseignées et exitDate < entryDate (sortie temporaire puis retour), le membre est
     * toujours affiché, dans la grille de TOUS les sprints (jamais masqué) — seule inTenure() neutralise
     * les jours d'absence.
     *
     * @param array{entryDate?: string, exitDate?: string} $member
     */
    private function overlapsWindow(array $member, string $start, string $end): bool
    {
        $entry = $member['entryDate'] ?? '';
        $exit  = $member['exitDate'] ?? '';
        if ($entry !== '' && $exit !== '' && $exit < $entry) {
            return true; // sortie temporaire : jamais masqué
        }
        if ($entry !== '' && $end < $entry) {
            return false; // sprint entièrement avant l'entrée
        }
        if ($exit !== '' && $start > $exit) {
            return false; // sprint entièrement après la sortie (sortie incluse)
        }

        return true;
    }

    /**
     * Le membre est-il présent le jour `date` ? Entrée et sortie incluses : présent sur [entryDate, exitDate].
     * Cas sortie temporaire puis retour (exitDate < entryDate, les deux renseignées) : présent partout sauf
     * strictement entre les deux dates (les bornes elles-mêmes restent des jours de présence).
     *
     * @param array{entryDate?: string, exitDate?: string} $member
     */
    private function inTenure(array $member, string $date): bool
    {
        $entry = $member['entryDate'] ?? '';
        $exit  = $member['exitDate'] ?? '';
        if ($entry !== '' && $exit !== '' && $exit < $entry) {
            return $date <= $exit || $date >= $entry;
        }
        if ($entry !== '' && $date < $entry) {
            return false;
        }
        if ($exit !== '' && $date > $exit) {
            return false;
        }

        return true;
    }
}
