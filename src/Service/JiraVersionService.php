<?php

namespace App\Service;

use Exception;

/**
 * JiraVersionService
 *
 * Résolution et mise en cache des Versions Jira pour les projets surveillés.
 *
 * Responsabilités :
 * - Charger les versions des projets listés dans watched_projects.json
 * - Mettre en cache les données complètes (indexées par nom en minuscules)
 * - Résoudre un nom exact (case-insensitive) vers les données complètes
 * - Détecter les doublons inter-projets (même nom, projets différents)
 *
 * Le cache local (cache/jira_versions.json) est invalidé après TTL = 4 heures.
 * Un refresh forcé est possible via le flag booléen de findAllByName().
 *
 * Priorité : KISS — pas de double appel API.
 * GET /project/{key}/versions retourne déjà les données complètes.
 */
class JiraVersionService extends AbstractJiraCacheService
{
    private const CACHE_FILE = __DIR__ . '/../../cache/jira_versions.json';

    /**
     * Structure du cache :
     * {
     *   "updatedAt": 1234567890,
     *   "versions": {
     *     "cqfd4.6.0 tid": [
     *       { "id": "19075", "name": "...", "projectId": 11547, ... }
     *     ]
     *   }
     * }
     *
     * La clé est le nom en minuscules.
     * La valeur est un tableau (gère les doublons inter-projets).
     *
     * @var array{updatedAt: int, versions: array<string, array[]>}
     */
    protected array $cache = [
        'updatedAt' => 0,
        'versions'  => [],
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
     * Retourne toutes les versions dont le nom correspond exactement (case-insensitive).
     *
     * Permet à l'appelant de distinguer :
     * - 0 résultat → version introuvable
     * - 1 résultat → cas nominal
     * - N résultats → ambiguïté inter-projets
     *
     * Algorithme :
     * 1. Si refresh forcé ou cache expiré → fetchWatchedProjects() + saveCache()
     * 2. Lookup dans le cache (clé = strtolower(trim($name)))
     * 3. Si 0 résultat et cache non encore rechargé → fetch unique + re-lookup
     *
     * @param  string $name    Nom exact de la version (ex: "CQFD4.6.0 TID")
     * @param  bool   $refresh Force le rechargement depuis l'API Jira
     * @return array[]         Tableau de données complètes (peut être vide)
     */
    public function findAllByName(string $name, bool $refresh = false): array
    {
        $key = strtolower(trim($name));

        // Refresh explicite ou cache expiré : on recharge avant le lookup
        if ($refresh || $this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
        }

        $matches = $this->cache['versions'][$key] ?? [];

        // Introuvable et cache déjà frais : on tente un fetch unique au cas où
        // une nouvelle version aurait été créée dans Jira depuis le dernier cache
        if (empty($matches) && !$refresh && !$this->isCacheExpired()) {
            $this->fetchWatchedProjects();
            $this->saveCache();
            $matches = $this->cache['versions'][$key] ?? [];
        }

        return $matches;
    }

    // =========================================================================
    // Fetch & construction du cache
    // =========================================================================

    /**
     * Charge les versions de tous les projets surveillés et reconstruit le cache.
     *
     * Pour chaque projet dans watched_projects.json :
     *   GET /project/{key}/versions → données complètes (id, name, dates, statuts...)
     *
     * Pas de double appel par version : le payload retourné est déjà complet.
     *
     * @throws Exception Si watched_projects.json est absent ou malformé
     */
    private function fetchWatchedProjects(): void
    {
        $projects = $this->loadWatchedProjects();

        // Reset complet : on repart d'un cache vide à chaque fetch
        $this->cache = [
            'updatedAt' => time(),
            'versions'  => [],
        ];

        foreach ($projects as $projectKey) {
            $this->fetchVersionsForProject($projectKey);
        }
    }

    /**
     * Charge toutes les versions d'un projet et les stocke dans le cache.
     *
     * Les erreurs par projet sont silencieuses (error_log + continue)
     * pour ne pas bloquer le fetch des autres projets.
     *
     * @param string $projectKey Clé du projet Jira (ex: "CQFD")
     */
    private function fetchVersionsForProject(string $projectKey): void
    {
        try {
            $versions = $this->jira->getVersionsByProject($projectKey);
        } catch (Exception $e) {
            // Projet inaccessible : on logue et on continue les autres
            error_log("JiraVersionService: projet $projectKey inaccessible : " . $e->getMessage());
            return;
        }

        foreach ($versions as $versionData) {
            $this->storeInCache($versionData);
        }
    }

    /**
     * Stocke les données complètes d'une version dans le cache.
     *
     * Clé = strtolower(trim(name)) pour le matching case-insensitive.
     * Plusieurs versions avec le même nom sont regroupées dans un tableau
     * (détection de doublons inter-projets).
     *
     * @param array $versionData Données brutes retournées par GET /project/{key}/versions
     */
    private function storeInCache(array $versionData): void
    {
        if (empty($versionData['id']) || empty($versionData['name'])) {
            return;
        }

        $key = strtolower(trim($versionData['name']));

        $this->cache['versions'][$key][] = $versionData;
    }

    // =========================================================================
    // Persistance du cache (load/save/expiration : cf. AbstractJiraCacheService)
    // =========================================================================

    protected function cacheFilePath(): string
    {
        return self::CACHE_FILE;
    }
}
