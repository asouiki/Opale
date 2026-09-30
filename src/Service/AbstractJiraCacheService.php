<?php

namespace App\Service;

use App\Model\Config;
use App\Support\JsonFile;
use Exception;

/**
 * AbstractJiraCacheService
 *
 * Base des services Jira à cache fichier (JiraVersionService, JiraReleaseService).
 * Mutualise l'accès à la configuration, la lecture des projets surveillés et la persistance du cache
 * (chargement/sauvegarde atomique/expiration TTL). Chaque sous-classe fournit son chemin de fichier
 * ({@see cacheFilePath()}) et la structure de son cache (clés propres à son besoin).
 *
 * Le client Jira est un COLLABORATEUR ({@see $jira}), pas une classe parente : un service de cache
 * *consomme* l'API Jira, il n'en est pas une spécialisation. L'hériter exposerait en outre toute la
 * surface publique de JiraService sur des objets dont ce n'est pas le rôle.
 */
abstract class AbstractJiraCacheService
{
    /** TTL du cache fichier, en secondes. Défaut commun, REDÉFINISSABLE par sous-classe (cf. isCacheExpired). */
    protected const TTL = 14400; // 4 heures

    /**
     * Contenu du cache en RAM. La structure exacte est définie par la sous-classe ; seule la clé
     * `updatedAt` (timestamp du dernier fetch) est exploitée ici, pour l'expiration.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    private Config $config;

    /** Client de l'API Jira, utilisé par les sous-classes pour alimenter leur cache. */
    protected JiraService $jira;

    /**
     * @throws \App\Exception\JiraCredentialsException Si une variable d'environnement Jira est absente
     */
    public function __construct()
    {
        $this->jira   = new JiraService();
        $this->config = new Config();
    }

    /**
     * Chemin absolu du fichier cache propre à la sous-classe.
     */
    abstract protected function cacheFilePath(): string;

    /**
     * Charge le cache depuis le disque (silencieux si absent/illisible). Les clés absentes du fichier
     * conservent la valeur par défaut initialisée par la sous-classe (union `$data + $this->cache`).
     */
    protected function loadCache(): void
    {
        $data = JsonFile::read($this->cacheFilePath());
        if ($data !== []) {
            $this->cache = $data + $this->cache;
        }
    }

    /**
     * Persiste le cache sur disque, de façon atomique (cf. {@see JsonFile::write()}).
     *
     * @throws \JsonException Si le cache ne peut pas être encodé en JSON
     */
    protected function saveCache(): void
    {
        JsonFile::write(
            $this->cacheFilePath(),
            json_encode($this->cache, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Vrai si le cache dépasse le TTL (basé sur `updatedAt`).
     *
     * `static::TTL` et non `self::TTL` : la liaison statique tardive est ce qui permet à une sous-classe
     * de choisir sa propre durée de vie (ex. un workflow projet, quasi immuable, se garde 24 h).
     */
    protected function isCacheExpired(): bool
    {
        return (time() - (int) ($this->cache['updatedAt'] ?? 0)) > static::TTL;
    }

    /**
     * Charge la liste des projets surveillés depuis watched_projects.json.
     *
     * @return string[] Clés de projets Jira (ex: ["CQFD", "ABC"])
     * @throws Exception Si le fichier est absent ou malformé
     */
    protected function loadWatchedProjects(): array
    {
        $projects = $this->config->getWatchedProjects();

        if (empty($projects)) {
            throw new Exception('watched_projects.json est vide ou malformé.');
        }

        return $projects;
    }

    /**
     * Ajoute une clé de projet aux projets surveillés (idempotent).
     *
     * Utilisé pour « adopter » un projet résolu à la volée (ex: Release d'un projet parent hors liste)
     * afin qu'il soit couvert par les prochaines reconstructions de cache.
     *
     * @throws \RuntimeException Si l'écriture de watched_projects.json échoue
     */
    protected function addWatchedProject(string $projectKey): void
    {
        $this->config->addWatchedProject($projectKey);
    }
}
