<?php

declare(strict_types=1);

namespace App\Model;

use App\Dto\SprintTicketDto;

/**
 * Analyseur des objectifs de sprint — collaborateur extrait de Sprint (lot A2 du chantier V2).
 *
 * Regroupe les tickets par étiquette « objectif_N » et fait correspondre chaque groupe à la
 * N-ième ligne de liste du goal du sprint (titre lisible, repli « Objectif N » sinon).
 * Sans état : reçoit les tickets et le goal, retourne les groupes prêts pour la vue/le snapshot.
 */
final class SprintObjectiveAnalyzer
{
    /** Préfixe des étiquettes métier « objectif » (objectif_1, objectif_2...). */
    public const LABEL_OBJECTIVE_PREFIX = ['objectif', 'goal'];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Regroupe les tickets par étiquette contenant « objectif / goal ».
     *
     * Le titre de chaque groupe est déduit du goal du sprint : la N-ième ligne
     * de liste du goal correspond à l'objectif N (cf. parseObjectivesFromGoal).
     * Repli sur « Objectif N » si le goal est absent ou mal structuré.
     * Tri : groupes par label (naturel), tickets par Feature parente puis statut (ordre workflow).
     *
     * @param  list<SprintTicketDto> $tickets Tickets du sprint (état à la date de référence)
     * @param  string|null           $goal    Goal brut du sprint (sprintData), null si absent
     * @return list<array{label: string, title: string, issues: list<array<string, mixed>>}>
     */
    public function buildGroups(array $tickets, ?string $goal): array
    {
        $groups     = [];
        $objectives = $this->parseObjectivesFromGoal($goal);

        foreach ($tickets as $ticket) {
            foreach ($ticket->labels as $label) {
                if (!array_any(
                    self::LABEL_OBJECTIVE_PREFIX,
                    fn (string $search) => stripos($label, $search) !== false
                )) {
                    //Si ne contient pas self::LABEL_OBJECTIVE_PREFIX dans les étiquettes Jira, on ignore
                    continue;
                }

                if (!isset($groups[$label])) {
                    $groups[$label] = [
                        'label'  => $label,
                        'title'  => $this->buildObjectiveTitle($label, $objectives),
                        'issues' => [],
                    ];
                }

                // Forme array : les groupes partent tels quels vers la vue et le snapshot JSON.
                $groups[$label]['issues'][] = $ticket->toArray();
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        $statusOrder = $this->config->getWorkflowStatusOrder('', true);

        foreach ($groups as &$group) {
            usort($group['issues'], static function (array $a, array $b) use ($statusOrder): int {
                $aKey = $a['parentKey'] ?? '';
                $bKey = $b['parentKey'] ?? '';

                if ($aKey !== $bKey) {
                    if ($aKey === '') {
                        return 1;
                    }
                    if ($bKey === '') {
                        return -1;
                    }
                    $cmp = strnatcmp($aKey, $bKey);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                $aPos = array_search($a['status'], $statusOrder, true);
                $bPos = array_search($b['status'], $statusOrder, true);
                $aPos = ($aPos === false) ? PHP_INT_MAX : $aPos;
                $bPos = ($bPos === false) ? PHP_INT_MAX : $bPos;
                return $aPos <=> $bPos;
            });
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Extrait les objectifs du goal du sprint sous forme de tableau indexé 1-base.
     *
     * Reconnaît les formats de liste courants dans les goals Jira :
     * - « * texte »  ou  « • texte »  (puces)
     * - « - texte »                   (tiret)
     * - « 1. texte » ou « 1) texte »  (numérotation)
     *
     * Les lignes vides ou sans marqueur de liste sont ignorées, ce qui permet
     * d'avoir un paragraphe d'introduction avant la liste dans le goal.
     *
     * @param  string|null        $goal Goal brut du sprint, null si absent
     * @return array<int, string>       Ex: [1 => "Mettre en prod la feature ABC", 2 => "..."]
     */
    public function parseObjectivesFromGoal(?string $goal): array
    {
        $goal = trim($goal ?? '');
        if ($goal === '') {
            return [];
        }

        $objectives = [];
        $index      = 1;

        foreach (preg_split('/\r?\n/', $goal) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(?:[*•\-]|\d+[.):])\s+(.+)$/', $line, $matches) === 1) {
                $objectives[$index++] = trim($matches[1]);
            }
        }

        return $objectives;
    }

    /**
     * Construit un titre lisible à partir d'une étiquette « objectif » / « goal » / « sprint_goal ».
     *
     * Cherche l'index N après le préfixe et retourne le N-ième objectif du goal du sprint
     * s'il existe, ou « Objectif N » comme repli.
     * Séparateur avant le numéro optionnel et libre : objectif1, objectif-1, objectif 1, objectif_1 (idem goal / sprint_goal).
     *
     * @param string             $label      Ex: "objectif_1", "goal-2", "sprint_goal 3"
     * @param array<int, string> $objectives Tableau indexé 1-base issu de parseObjectivesFromGoal()
     */
    public function buildObjectiveTitle(string $label, array $objectives = []): string
    {
        if (preg_match('/(?:objectif|goal)[_\- ]?(\d+)/i', $label, $matches) === 1) {
            $index = (int) $matches[1];
            return $objectives[$index] ?? 'Objectif ' . $index;
        }

        return ucfirst($label);
    }
}
