<?php

namespace App\Service;

use App\Model\Version;
use App\Support\JsonFile;
use Throwable;

/**
 * ReleaseMetricsCacheService
 *
 * Cache fichier PARTAGÉ des métriques calculées d'une Release (issue) ou d'une Version (fixVersion),
 * sous forme de projection sérialisée (cf. Version::toProjection()) — agrégats + détail par ticket,
 * SANS changelog. Mutualisé par l'onglet Releases de /team-metrics et la page /release : la première
 * page qui calcule une entité remplit le cache, l'autre la réutilise sans rappeler Jira.
 *
 * Politique : une entité Done a des métriques immuables → cache permanent ; une entité en cours expire
 * après ONGOING_TTL (au-delà, recalcul depuis Jira).
 *
 * Les écritures sont mémorisées puis persistées en un seul save() (évite N réécritures du fichier
 * lorsque l'onglet met en cache de nombreuses entités).
 */
class ReleaseMetricsCacheService
{
    private const CACHE_FILE  = __DIR__ . '/../../cache/release_metrics.json';

    /** Durée de validité du cache des entités non terminées (les Done n'expirent jamais). */
    private const ONGOING_TTL = 3600; // 60 minutes en secondes

    /**
     * Version de l'algorithme de calcul des métriques (agrégats + projection par ticket).
     * À INCRÉMENTER dès qu'un calcul change, pour invalider automatiquement les entrées
     * existantes — y compris les entités Done, sinon cachées en permanence.
     */
    public const METRICS_VERSION = 2;

    /**
     * Cache indexé par « type:identifiant » (ex: « release:ABC-123 », « version:34176 »).
     * Chaque entrée : { projection: array, done: bool, cachedAt: int, metricsVersion: int }.
     *
     * @var array<string, array{projection: array, done: bool, cachedAt: int, metricsVersion?: int}>
     */
    private array $cache = [];

    /** Vrai dès qu'une entrée a été mémorisée ou invalidée sans avoir encore été persistée sur disque. */
    private bool $dirty = false;

    public function __construct()
    {
        $this->load();
        $this->registerEmergencySave();
    }

    /**
     * Filet de persistance : sauve le cache si PHP est tué avant le save() de fin de calcul.
     *
     * Sans lui, un dépassement de max_execution_time sur une fenêtre large perd TOUT le travail accompli
     * (les appels Jira déjà payés), et le rechargement suivant repart de zéro. Avec lui, chaque tentative
     * capitalise sur la précédente jusqu'à converger.
     */
    private function registerEmergencySave(): void
    {
        register_shutdown_function(function (): void {
            if (!$this->dirty) {
                return;
            }

            // Dépassement mémoire : ré-encoder tout le cache rejouerait le fatal. On renonce plutôt que
            // d'ajouter une seconde erreur à la première.
            $error = error_get_last();
            if ($error !== null && stripos($error['message'], 'allowed memory size') !== false) {
                return;
            }

            try {
                $this->save();
            } catch (Throwable $e) {
                error_log('ReleaseMetricsCacheService: sauvegarde de secours impossible : ' . $e->getMessage());
            }
        });
    }

    /**
     * Retourne la Version hydratée depuis le cache si l'entrée existe et est encore valide, sinon null.
     *
     * @param string $type 'release' | 'version'
     * @param string $id   Key Release (ABC-123) ou ID fixVersion
     */
    public function get(string $type, string $id): ?Version
    {
        $entry = $this->cache[$type . ':' . $id] ?? null;
        if (!isset($entry['projection'])) {
            return null;
        }

        // Entrée produite par une version d'algorithme obsolète → on l'ignore (recalcul).
        if (($entry['metricsVersion'] ?? null) !== self::METRICS_VERSION) {
            return null;
        }

        // Done → toujours valable ; en cours → valable tant que le TTL n'est pas dépassé.
        $fresh = !empty($entry['done']) || (time() - $entry['cachedAt']) <= self::ONGOING_TTL;
        if (!$fresh) {
            return null;
        }

        try {
            return Version::fromProjection($entry['projection']);
        } catch (Throwable $e) {
            error_log("ReleaseMetricsCacheService: projection illisible pour $type:$id : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Horodatage de mise en cache d'une entité, ou null si elle n'y figure pas.
     *
     * Sert au bandeau « version issue du cache » de la page /release : l'utilisateur doit pouvoir dater
     * ce qu'il regarde, une projection d'entité terminée n'expirant jamais d'elle-même.
     *
     * @param string $type 'release' | 'version'
     * @param string $id   Key Release (ABC-123) ou ID fixVersion
     */
    public function getCachedAt(string $type, string $id): ?int
    {
        $entry = $this->cache[$type . ':' . $id] ?? null;

        return isset($entry['cachedAt']) ? (int) $entry['cachedAt'] : null;
    }

    /**
     * Mémorise (en RAM) la projection d'une entité. Appeler save() pour persister sur disque.
     *
     * @param string  $type    'release' | 'version'
     * @param string  $id      Key Release ou ID fixVersion
     * @param bool    $isDone  Entité en catégorie Done (cache permanent) ou en cours (TTL)
     * @param Version $version Collection calculée à sérialiser
     */
    public function put(string $type, string $id, bool $isDone, Version $version): void
    {
        $this->cache[$type . ':' . $id] = [
            'projection'     => $version->toProjection(),
            'done'           => $isDone,
            'cachedAt'       => time(),
            'metricsVersion' => self::METRICS_VERSION,
        ];

        $this->dirty = true;
    }

    /**
     * Invalide (en RAM) l'entrée d'une entité. Appeler save() pour persister.
     *
     * @param string $type 'release' | 'version'
     * @param string $id   Key Release ou ID fixVersion
     */
    public function bust(string $type, string $id): void
    {
        unset($this->cache[$type . ':' . $id]);

        $this->dirty = true;
    }

    /**
     * Persiste l'état courant du cache sur disque.
     *
     * Volontairement sans JSON_PRETTY_PRINT : ce fichier est une donnée machine, jamais relue à l'œil,
     * et l'indentation y pesait 51 % du volume (mesuré à 1,02 Mo sur 2,01 Mo).
     *
     * @throws \JsonException Si le cache ne peut pas être encodé en JSON
     */
    public function save(): void
    {
        JsonFile::write(
            self::CACHE_FILE,
            json_encode($this->cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->dirty = false;
    }

    /**
     * Charge le cache depuis le disque (silencieux si absent ou illisible).
     */
    private function load(): void
    {
        $data = JsonFile::read(self::CACHE_FILE);
        if ($data !== []) {
            $this->cache = $data;
        }
    }
}
