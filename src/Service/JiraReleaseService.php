<?php

namespace App\Service;

use Exception;

/**
 * JiraReleaseService
 *
 * Résolution et mise en cache de l'EXISTENCE des issues Jira de type "Release"
 * pour les projets surveillés (cf. watched_projects.json).
 *
 * But du cache : économiser les appels API search lors de la résolution
 * d'une saisie texte (Key ABC-123 ou nom exact) en input du formulaire.
 * Le cache stocke uniquement les métadonnées (id, key, summary, project, status)
 * — pas le contenu (issues enfants, changelog), qui reste fetch live au process.
 *
 * Cache local : cache/jira_releases.json — TTL 4 heures, refresh forçable.
 *
 * Inspiré de [[JiraVersionService]] (même pattern).
 */
class JiraReleaseService extends AbstractJiraCacheService
{
    private const CACHE_FILE = __DIR__ . '/../../cache/jira_releases.json';

    /**
     * Structure du cache :
     * {
     *   "updatedAt": 1234567890,
     *   "byKey":  { "ABC-123": { ...release normalisée... } },
     *   "byName": { "release 4.6": [ { ... } ] }  // tableau pour gérer doublons inter-projets
     * }
     *
     * Entrée normalisée :
     * { id, key, name, projectId, projectKey, released, description }
     *
     * @var array{updatedAt: int, byKey: array<string, array>, byName: array<string, array[]>}
     */
    protected array $cache = [
        'updatedAt' => 0,
        'byKey'     => [],
        'byName'    => [],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->loadCache();
    }

    // =========================================================================
    // API publique
    // =========================================================================

    /**
     * Résolution par Key (ex: ABC-123). Retourne null si non trouvé.
     *
     * Le cache ne couvre que les watched_projects. Un projectHint permet de résoudre une Key issue
     * d'un projet hors de cette liste (ex: Release d'un projet parent fusionnée dans un onglet) : en
     * dernier recours, les Releases de ce projet sont récupérées à la volée puis indexées.
     *
     * @param  string $key         Key Jira (ex: "ABC-123")
     * @param  bool   $refresh     Force le rechargement depuis Jira avant le lookup
     * @param  string $projectHint Clé du projet porteur, utilisée en repli si la Key est absente du cache
     * @return array|null          Release normalisée, ou null si introuvable
     */
    public function findByKey(string $key, bool $refresh = false, string $projectHint = ''): ?array
    {
        $normalized = strtoupper(trim($key));

        if ($refresh || $this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
        }

        $hit = $this->cache['byKey'][$normalized] ?? null;

        // Miss + cache pas encore rechargé : on tente un fetch unique
        if ($hit === null && !$refresh && !$this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
            $hit = $this->cache['byKey'][$normalized] ?? null;
        }

        // Toujours absent : repli sur le projet indiqué (hors watched_projects), sans écraser le cache.
        if ($hit === null && $projectHint !== '') {
            $hintKey = strtoupper(trim($projectHint));
            $this->fetchReleasesForProject($hintKey);
            $this->saveCache();
            $hit = $this->cache['byKey'][$normalized] ?? null;

            // La Release existe dans un projet non surveillé : on l'adopte pour éviter le repli la
            // prochaine fois (résolution par nom, versions/features). Un échec d'écriture ne doit pas
            // casser l'analyse déjà résolue.
            if ($hit !== null) {
                try {
                    $this->addWatchedProject($hintKey);
                } catch (\RuntimeException $e) {
                    error_log('JiraReleaseService: adoption du projet ' . $hintKey . ' impossible : ' . $e->getMessage());
                }
            }
        }

        return $hit;
    }

    /**
     * Résolution par nom exact (case-insensitive).
     *
     * Permet à l'appelant de distinguer :
     * - 0 résultat → Release introuvable
     * - 1 résultat → cas nominal
     * - N résultats → ambiguïté inter-projets
     *
     * @param  string $name    Summary exact d'une Release
     * @param  bool   $refresh Force le rechargement depuis Jira avant le lookup
     * @return array[]         Tableau de Releases normalisées (peut être vide)
     */
    public function findAllByName(string $name, bool $refresh = false): array
    {
        $key = strtolower(trim($name));

        if ($refresh || $this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
        }

        $matches = $this->cache['byName'][$key] ?? [];

        if (empty($matches) && !$refresh && !$this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
            $matches = $this->cache['byName'][$key] ?? [];
        }

        return $matches;
    }

    // =========================================================================
    // Fetch & construction du cache
    // =========================================================================

    private function fetchWatchedProjects(): void
    {
        $projects = $this->loadWatchedProjects();

        $this->cache = [
            'updatedAt' => time(),
            'byKey'     => [],
            'byName'    => [],
        ];

        foreach ($projects as $projectKey) {
            $this->fetchReleasesForProject($projectKey);
        }
    }

    private function fetchReleasesForProject(string $projectKey): void
    {
        try {
            $releases = $this->jira->getReleasesByProject($projectKey);
        } catch (Exception $e) {
            error_log("JiraReleaseService: projet $projectKey inaccessible : " . $e->getMessage());
            return;
        }

        foreach ($releases as $rawRelease) {
            $this->storeInCache($rawRelease);
        }
    }

    /**
     * Normalise l'issue brute et l'indexe par Key et par nom (minuscules).
     *
     * @param array $rawRelease Issue Jira brute (champs : id, key, fields.summary, fields.project, fields.status)
     */
    private function storeInCache(array $rawRelease): void
    {
        $id      = (string) ($rawRelease['id'] ?? '');
        $key     = trim((string) ($rawRelease['key'] ?? ''));
        $name    = trim((string) ($rawRelease['fields']['summary'] ?? ''));

        if ($id === '' || $key === '' || $name === '') {
            return;
        }

        $statusCategoryKey = $rawRelease['fields']['status']['statusCategory']['key'] ?? '';

        $entry = [
            'id'          => $id,
            'key'         => $key,
            'name'        => $name,
            'projectId'   => (string) ($rawRelease['fields']['project']['id'] ?? ''),
            'projectKey'  => (string) ($rawRelease['fields']['project']['key'] ?? ''),
            'released'    => $statusCategoryKey === 'done',
            'description' => null,
        ];

        $this->cache['byKey'][strtoupper($key)] = $entry;
        $this->cache['byName'][strtolower($name)][] = $entry;
    }

    // =========================================================================
    // Persistance du cache (load/save/expiration : cf. AbstractJiraCacheService)
    // =========================================================================

    protected function cacheFilePath(): string
    {
        return self::CACHE_FILE;
    }
}
