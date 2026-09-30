<?php

namespace App\Service;

use Throwable;

/**
 * JiraStatusCacheService
 *
 * Cache fichier des noms de statuts réellement déclarés sur un projet Jira (cache/jira_statuses.json).
 *
 * Sert à n'injecter dans un JQL que des libellés que Jira connaît : une clause `status CHANGED TO (...)`
 * fait valider chaque valeur, et un seul nom inconnu — libellé renommé côté Jira, coquille saisie dans la
 * page /config — renvoie une 400 qui fait échouer la requête ENTIÈRE. Croiser la configuration avec cette
 * liste transforme une panne totale en simple perte du critère concerné.
 *
 * TTL long assumé : le workflow d'un projet ne change qu'exceptionnellement, alors qu'il serait relu à
 * chaque affichage d'onglet. Expiration PAR PROJET (et non globale au fichier) : sans cela, le premier
 * projet mis en cache rendrait « frais » un fichier ne contenant pas encore les autres.
 */
class JiraStatusCacheService extends AbstractJiraCacheService
{
    /** 24 h : un workflow projet est quasi immuable, inutile de le redemander plus souvent. */
    protected const TTL = 86400;

    private const CACHE_FILE = __DIR__ . '/../../cache/jira_statuses.json';

    protected function cacheFilePath(): string
    {
        return self::CACHE_FILE;
    }

    /**
     * Noms des statuts déclarés sur le projet, servis du cache tant qu'ils ont moins de TTL secondes.
     *
     * Jira injoignable : retourne une liste vide plutôt que de propager l'erreur. L'appelant retombe
     * alors sur un JQL sans liste de statuts — moins précis, mais fonctionnel : ce cache est une
     * précaution, il ne doit jamais devenir un point de panne.
     *
     * @param  string       $projectKey Clé du projet Jira (ex: "REP")
     * @return list<string>             Noms de statuts, vide si indisponible
     */
    public function getStatusNames(string $projectKey): array
    {
        $this->loadCache();

        $projects = is_array($this->cache['projects'] ?? null) ? $this->cache['projects'] : [];
        $entry    = is_array($projects[$projectKey] ?? null) ? $projects[$projectKey] : null;

        if ($entry !== null && (time() - (int) ($entry['fetchedAt'] ?? 0)) <= static::TTL) {
            return $this->onlyStrings($entry['statuses'] ?? []);
        }

        try {
            $names = $this->onlyStrings(array_column($this->jira->getStatusesByProject($projectKey), 'name'));
        } catch (Throwable $e) {
            error_log('JiraStatusCacheService: statuts inaccessibles pour ' . $projectKey . ' : ' . $e->getMessage());
            return [];
        }

        $projects[$projectKey]     = ['fetchedAt' => time(), 'statuses' => $names];
        $this->cache['projects']   = $projects;
        $this->cache['updatedAt']  = time();
        $this->saveCache();

        return $names;
    }

    /**
     * Ne retient que les chaînes non vides d'un tableau hétérogène (cache relu depuis le disque).
     *
     * @param  mixed        $values
     * @return list<string>
     */
    private function onlyStrings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));
    }
}
