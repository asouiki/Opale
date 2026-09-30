<?php

namespace App\Repository;

use App\Service\JiraService;

/**
 * Récupère et trie la liste des versions Jira d'un projet pour alimenter la
 * dropdown de sélection (formulaire Version / PI Releases).
 */
class ProjectVersionsRepository
{
    public const VERSION_STATUS_UNRELEASED = '🛠️Non livré';
    public const VERSION_STATUS_RELEASED = '🚀Livré';
    public const VERSION_STATUS_ARCHIVED = '📦Archivé';

    private JiraService $jira;

    public function __construct()
    {
        $this->jira = new JiraService();
    }

    /**
     * Retourne la liste des versions d'un projet, triée et normalisée.
     */
    public function getProjectVersionsList(string $projectKey): array
    {
        $versions = $this->jira->getVersionsByProject($projectKey);

        return $this->sortVersionsList($versions);
    }

    /**
     * Normalise et trie les versions : Non livré en tête, puis Livré, puis Archivé.
     * À statut égal, les versions sont triées par date de mise à jour décroissante.
     */
    protected function sortVersionsList(array $versions): array
    {
        $mapped = array_map(static function ($v) {
            if (!empty($v['archived'])) {
                $status = self::VERSION_STATUS_ARCHIVED;
            } elseif (!empty($v['released'])) {
                $status = self::VERSION_STATUS_RELEASED;
            } else {
                $status = self::VERSION_STATUS_UNRELEASED;
            }

            return [
                'id'      => $v['id'],
                'key'     => $v['key'] ?? null,
                'name'    => $v['name'],
                'status'  => $status,
                'updated' => $v['releaseDate'] ?? $v['startDate'] ?? $v['userReleaseDate'] ?? '1970-01-01',
                'label'   => $v['name'] . ' (' . $status . ')'
            ];
        }, $versions);



        $statusWeight = [
            self::VERSION_STATUS_UNRELEASED => 0,
            self::VERSION_STATUS_RELEASED   => 1,
            self::VERSION_STATUS_ARCHIVED   => 2,
        ];

        usort($mapped, static function ($a, $b) use ($statusWeight) {
            // 1. Statut
            if ($statusWeight[$a['status']] !== $statusWeight[$b['status']]) {
                return $statusWeight[$a['status']] <=> $statusWeight[$b['status']];
            }

            // 2. Date de mise à jour décroissante
            return strcmp($b['updated'], $a['updated']);
        });

        return $mapped;
    }
}
