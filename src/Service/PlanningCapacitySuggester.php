<?php

namespace App\Service;

/**
 * PlanningCapacitySuggester
 *
 * Calcule la capacité en points suggérée pour le prochain sprint à venir. Cette valeur pré-remplit le
 * champ « Capacité totale brute » de la page /planification, que l'utilisateur devait jusqu'ici saisir
 * de tête à chaque Sprint Planning.
 *
 * Règle métier : suggestion = capaTotal(prochain sprint) × avgVelocity, arrondie au 0,5 le plus proche.
 *  - capaTotal   : total jours/homme de la grille de l'onglet Capacité (sprints.<id>.capacity.totals.capaTotal) ;
 *  - avgVelocity : « Points terminés par jours/homme » mémorisé lors de la dernière analyse d'équipe.
 *
 * Prochain sprint = sprint à venir dont la date de début est la plus proche dans le futur (aujourd'hui
 * inclus). Les sprints IGNORÉS restent éligibles : un sprint peut être exclu des métriques d'équipe tout
 * en étant la cible réelle de la planification.
 *
 * Ne fait aucun appel Jira : la liste des sprints, déjà normalisée, lui est fournie par l'appelant
 * (\App\Repository\BoardRepository::getBoardSprints()).
 */
final class PlanningCapacitySuggester
{
    /** État Agile d'un sprint créé mais non démarré. */
    private const STATE_FUTURE = 'future';

    private TeamConfigRepository $teamConfig;

    /**
     * @param TeamConfigRepository|null $teamConfig Injectable pour les tests ; null = instance par défaut.
     */
    public function __construct(?TeamConfigRepository $teamConfig = null)
    {
        $this->teamConfig = $teamConfig ?? new TeamConfigRepository();
    }

    /**
     * Retourne le prochain sprint détecté et sa capacité suggérée.
     *
     * `capaTotal`, `avgVelocity` et `suggestedCapacity` peuvent être null INDIVIDUELLEMENT : le sprint est
     * alors renvoyé quand même, pour que l'IHM puisse dire ce qui manque (grille de capacité non saisie
     * vs. aucune analyse d'équipe encore lancée) et que le CTA « Ajouter au prochain sprint » reste
     * opérant. Le retour global n'est null que si aucun sprint à venir daté n'existe sur le board.
     *
     * @param  string $projectKey   Clé du projet Jira (ex: "GCS")
     * @param  array<int, array{id?:string, name?:string, state?:string, startDate?:?string}> $boardSprints Sprints normalisés du board
     * @return array{sprintId:string, sprintName:string, capaTotal:float|null, avgVelocity:float|null, suggestedCapacity:float|null}|null
     */
    public function suggest(string $projectKey, array $boardSprints): ?array
    {
        $sprint = $this->findNextSprint($boardSprints);
        if ($sprint === null) {
            return null;
        }

        $sprintId    = (string) ($sprint['id'] ?? '');
        $capaTotal   = $this->teamConfig->getCapacityManDays($projectKey, $sprintId);
        $avgVelocity = $this->teamConfig->getAvgVelocity($projectKey);

        return [
            'sprintId'          => $sprintId,
            'sprintName'        => (string) ($sprint['name'] ?? ''),
            'capaTotal'         => $capaTotal,
            'avgVelocity'       => $avgVelocity,
            'suggestedCapacity' => ($capaTotal !== null && $avgVelocity !== null)
                ? $this->roundToHalf($capaTotal * $avgVelocity)
                : null,
        ];
    }

    /**
     * Retient le sprint à venir dont la date de début est la plus proche, aujourd'hui inclus.
     *
     * Un sprint sans date de début est écarté : impossible de le situer dans le temps. Un sprint « à venir »
     * dont la date est déjà passée l'est aussi — c'est le cas des sprints fictifs (« prio », « PI » de
     * regroupement), qui ne sont pas des itérations réelles.
     *
     * Les dates sont déjà en date locale `Y-m-d` (\App\Repository\BoardRepository::mapSprint()), donc
     * comparables comme des chaînes.
     *
     * @param  array<int, array{id?:string, name?:string, state?:string, startDate?:?string}> $boardSprints
     * @return array{id?:string, name?:string, state?:string, startDate?:?string}|null
     */
    private function findNextSprint(array $boardSprints): ?array
    {
        $today     = (new \DateTimeImmutable())->format('Y-m-d');
        $next      = null;
        $nextStart = '';

        foreach ($boardSprints as $sprint) {
            if (($sprint['state'] ?? '') !== self::STATE_FUTURE) {
                continue;
            }

            $startDate = (string) ($sprint['startDate'] ?? '');
            if ($startDate === '' || $startDate < $today) {
                continue;
            }

            if ($next === null || $startDate < $nextStart) {
                $next      = $sprint;
                $nextStart = $startDate;
            }
        }

        return $next;
    }

    /**
     * Arrondit au 0,5 le plus proche, un demi exact étant tranché vers le BAS : 9.24 → 9 ; 9.25 → 9 ;
     * 9.26 → 9.5 ; 9.45 → 9.5.
     *
     * `round()` trancherait un demi exact vers le haut (9.25 → 9.5), ce qui gonflerait la capacité
     * suggérée : sur un engagement de sprint, on préfère systématiquement la borne prudente.
     */
    private function roundToHalf(float $value): float
    {
        return ceil($value * 2 - 0.5) / 2;
    }
}
