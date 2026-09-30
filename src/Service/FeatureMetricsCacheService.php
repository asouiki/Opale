<?php

namespace App\Service;

use App\Model\Feature;
use App\Support\JsonFile;
use Throwable;

/**
 * FeatureMetricsCacheService
 *
 * Cache fichier des métriques calculées d'une Feature (agrégats + détail par ticket + ticket Feature
 * lui-même), sous forme de projection sérialisée (cf. Feature::toProjection()) — SANS changelog. Évite
 * de rappeler Jira à chaque affichage de la page /feature.
 *
 * Politique : une Feature Done a des métriques immuables → cache permanent ; une Feature en cours
 * expire après ONGOING_TTL (au-delà, recalcul depuis Jira). Un bump de METRICS_VERSION invalide tout
 * (y compris les Done, sinon cachées en permanence).
 *
 * Les écritures sont mémorisées puis persistées en un seul save() (évite N réécritures du fichier
 * lorsque l'onglet Features met en cache de nombreuses entités).
 */
class FeatureMetricsCacheService
{
    private const CACHE_FILE  = __DIR__ . '/../../cache/feature_metrics.json';

    /** Durée de validité du cache des Features non terminées (les Done n'expirent jamais). */
    private const ONGOING_TTL = 3600; // 60 minutes en secondes

    /**
     * Version de l'algorithme de calcul des métriques.
     * À INCRÉMENTER dès qu'un calcul change, pour invalider automatiquement les entrées existantes.
     *
     * 4 : le changelog de la Feature elle-même est désormais complété quand l'API le tronque
     * (JiraService::getIssueById()). Les métriques propres d'une Feature très éditée — lead time,
     * cycle time, temps par statut — étaient jusque-là calculées sur un historique partiel. Les
     * entrées produites avant ce correctif portent donc des valeurs fausses et doivent être recalculées,
     * y compris les Features terminées, dont le cache est permanent.
     */
    public const METRICS_VERSION = 4;

    /**
     * Cache indexé par ID de Feature.
     * Chaque entrée : { projection: array, done: bool, cachedAt: int, metricsVersion: int }.
     *
     * @var array<string, array{projection: array<string, mixed>, done: bool, cachedAt: int, metricsVersion?: int}>
     */
    private array $cache = [];

    /** Vrai dès qu'une entrée a été mémorisée sans avoir encore été persistée sur disque. */
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
                error_log('FeatureMetricsCacheService: sauvegarde de secours impossible : ' . $e->getMessage());
            }
        });
    }

    /**
     * Retourne la Feature hydratée depuis le cache si l'entrée existe et est encore valide, sinon null.
     *
     * @param string $id ID de la Feature Jira
     */
    public function get(string $id): ?Feature
    {
        if (!$this->isFresh($id)) {
            return null;
        }

        $entry = $this->cache[$id];

        try {
            return Feature::fromProjection($entry['projection']);
        } catch (Throwable $e) {
            error_log("FeatureMetricsCacheService: projection illisible pour $id : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Indique si une entrée est exploitable en l'état, SANS l'hydrater.
     *
     * Extrait des gardes de get() pour pouvoir décider, avant toute boucle, quelles Features devront
     * être recalculées — et donc lesquelles précharger en lot (cf. CurrentPiFeatures). Hydrater chaque
     * projection juste pour répondre à cette question coûterait la désérialisation de tout le cache.
     *
     * @param string $id ID ou Key de la Feature Jira
     */
    public function isFresh(string $id): bool
    {
        $entry = $this->cache[$id] ?? null;
        if (!isset($entry['projection'])) {
            return false;
        }

        // Entrée produite par une version d'algorithme obsolète → on l'ignore (recalcul).
        if (($entry['metricsVersion'] ?? null) !== self::METRICS_VERSION) {
            return false;
        }

        // Done → toujours valable ; en cours → valable tant que le TTL n'est pas dépassé.
        return !empty($entry['done']) || (time() - $entry['cachedAt']) <= self::ONGOING_TTL;
    }

    /**
     * Horodatage de mise en cache d'une Feature, ou null si elle n'y figure pas.
     *
     * Sert au bandeau « version issue du cache » de la page /feature : l'utilisateur doit pouvoir dater
     * ce qu'il regarde, la projection d'une Feature terminée n'expirant jamais d'elle-même.
     *
     * @param string $id ID ou Key de la Feature Jira
     */
    public function getCachedAt(string $id): ?int
    {
        $entry = $this->cache[$id] ?? null;

        return isset($entry['cachedAt']) ? (int) $entry['cachedAt'] : null;
    }

    /**
     * Mémorise (en RAM) la projection d'une Feature. Appeler save() pour persister sur disque.
     *
     * @param string  $id      ID de la Feature Jira
     * @param bool    $isDone  Feature en catégorie Done (cache permanent) ou en cours (TTL)
     * @param Feature $feature Collection calculée à sérialiser
     */
    public function put(string $id, bool $isDone, Feature $feature): void
    {
        $this->cache[$id] = [
            'projection'     => $feature->toProjection(),
            'done'           => $isDone,
            'cachedAt'       => time(),
            'metricsVersion' => self::METRICS_VERSION,
        ];

        $this->dirty = true;
    }

    /**
     * Persiste l'état courant du cache sur disque.
     *
     * Volontairement sans JSON_PRETTY_PRINT : ce fichier est une donnée machine, jamais relue à l'œil,
     * et l'indentation y pesait 64 % du volume (mesuré à 4,89 Mo sur 7,70 Mo).
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
