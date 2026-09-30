<?php

namespace App\Model;

use App\Repository\ProjectVersionsRepository;
use App\Service\JiraService;
use App\Service\ReleaseMetricsCacheService;
use Throwable;

/**
 * CurrentPiReleases
 *
 * Agrège les Releases (issues de type Release) et Versions (fixVersions) d'un projet rattachées au
 * « PI en cours », c.-à-d. dont le démarrage OU la clôture tombe dans la fenêtre temporelle
 * [fromDate, toDate] (bornes déduites des sprints sélectionnés sur la page Métriques d'équipe).
 *
 * Pour chaque entité retenue :
 * - Release → Lead/Cycle/Waiting Time CUMULÉS sur les tickets liés (collection Version), comme la
 *   page /release ; efficacité = Flow Efficiency (Production = Cycle − Waiting ; % = Production / Cycle).
 * - Version → Lead = jours ouvrés startDate→releaseDate ; Cycle = null (pas de date « in progress ») ;
 *   Waiting agrégé sur les tickets liés ; efficacité basée sur le Lead (Lead − Waiting total).
 *
 * Le calcul Lead/Cycle/Waiting nécessite de charger les tickets liés depuis Jira ; il est donc mis en
 * cache via ReleaseMetricsCacheService (cache/release_metrics.json), PARTAGÉ avec la page /release :
 * entités Done sans expiration (métriques immuables), entités en cours avec un TTL de 60 min. Le flag
 * $forceRefresh ne recalcule que les entités en cours ; $refreshKeys cible une sélection et recalcule
 * aussi les Done (cf. AbstractPiEntities::mustRecompute()).
 *
 * La logique type-agnostique (fenêtre, assemblage de ligne, agrégats) est portée par {@see AbstractPiEntities}.
 *
 * @phpstan-import-type PiRow from AbstractPiEntities
 */
class CurrentPiReleases extends AbstractPiEntities
{
    /** Clé du projet parent portant les tickets Release/Feature (ex : "XYZ"), '' si absent. */
    private string $parentProjectKey = '';

    /** Nom d'équipe (champ Équipe) filtrant les Releases du projet parent ; défaut = projectKey. */
    private string $releaseTeamName = '';

    /** Cache partagé des projections calculées (Releases & Versions), mutualisé avec /release. */
    private ReleaseMetricsCacheService $cache;

    /**
     * Calcule, pour le « PI en cours » [fromDate, toDate], les lignes Versions puis Releases et leurs
     * agrégats d'en-tête, puis persiste le cache partagé une seule fois en fin de calcul.
     *
     * @param string           $projectKey       Clé du projet d'équipe Jira (ex: "ABC")
     * @param string           $fromDate         Borne basse de la fenêtre (Y-m-d) = début du sprint le plus ancien
     * @param string           $toDate           Borne haute de la fenêtre (Y-m-d) = fin du sprint le plus récent
     * @param bool             $forceRefresh     Recalcule tout en ignorant/réécrivant le cache
     * @param string           $parentProjectKey Clé du projet parent pour les tickets Release (ex: "XYZ"), '' si absent
     * @param string           $releaseTeamName  Nom d'équipe filtrant les Releases du projet parent, '' = défaut projectKey
     * @param bool             $countOnly        Comptage seul : liste les entités sans calculer leurs métriques
     * @param list<string>     $refreshKeys      Identifiants des seules entités à recalculer, terminées ou non (cf. mustRecompute())
     * @param JiraService|null $jira             Optionnel, injecté pour les tests (défaut : new JiraService())
     * @param list<string>     $onlyKeys         Restriction au lot demandé (chargement par lots) ; vide = toute la fenêtre
     */
    public function __construct(
        string $projectKey,
        string $fromDate,
        string $toDate,
        bool $forceRefresh = false,
        string $parentProjectKey = '',
        string $releaseTeamName = '',
        bool $countOnly = false,
        array $refreshKeys = [],
        ?JiraService $jira = null,
        array $onlyKeys = []
    ) {
        $this->jira             = $jira ?? new JiraService();
        $this->fromDate         = $fromDate;
        $this->toDate           = $toDate;
        $this->forceRefresh     = $forceRefresh;
        $this->countOnly        = $countOnly;
        $this->refreshKeys      = $refreshKeys;
        $this->onlyKeys         = $onlyKeys;
        $this->parentProjectKey = strtoupper($parentProjectKey);
        // Défaut : le projectKey de l'équipe (cas où le champ Équipe = clé de projet).
        $this->releaseTeamName  = $releaseTeamName !== '' ? $releaseTeamName : $projectKey;

        $this->cache = $this->makeCache();

        $this->items = array_merge(
            $this->buildVersionRows($projectKey),
            $this->buildReleaseRows($projectKey)
        );
        $this->computeStats();

        // Persistance unique en fin de calcul (évite N réécritures du fichier de cache). En comptage
        // seul, aucune collection n'a été résolue : rien de neuf à persister.
        if (!$this->countOnly) {
            $this->cache->save();
        }
    }

    /**
     * Fabrique le service de cache partagé. Point d'extension isolant l'accès disque (surchargé en test).
     */
    protected function makeCache(): ReleaseMetricsCacheService
    {
        return new ReleaseMetricsCacheService();
    }

    /**
     * {@inheritdoc}
     *
     * Deep-link vers /release, avec la clé du projet porteur pour résoudre une Key hors watched_projects.
     */
    protected function analyseUrl(string $key, string $projectKey): string
    {
        return '/release?releaseOrVersionId=' . rawurlencode($key)
            . ($projectKey !== '' ? '&projectKey=' . rawurlencode($projectKey) : '');
    }

    // =========================================================================
    // Construction des lignes
    // =========================================================================

    /**
     * Construit les lignes pour les Versions (fixVersions) du projet dans la fenêtre.
     *
     * @return array<int, PiRow>
     */
    private function buildVersionRows(string $projectKey): array
    {
        try {
            $versions = $this->jira->getVersionsByProject($projectKey);
        } catch (Throwable $e) {
            error_log('CurrentPiReleases: versions inaccessibles : ' . $e->getMessage());
            return [];
        }

        $rows = [];
        foreach ($versions as $v) {
            $start = $this->toYmd($v['startDate'] ?? null);
            $close = $this->toYmd($v['releaseDate'] ?? null);

            if (!$this->isInWindow($start) && !$this->isInWindow($close)) {
                continue;
            }

            $id     = (string) ($v['id'] ?? '');
            $isDone = !empty($v['released']);
            $status = !empty($v['archived'])
                ? ProjectVersionsRepository::VERSION_STATUS_ARCHIVED
                : ($isDone ? ProjectVersionsRepository::VERSION_STATUS_RELEASED : ProjectVersionsRepository::VERSION_STATUS_UNRELEASED);

            // Lead/Cycle/Waiting Time d'une Version = cumuls des tickets liés (collection), comme la
            // page /release. Les colonnes Démarrage/Clôture affichent toujours startDate/releaseDate.
            $metrics = $this->metricsFrom($this->resolveCollection('version', $id, $isDone));

            $rows[] = $this->buildRow('version', $id, $v['name'] ?? '', $this->versionUrl($v), $status, $start, $close, $isDone, $metrics);
        }

        return $rows;
    }

    /**
     * Construit les lignes pour les issues de type Release du projet dans la fenêtre.
     *
     * @return array<int, PiRow>
     */
    private function buildReleaseRows(string $projectKey): array
    {
        try {
            $releases = $this->jira->getReleasesByProject($projectKey);
        } catch (Throwable $e) {
            error_log('CurrentPiReleases: releases inaccessibles : ' . $e->getMessage());
            return [];
        }

        // Si un projet parent est configuré (ex : "XYZ"), on fusionne ses tickets Release
        // — filtrés sur le champ Équipe pour ne garder que ceux de l'équipe — avec ceux du
        // projet équipe. Les Versions restent sur le projet équipe uniquement.
        if ($this->parentProjectKey !== '' && $this->parentProjectKey !== $projectKey) {
            try {
                $parentReleases = $this->jira->getReleasesByProject($this->parentProjectKey, $this->releaseTeamName);
                // Déduplique par clé Jira (impossible d'avoir un doublon inter-projets, mais défensif).
                $seen = array_flip(array_column($releases, 'key'));
                foreach ($parentReleases as $r) {
                    if (!isset($seen[$r['key']])) {
                        $releases[] = $r;
                    }
                }
            } catch (Throwable $e) {
                error_log('CurrentPiReleases: releases projet parent inaccessibles : ' . $e->getMessage());
            }
        }

        $rows = [];
        foreach ($releases as $r) {
            $fields = $r['fields'] ?? [];
            $start  = $this->toYmd($fields['created'] ?? null);
            $close  = $this->toYmd($fields['resolutiondate'] ?? null);

            if (!$this->isInWindow($start) && !$this->isInWindow($close)) {
                continue;
            }

            $key     = (string) ($r['key'] ?? '');
            $isDone  = ($fields['status']['statusCategory']['key'] ?? '') === 'done';
            $status  = $fields['status']['name'] ?? '';

            // Lead/Cycle/Waiting Time tous cumulés sur les tickets liés à la Release (collection
            // Version), pour refléter le travail réel de l'équipe — mêmes chiffres que /release.
            $metrics = $this->metricsFrom($this->resolveCollection('release', $key, $isDone));

            // Projet porteur de la Release (peut être le projet parent fusionné) : transmis au lien
            // d'analyse pour permettre à /release de résoudre une Key hors watched_projects.
            $releaseProjectKey = (string) ($fields['project']['key'] ?? '');

            $rows[] = $this->buildRow('release', $key, $fields['summary'] ?? '', $this->releaseUrl($key), $status, $start, $close, $isDone, $metrics, $releaseProjectKey);
        }

        return $rows;
    }

    /**
     * Récupère la collection (tickets liés) d'une entité via le cache partagé, sinon la calcule en
     * live puis la met en cache. Retourne null si Jira est inaccessible : l'entité est alors listée
     * sans métriques plutôt que de casser tout l'onglet.
     *
     * Le choix cache / recalcul est porté par AbstractPiEntities::mustRecompute() : rafraîchissement
     * ciblé sur une sélection (terminées comprises), sinon entités en cours uniquement — ce qui évite
     * un appel Jira par item Done lors d'un « Recalculer » global.
     *
     * En mode comptage seul, aucune collection n'est résolue : c'est ce qui évite l'aller-retour Jira
     * par entité (cf. AbstractPiEntities::$countOnly).
     *
     * @param string $type   'version' | 'release'
     * @param string $id     Identifiant (id fixVersion ou Key Release)
     * @param bool   $isDone Entité en catégorie Done
     */
    private function resolveCollection(string $type, string $id, bool $isDone): ?Version
    {
        if ($this->countOnly) {
            return null;
        }

        // Chargement par lots : hors du lot demandé, la ligne est listée sans métriques. La garde est
        // ici, et non sur la liste des entités comme pour les Features, parce que Versions et Releases
        // sont construites par deux chemins distincts qui convergent tous deux vers cette méthode.
        if ($this->onlyKeys !== [] && !in_array($id, $this->onlyKeys, true)) {
            return null;
        }

        if (!$this->mustRecompute($id, $isDone)) {
            $cached = $this->cache->get($type, $id);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $collection = $this->newCollection($id);
        } catch (Throwable $e) {
            error_log("CurrentPiReleases: collection indisponible pour $type:$id : " . $e->getMessage());
            return null;
        }

        $this->cache->put($type, $id, $isDone, $collection);

        return $collection;
    }

    /**
     * Instancie la collection d'issues (tickets liés) d'une Release/Version pour agréger les temps
     * d'attente. Point d'extension isolant l'accès Jira (surchargé dans les tests).
     *
     * @param string $idOrKey ID numérique (fixVersion) ou Key (Release, ex: ABC-123)
     */
    protected function newCollection(string $idOrKey): Version
    {
        return new Version($idOrKey);
    }

    // =========================================================================
    // URLs Jira
    // =========================================================================

    /**
     * URL Jira d'une Version (page versions du projet).
     *
     * @param array $v Données brutes de la fixVersion
     */
    private function versionUrl(array $v): string
    {
        return JiraUrl::version((string) ($v['projectId'] ?? ''), (string) ($v['id'] ?? ''));
    }

    /**
     * URL Jira d'une issue Release (page browse).
     *
     * @param string $key Key de la Release (ex: ABC-123)
     */
    private function releaseUrl(string $key): string
    {
        return JiraUrl::browse($key);
    }
}
