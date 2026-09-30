<?php

namespace App\Service;

use App\Support\ErrorLogger;
use App\Support\JsonFile;

/**
 * SprintCacheService
 *
 * Cache fichier des « photos » de sprints CLÔTURÉS.
 *
 * Un sprint clôturé est immuable : son état reconstruit à la date de clôture ne
 * change plus. On met donc en cache le résultat calculé (snapshot prêt pour
 * l'affichage) afin d'éviter de re-paginer tout le changelog Jira à chaque
 * consultation.
 *
 * Format du fichier (JSON) :
 * - "cached_at"       : timestamp Unix de la mise en cache
 * - "metrics_version" : version de l'algorithme de calcul ; un changement
 *                       invalide automatiquement les anciens caches
 * - "data"            : snapshot du Sprint (cf. \App\Model\Sprint::toSnapshot())
 *
 * DEUX fichiers au plus par sprint :
 * - le NOMINAL (sprint_{KEY}_{ID}.json), photo à la clôture, jamais écrasé par une photo « à date » ;
 * - la PHOTO à date (sprint_{KEY}_{ID}_asof_{AAAAMMJJ}.json), unique par sprint et remplacée à chaque
 *   enregistrement, qui rattrape un sprint dont le reliquat a été déplacé avant la clôture.
 *
 * @see \App\Service\GovApiService Pour le même pattern de cache fichier.
 */
class SprintCacheService
{
    /** Répertoire de stockage des caches de sprint. */
    public const CACHE_DIR = __DIR__ . '/../../cache/sprints';

    /**
     * Version de l'algorithme de calcul des métriques de sprint.
     * À INCRÉMENTER dès qu'un calcul change, pour invalider automatiquement les anciens caches existants.
     */
    public const METRICS_VERSION = 29;

    /**
     * Lit le snapshot en cache pour un sprint donné.
     *
     * Retourne null si le cache est absent, illisible, ou produit par une version d'algorithme différente (→ recalcul nécessaire).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC") — incluse dans le nom de fichier
     *
     * @return array{cached_at:int, data:array}|null
     */
    public function get(string $sprintId, string $projectKey = ''): ?array
    {
        $path = $this->getCacheFilePath($sprintId, $projectKey);
        if (!file_exists($path)) {
            return null;
        }

        $content = json_decode((string) file_get_contents($path), true);

        if (!is_array($content) || empty($content['data'])) {
            return null;
        }

        // Cache produit par une version d'algorithme obsolète → on l'ignore.
        if (($content['metrics_version'] ?? null) !== self::METRICS_VERSION) {
            return null;
        }

        return [
            'cached_at' => (int) ($content['cached_at'] ?? 0),
            'data'      => $content['data'],
        ];
    }

    /**
     * Indique si un cache valide et à jour existe pour un sprint donné.
     *
     * Réutilise get() pour hériter de la vérification de version.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function has(string $sprintId, string $projectKey = ''): bool
    {
        return $this->get($sprintId, $projectKey) !== null;
    }

    /**
     * Écrit le snapshot d'un sprint en cache.
     *
     * @param array  $snapshot   Donnée issue de \App\Model\Sprint::toSnapshot()
     * @param string $projectKey Clé du projet Jira (ex: "ABC") — incluse dans le nom de fichier
     *
     * @throws \RuntimeException Si le répertoire ou l'écriture échoue
     */
    public function put(string $sprintId, array $snapshot, string $projectKey = ''): void
    {
        $content = json_encode([
            'cached_at'       => time(),
            'metrics_version' => self::METRICS_VERSION,
            'data'            => $snapshot,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $path = $this->getCacheFilePath($sprintId, $projectKey);
        if (!JsonFile::write($path, $content)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('SprintCacheService : impossible d\'écrire le cache du sprint.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }
    }

    /**
     * Lit la photo « à date » d'un sprint, si elle existe.
     *
     * Contrairement au cache nominal, elle N'EST PAS invalidée par un changement de METRICS_VERSION : c'est une
     * correction saisie manuellement, que l'utilisateur devrait sinon refaire à chaque évolution d'une métrique.
     * La version ayant produit la photo est donc restituée, à charge de la vue de la signaler.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     *
     * @return array{cached_at:int, metrics_version:int, data:array<string, mixed>}|null
     */
    public function getAsOf(string $sprintId, string $projectKey = ''): ?array
    {
        $path = $this->findAsOfFile($sprintId, $projectKey);
        if ($path === null) {
            return null;
        }

        $content = json_decode((string) file_get_contents($path), true);

        if (!is_array($content) || !is_array($content['data'] ?? null) || $content['data'] === []) {
            return null;
        }

        return [
            'cached_at'       => (int) ($content['cached_at'] ?? 0),
            'metrics_version' => (int) ($content['metrics_version'] ?? 0),
            'data'            => $content['data'],
        ];
    }

    /**
     * Écrit (ou remplace) la photo « à date » d'un sprint.
     *
     * Une seule photo est conservée par sprint : les précédentes sont supprimées avant écriture, la date figurant
     * dans le nom du fichier.
     *
     * @param array<string, mixed> $snapshot   Donnée issue de \App\Model\Sprint::toSnapshot()
     * @param \DateTime            $asOfDate   Date d'observation, reprise dans le nom du fichier
     * @param string    $projectKey Clé du projet Jira (ex: "ABC")
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function putAsOf(string $sprintId, array $snapshot, \DateTime $asOfDate, string $projectKey = ''): void
    {
        $content = json_encode([
            'cached_at'       => time(),
            'metrics_version' => self::METRICS_VERSION,
            'data'            => $snapshot,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Purge d'abord : la date faisant partie du nom, une nouvelle photo ne recouvrirait pas la précédente.
        $this->deleteAsOf($sprintId, $projectKey);

        $path = $this->asOfPathPrefix($sprintId, $projectKey) . $asOfDate->format('Ymd') . '.json';
        if (!JsonFile::write($path, $content)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('SprintCacheService : impossible d\'écrire la photo du sprint.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }
    }

    /**
     * Dates d'observation des photos enregistrées, pour un lot de sprints.
     *
     * Alimente les sélecteurs de sprint : une photo y apparaît comme une entrée à part, sélectionnable à la place de
     * l'état de clôture. Les sprints sans photo sont absents du résultat.
     *
     * @param  list<string>          $sprintIds  IDs Agile des sprints à interroger
     * @param  string                $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<string, string>             sprintId → date d'observation ISO 8601
     */
    public function listAsOf(array $sprintIds, string $projectKey = ''): array
    {
        $dates = [];

        foreach ($sprintIds as $sprintId) {
            $asOf = $this->getAsOf($sprintId, $projectKey)['data']['asOfDate'] ?? null;
            if (is_string($asOf) && $asOf !== '') {
                $dates[$sprintId] = $asOf;
            }
        }

        return $dates;
    }

    /**
     * Supprime la (ou les) photo(s) « à date » d'un sprint. Sans effet s'il n'y en a aucune.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function deleteAsOf(string $sprintId, string $projectKey = ''): void
    {
        foreach ($this->globAsOfFiles($sprintId, $projectKey) as $existing) {
            // Best-effort : une photo non supprimable ne doit pas faire échouer l'action en cours ; findAsOfFile()
            // retient de toute façon la plus récente.
            @unlink($existing);
        }
    }

    /**
     * Retourne le chemin absolu du fichier cache pour un sprint donné.
     *
     * Format : sprint_{projectKey}_{sprintId}.json (ex: sprint_ABC_1234.json).
     * Si projectKey est vide, fallback sur l'ancien format : sprint_{sprintId}.json.
     */
    private function getCacheFilePath(string $sprintId, string $projectKey = ''): string
    {
        return $this->baseFilePath($sprintId, $projectKey) . '.json';
    }

    /**
     * Chemin du fichier cache SANS extension, socle commun au nominal et aux photos « à date ».
     */
    private function baseFilePath(string $sprintId, string $projectKey = ''): string
    {
        $safeId = preg_replace('/[^a-z0-9_-]/i', '', $sprintId);
        if ($projectKey !== '') {
            $safeKey = preg_replace('/[^a-z0-9_-]/i', '', $projectKey);
            return self::CACHE_DIR . '/sprint_' . $safeKey . '_' . $safeId;
        }
        return self::CACHE_DIR . '/sprint_' . $safeId;
    }

    /**
     * Préfixe des photos « à date » : sprint_{KEY}_{ID}_asof_ (la date AAAAMMJJ et l'extension suivent).
     *
     * La date du nom est PUREMENT indicative (lecture humaine du répertoire cache/) : l'application ne lit que la
     * clé `asOfDate` du snapshot. C'est pourquoi la recherche du fichier passe par un glob, la date n'étant pas
     * connue de l'appelant.
     */
    private function asOfPathPrefix(string $sprintId, string $projectKey = ''): string
    {
        return $this->baseFilePath($sprintId, $projectKey) . '_asof_';
    }

    /**
     * Liste les fichiers photo d'un sprint (normalement 0 ou 1).
     *
     * @return list<string>
     */
    private function globAsOfFiles(string $sprintId, string $projectKey = ''): array
    {
        $found = glob($this->asOfPathPrefix($sprintId, $projectKey) . '*.json');

        return $found === false ? [] : $found;
    }

    /**
     * Chemin de la photo « à date » d'un sprint, ou null s'il n'y en a pas.
     *
     * Retient la plus récemment écrite si plusieurs subsistent (suppression antérieure ayant échoué), pour rester
     * cohérent avec la règle « une seule photo par sprint ».
     */
    private function findAsOfFile(string $sprintId, string $projectKey = ''): ?string
    {
        $files = $this->globAsOfFiles($sprintId, $projectKey);
        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
