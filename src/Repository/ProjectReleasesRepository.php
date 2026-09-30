<?php

namespace App\Repository;

use App\Service\JiraService;
use App\Service\TeamConfigRepository;

/**
 * ProjectReleasesRepository
 *
 * Récupère et formate la liste des issues de type "Release" d'un projet Jira
 * pour alimenter la dropdown Releases du formulaire de sélection.
 */
class ProjectReleasesRepository
{
    private JiraService $jira;
    private TeamConfigRepository $teamConfig;

    public function __construct()
    {
        $this->jira       = new JiraService();
        $this->teamConfig = new TeamConfigRepository();
    }

    /**
     * Retourne la liste des Releases d'un projet, formatées pour la dropdown.
     *
     * Si un projet parent est configuré (parentProjectKey via /config), ses Releases
     * — filtrées sur le champ Équipe (releaseTeamName) — sont fusionnées à celles du
     * projet, dédupliquées par clé Jira, à l'identique de l'onglet Releases de /team-metrics.
     * La liste est triée par date de dernière mise à jour décroissante (la plus récente en tête).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<int, array{id: string, key: string, name: string, status: string, label: string}>
     */
    public function getProjectReleasesList(string $projectKey): array
    {
        $releases = $this->jira->getReleasesByProject($projectKey);

        $parentProjectKey = $this->teamConfig->getParentProjectKey($projectKey);
        if ($parentProjectKey !== '' && $parentProjectKey !== $projectKey) {
            try {
                $parentReleases = $this->jira->getReleasesByProject($parentProjectKey, $this->teamConfig->getReleaseTeamName($projectKey));
                // Déduplique par clé Jira (impossible d'avoir un doublon inter-projets, mais défensif).
                $seen = array_flip(array_column($releases, 'key'));
                foreach ($parentReleases as $r) {
                    if (!isset($seen[$r['key']])) {
                        $releases[] = $r;
                    }
                }
            } catch (\Throwable $e) {
                error_log('ProjectReleasesRepository: releases projet parent inaccessibles : ' . $e->getMessage());
            }
        }

        // Tri par date de dernière modification décroissante (les timestamps Jira d'une même
        // instance partagent le format/fuseau : la comparaison lexicographique suffit).
        usort($releases, static fn (array $a, array $b): int => strcmp(
            (string) ($b['fields']['updated'] ?? ''),
            (string) ($a['fields']['updated'] ?? '')
        ));

        return array_map(static function (array $r): array {
            $key    = $r['key'] ?? '';
            $name   = $r['fields']['summary'] ?? '';
            $status = $r['fields']['status']['name'] ?? '';

            return [
                'id'     => (string) ($r['id'] ?? ''),
                'key'    => $key,
                'name'   => $name,
                'status' => $status,
                'label'  => $key . ' – ' . $name . ($status !== '' ? ' (' . $status . ')' : ''),
            ];
        }, $releases);
    }
}
