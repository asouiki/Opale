<?php

namespace App\Service;

use App\Support\ErrorLogger;
use App\Support\JsonFile;

/**
 * PiEntitiesCountCacheService
 *
 * Cache fichier du nombre d'entités du « PI en cours » (fenêtre [from, to] déduite des sprints
 * sélectionnés sur la page Métriques d'équipe), pour les deux natures d'entités des onglets :
 * Releases + Versions ({@see \App\Model\CurrentPiReleases}) et Features ({@see \App\Model\CurrentPiFeatures}).
 * Sert les cartes compteur de l'onglet Sprints sans relancer l'agrégat complet à chaque affichage.
 *
 * La clé de cache est dérivée de kind + projectKey + from + to : comme les bornes découlent directement
 * de la sélection de sprints, mémoriser par (projectKey, from, to) revient à mémoriser par sélection.
 *
 * Le count dépendant de données Jira évolutives (une Release peut être créée/clôturée après coup),
 * l'entrée expire après un TTL court (aligné sur le TTL des entités « en cours » des onglets).
 *
 * @see \App\Service\LlmAnalysisCacheService Pour le même pattern de cache fichier.
 */
class PiEntitiesCountCacheService
{
    /** Nature d'entité : Releases + Versions (onglet « Releases »). */
    public const KIND_RELEASES = 'releases';

    /** Nature d'entité : Features (onglet « Features »). */
    public const KIND_FEATURES = 'features';

    /** Répertoire de stockage des compteurs (les deux natures y cohabitent, distinguées par le nom de fichier). */
    public const CACHE_DIR = __DIR__ . '/../../cache/pi_entities_count';

    /**
     * Version du format/périmètre du count.
     * À INCRÉMENTER si la définition du count change (ex : Releases seules au lieu de Releases + Versions).
     */
    public const COUNT_VERSION = 1;

    /** Durée de validité d'une entrée (secondes) — 1 h, comme les entités « en cours » des onglets. */
    public const TTL_SECONDS = 3600;

    /**
     * @param string $kind Nature d'entité mémorisée (cf. KIND_RELEASES / KIND_FEATURES) — segmente le cache
     */
    public function __construct(private readonly string $kind)
    {
    }

    /**
     * Lit le count en cache pour une fenêtre donnée.
     *
     * Retourne null si le cache est absent, illisible, produit par une version obsolète, ou expiré.
     *
     * @param string $projectKey Clé du projet d'équipe Jira
     * @param string $from       Borne basse de la fenêtre (Y-m-d)
     * @param string $to         Borne haute de la fenêtre (Y-m-d)
     */
    public function get(string $projectKey, string $from, string $to): ?int
    {
        $path = $this->getCacheFilePath($projectKey, $from, $to);
        if (!file_exists($path)) {
            return null;
        }

        $content = json_decode((string) file_get_contents($path), true);

        if (!is_array($content) || !isset($content['count'])) {
            return null;
        }

        // Entrée produite par une version obsolète du périmètre → on l'ignore.
        if (($content['count_version'] ?? null) !== self::COUNT_VERSION) {
            return null;
        }

        // Entrée expirée → on force un recalcul.
        if (time() - (int) ($content['cached_at'] ?? 0) > self::TTL_SECONDS) {
            return null;
        }

        return (int) $content['count'];
    }

    /**
     * Écrit le count d'une fenêtre en cache.
     *
     * @param string $projectKey Clé du projet d'équipe Jira
     * @param string $from       Borne basse de la fenêtre (Y-m-d)
     * @param string $to         Borne haute de la fenêtre (Y-m-d)
     * @param int    $count      Nombre d'entités de la nature courante dans la fenêtre
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function put(string $projectKey, string $from, string $to, int $count): void
    {
        $content = json_encode([
            'cached_at'     => time(),
            'count_version' => self::COUNT_VERSION,
            'count'         => $count,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $path = $this->getCacheFilePath($projectKey, $from, $to);
        if (!JsonFile::write($path, $content)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('PiEntitiesCountCacheService : impossible d\'écrire le cache.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }
    }

    /**
     * Retourne le chemin absolu du fichier cache pour une fenêtre donnée.
     *
     * Format : count_{kind}_{projectKey}_{md5(from|to)}.json.
     */
    private function getCacheFilePath(string $projectKey, string $from, string $to): string
    {
        $safeKind = preg_replace('/[^a-z0-9_-]/i', '', $this->kind);
        $safeKey  = preg_replace('/[^a-z0-9_-]/i', '', $projectKey);
        $window   = md5($from . '|' . $to);

        return self::CACHE_DIR . '/count_' . $safeKind . '_' . $safeKey . '_' . $window . '.json';
    }
}
