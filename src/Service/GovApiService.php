<?php

namespace App\Service;

use App\Model\Config;
use App\Support\ErrorLogger;
use App\Support\JsonFile;

/**
 * GovApiService
 *
 * Accès à l'API publique du gouvernement français.
 * Actuellement utilisé pour récupérer les jours fériés (calendrier.api.gouv.fr).
 *
 * Les résultats sont mis en cache sur disque, et rafraichit toutes les nouvelles années (fichier JSON),
 * ainsi qu'en mémoire statique pour la durée de la requête PHP courante.
 *
 * @see https://calendrier.api.gouv.fr/jours-feries/
 */
class GovApiService
{
    public const BASE_URL_NON_WORKING_DAYS = 'https://calendrier.api.gouv.fr/jours-feries';
    public const ZONE_METROPOLE = 'metropole';
    public const CACHE_DIR      = __DIR__ . '/../../cache';

    private bool $isDemo = false;

    /**
     * Cache mémoire statique : évite les lectures disque répétées
     * au sein d'une même requête PHP (Config est instancié plusieurs fois).
     *
     * @var array<string, array<string, string>>
     */
    private static array $memoryCache = [];

    public function __construct()
    {
        $this->isDemo  = Config::isDemo();
    }

    /**
     * Retourne tous les jours fériés disponibles pour une zone donnée.
     *
     * Ordre de priorité :
     * 1. Cache mémoire (même requête)
     * 2. Cache fichier valide (si année courante = année de mise en cache)
     * 3. Appel HTTP → mise en cache fichier + mémoire
     *
     * @return array<string, string> [date Y-m-d => nom du jour férié]
     *
     * @throws \RuntimeException Si l'appel HTTP échoue ou si la réponse est invalide
     */
    public function fetchAllHolidays(string $zone = self::ZONE_METROPOLE): array
    {
        // 1. Cache mémoire
        if (isset(self::$memoryCache[$zone])) {
            return self::$memoryCache[$zone];
        }

        // 2. Cache fichier
        $cached = $this->readFromFileCache($zone);
        if ($cached !== null) {
            self::$memoryCache[$zone] = $cached;
            return $cached;
        }

        // 3. Appel API
        $url  = sprintf('%s/%s.json', self::BASE_URL_NON_WORKING_DAYS, urlencode($zone));
        $data = $this->get($url);

        $this->writeToFileCache($zone, $data);
        self::$memoryCache[$zone] = $data;

        return $data;
    }

    /**
     * Lit le cache fichier pour une zone donnée.
     *
     * Le fichier cache est un JSON contenant :
     * - "cached_at" : timestamp Unix de la mise en cache
     * - "data"      : la réponse API [date => nom]
     *
     * Retourne null si le fichier est absent ou expiré (> 1 an).
     *
     * @return array<string, string>|null
     */
    private function readFromFileCache(string $zone): ?array
    {
        $content = JsonFile::read($this->getCacheFilePath($zone));

        // Fichier absent, illisible ou vide
        if (empty($content['data'])) {
            return null;
        }

        // Si l'année courante diffère de l'année de génération du cache, on rafraîchit le cache
        // avec les nouveaux jours fériés.
        if (!isset($content['cache_year']) || $content['cache_year'] !== (int) date('Y')) {
            return null;
        }

        return $content['data'];
    }

    /**
     * Écrit les données dans le cache fichier pour une zone donnée.
     *
     * Crée le répertoire cache/ s'il n'existe pas.
     * Le fichier contient le timestamp de mise en cache et les données.
     *
     * @param array<string, string> $data
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    private function writeToFileCache(string $zone, array $data): void
    {
        // cache_year = on stocke l'année de mise en cache
        $content = json_encode([
            'cache_year' => (int) date('Y'),
            'data'      => $data,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if (!JsonFile::write($this->getCacheFilePath($zone), $content)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('GovApiService : impossible d\'écrire le cache des jours fériés.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $this->getCacheFilePath($zone));
            throw $error;
        }
    }

    /**
     * Retourne le chemin absolu du fichier cache pour une zone donnée.
     *
     * @return string Ex: /path/to/project/cache/holidays_metropole.json
     */
    private function getCacheFilePath(string $zone): string
    {
        return self::CACHE_DIR . '/holidays_' . preg_replace('/[^a-z0-9_-]/i', '', $zone) . '.json';
    }

    /**
     * Effectue une requête HTTP GET via cURL et retourne le résultat décodé.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException Si cURL échoue, HTTP non-200, ou JSON invalide
     */
    private function get(string $url): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
        ]);

        if ($this->isDemo === true) {
            // ⚠️ Démo uniquement ⚠️ (proxy SSL corporate) => on bypass la vérification SSL
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new \RuntimeException("GovApiService::get() — cURL error: $error (URL: $url)");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new \RuntimeException("GovApiService::get() — HTTP $httpCode (URL: $url)");
        }

        // curl_exec() renvoie false (ou true sans CURLOPT_RETURNTRANSFER) hors cas nominal.
        if (!is_string($response)) {
            throw new \RuntimeException("GovApiService::get() — Réponse cURL vide ou invalide (URL: $url)");
        }

        $data = json_decode($response, true);

        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException("GovApiService::get() — Réponse JSON invalide (URL: $url)");
        }

        return $this->normalizeIndex($data);
    }

    /**
     * Normalise les dates servant d'index : YYYY-MM-DD → d/m/Y (ex. « 2023-12-25 » → « 25/12/2023 »).
     */
    private function normalizeIndex(array $data): array
    {
        foreach ($data as $origIndex => $label) {
            $newIndex =
                substr($origIndex, 8, 2) . '/' .
                substr($origIndex, 5, 2) . '/' .
                substr($origIndex, 0, 4);

            // Remplace l'index original par le nouveau
            unset($data[$origIndex]);
            $data[$newIndex] = $label;
        }

        return $data;
    }
}
