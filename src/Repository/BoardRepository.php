<?php

namespace App\Repository;

use App\Service\BoardCacheService;
use App\Service\JiraDate;
use App\Service\TeamConfigRepository;

/**
 * BoardRepository
 *
 * Récupère et normalise les boards Agile d'un projet et les sprints d'un board,
 * pour alimenter le formulaire de sélection du sprint (selectSprint.phtml).
 *
 * Le rapatriement passe par BoardCacheService (TTL 12 h) ; seule la NORMALISATION reste systématique,
 * car elle dépend de la configuration d'équipe (board préféré, sprints ignorés), qui doit prendre effet
 * sans attendre l'expiration du cache.
 */
class BoardRepository
{
    private BoardCacheService $boards;

    public function __construct()
    {
        $this->boards = new BoardCacheService();
    }

    /**
     * Retourne la liste normalisée des boards d'un projet. Le board par défaut du
     * projet (config_files/teams/<KEY>_team_config.json) est marqué `preferred`
     * pour que les formulaires le présélectionnent par défaut.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  bool   $refresh    Ignore le cache et redemande la liste à Jira
     * @return array<int, array{id:int|string, name:string, type:string, label:string, preferred:bool}>
     */
    public function getProjectBoards(string $projectKey, bool $refresh = false): array
    {
        $boards           = $this->boards->getBoards($projectKey, $refresh);
        $preferredBoardId = (new TeamConfigRepository())->getPreferredBoardId($projectKey);

        return array_map(static function (array $board) use ($preferredBoardId): array {
            $name = $board['name'] ?? '';
            $type = $board['type'] ?? '';
            $id   = $board['id'] ?? '';

            return [
                'id'        => $id,
                'name'      => $name,
                'type'      => $type,
                'label'     => $name . ' (' . $type . ')',
                'preferred' => $preferredBoardId !== null && (string) $id === (string) $preferredBoardId,
            ];
        }, $boards);
    }

    /**
     * Retourne la liste normalisée des sprints d'un board, triée par date de
     * démarrage décroissante (le plus récent en tête).
     *
     * Si $projectKey est fourni, chaque sprint porte un flag `ignored` (true si
     * son id figure dans les sprints ignorés du projet), pour grisage côté IHM.
     *
     * @param  string      $boardId    ID du board Agile
     * @param  string|null $projectKey Clé du projet (pour marquer les sprints ignorés)
     * @param  bool        $refresh    Ignore le cache et redemande la liste à Jira
     * @return array<int, array{id:string, name:string, state:string, startDate:?string, endDate:?string, label:string, ignored:bool}>
     */
    public function getBoardSprints(string $boardId, ?string $projectKey = null, bool $refresh = false): array
    {
        $sprints = $this->boards->getSprints($boardId, $refresh);

        $mapped = array_map([$this, 'mapSprint'], $sprints);
        $sorted = $this->sortByStartDateDesc($mapped);

        if ($projectKey !== null && $projectKey !== '') {
            $ignoredIds = array_map(
                'strval',
                array_column((new TeamConfigRepository())->getIgnoredSprints($projectKey), 'id')
            );
            foreach ($sorted as &$sprint) {
                $sprint['ignored'] = in_array($sprint['id'], $ignoredIds, true);
            }
            unset($sprint);
        }

        return $sorted;
    }

    /**
     * Normalise un sprint brut de l'API Agile.
     *
     * `startDate`/`endDate` sont renvoyés en date locale `Y-m-d` (et non en datetime ISO/UTC brut) :
     * Jira exprime une fin à minuit local en UTC (ex. 24/06 00h00 Europe/Paris → `2024-06-23T22:00:00Z`),
     * et le front ne raisonne qu'en date (jours ouvrés de la grille). Sans cette conversion, un
     * `slice(0,10)` de l'ISO brut retomberait sur la veille et le dernier jour serait perdu.
     *
     * `completeDate` (clôture réelle, null tant que le sprint n'est pas clôturé) borne le calendrier de la photo « à date »
     * de /sprint : elle peut différer de la fin prévue `endDate` (sprint clôturé en avance ou en retard).
     *
     * @return array{id:string, name:string, state:string, startDate:?string, endDate:?string, completeDate:?string, label:string, ignored:bool}
     */
    private function mapSprint(array $sprint): array
    {
        $state = $sprint['state'] ?? '';

        return [
            'id'           => (string) ($sprint['id'] ?? ''),
            'name'         => $sprint['name'] ?? 'Sprint',
            'state'        => $state,
            'startDate'    => $this->localDate($sprint['startDate'] ?? null),
            'endDate'      => $this->localDate($sprint['endDate'] ?? null),
            'completeDate' => $this->localDate($sprint['completeDate'] ?? null),
            'label'        => $this->buildSprintLabel($sprint, $state),
            'ignored'      => false,
        ];
    }

    /**
     * Convertit une date ISO 8601 Jira (souvent UTC) en date locale `Y-m-d`, en ne conservant que le
     * jour calendaire vu par l'équipe (fuseau applicatif Europe/Paris). Renvoie null si l'entrée est
     * vide ou non parsable, pour préserver les sprints futurs sans date.
     */
    private function localDate(?string $isoDate): ?string
    {
        return JiraDate::toLocalDate($isoDate);
    }

    /**
     * Construit le libellé affiché dans la liste déroulante des sprints.
     */
    private function buildSprintLabel(array $sprint, string $state): string
    {
        $stateLabel = match ($state) {
            'active' => '🟢 En cours',
            'closed' => '⚪ Clôturé',
            'future' => '🔵 À venir',
            default  => $state,
        };

        $range = '';
        if (!empty($sprint['startDate'])) {
            $start = $this->formatDate($sprint['startDate']);
            $end   = !empty($sprint['endDate']) ? $this->formatDate($sprint['endDate']) : '?';
            $range = ' — ' . $start . ' → ' . $end;
        }

        return ($sprint['name'] ?? 'Sprint') . ' (' . $stateLabel . ')' . $range;
    }

    /**
     * Trie les sprints par date de démarrage décroissante.
     * Les sprints sans date de démarrage (futurs) sont placés en tête.
     */
    private function sortByStartDateDesc(array $sprints): array
    {
        usort($sprints, static function (array $a, array $b): int {
            // Pas de startDate (sprint futur) → considéré comme le plus récent
            $keyA = $a['startDate'] ?? '9999-12-31';
            $keyB = $b['startDate'] ?? '9999-12-31';

            return strcmp($keyB, $keyA);
        });

        return $sprints;
    }

    /**
     * Formate une date ISO 8601 au format jj/mm/aaaa (fuseau applicatif), pour le libellé du sprint.
     */
    private function formatDate(string $isoDate): string
    {
        return JiraDate::formatFr($isoDate, '?');
    }
}
