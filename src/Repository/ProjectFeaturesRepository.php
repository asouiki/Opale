<?php

namespace App\Repository;

use App\Model\Feature;
use App\Model\JiraFields;
use App\Service\JiraService;

/**
 * ProjectFeaturesRepository
 *
 * Récupère et trie la liste des Features Jira d'un projet.
 */
class ProjectFeaturesRepository
{
    public const STATUS_LABELS = [
        'Analysing' => '🔎Analysing',
        'Prêt' => '🚦Prêt',
        'Planifié' => '🔜Planifié',
        'À faire' => '▶️À faire',
        'En cours' => '⚙️En cours',
        'Validating on staging' => '🧪Validating on staging',
        'Releasing' => '🚀Releasing',
        'Terminé(e)' => '✅Terminé(e)',
        'Non atteignable' => '📦Non Atteignable',
        'ABANDONNE' => '🗑️ABANDONNE',
    ];

    private JiraService $jira;

    public function __construct()
    {
        $this->jira = new JiraService();
    }

    /**
     * Retourne la liste des Features triées pour un projet donné.
     *
     * Tri : Planning Interval décroissant (null en dernier), puis key croissante.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<int, array{id: string, key: string, name: string, status: string, lastPI: int|null, PIs: int[], teams: string[], label: string}>
     */
    public function getProjectFeaturesList(string $projectKey): array
    {
        $features = $this->jira->getFeaturesByProject($projectKey);

        return $this->sortFeaturesList($features);
    }

    /**
     * Normalise et trie les Features.
     *
     * Algorithme :
     * 1. Mapping vers une structure normalisée avec extraction du PI
     * 2. Tri primaire : PI décroissant (null → fin de liste)
     * 3. Tri secondaire : partie numérique de la key décroissante (ABC-2 > ABC-1)
     *
     * @param array $features Issues brutes retournées par l'API Jira
     */
    protected function sortFeaturesList(array $features): array
    {
        $mapped = array_map(function (array $f): array {
            $status  = $this->getStatusLabel($f['fields']['status']['name'] ?? '');
            $name    = $f['fields']['summary'] ?? '';
            $key     = $f['key'] ?? '';
            // PI multivaleurs normalisés (gère "PI8" comme "8") ; lastPI = PI courant pour le tri
            $pis     = Feature::normalizePlanningIntervalList($f['fields'][JiraFields::PLANNING_INTERVAL] ?? null);
            $lastPI  = $pis ? max($pis) : null;
            $teams   = Feature::normalizeTeamList($f['fields'][JiraFields::TEAM] ?? null);
            $piLabel = $lastPI !== null ? '[PI' . $lastPI . '] ' : null;

            return [
                'id'     => $f['id'],
                'key'    => $key,
                'name'   => $name,
                'status' => $status,
                'lastPI' => $lastPI,
                'PIs'    => $pis,
                'teams'  => $teams,
                'label'  => $piLabel . $key . ' – ' . $name . ' (' . $status . ')',
            ];
        }, $features);

        usort($mapped, function (array $a, array $b): int {
            // PI décroissant — null toujours en dernier
            if ($a['lastPI'] !== $b['lastPI']) {
                if ($a['lastPI'] === null) {
                    return 1;
                }
                if ($b['lastPI'] === null) {
                    return -1;
                }
                return $b['lastPI'] <=> $a['lastPI'];
            }

            // À PI égal : key numérique décroissante (ABC-2, ABC-1...)
            return $this->extractKeyNumber($b['key']) <=> $this->extractKeyNumber($a['key']);
        });

        return $mapped;
    }

    public function getStatusLabel(string $status): string
    {
        if (isset(self::STATUS_LABELS[$status])) {
            return self::STATUS_LABELS[$status];
        }
        return $status;
    }

    /**
     * Extrait la partie numérique d'une key Jira pour le tri.
     * Ex : "ABC-123" → 123
     */
    private function extractKeyNumber(string $key): int
    {
        $parts = explode('-', $key);
        return (int) end($parts);
    }
}
