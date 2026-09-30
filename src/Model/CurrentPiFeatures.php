<?php

namespace App\Model;

use App\Service\FeatureMetricsCacheService;
use App\Service\JiraService;
use App\Service\JiraStatusCacheService;
use Throwable;

/**
 * CurrentPiFeatures
 *
 * Agrège les Features (issues de type Feature) d'un projet rattachées au « PI en cours », c.-à-d.
 * TRAVAILLÉES pendant la fenêtre [fromDate, toDate] : toute Feature entrée dans un statut de mise en
 * chantier ou de clôture durant la période, y compris celles démarrées avant et toujours en cours
 * (bornes déduites des sprints sélectionnés, ou saisies au calendrier sur la page Métriques d'équipe).
 * Pendant Features de {@see CurrentPiReleases}, dont il partage toute la logique via
 * {@see AbstractPiEntities} — à ceci près que le filtrage de fenêtre est ici porté INTÉGRALEMENT par le
 * JQL (cf. JiraService::getFeaturesByProject()) et n'est jamais rejoué en PHP.
 *
 * Pour chaque Feature retenue, Lead/Cycle/Waiting Time sont CUMULÉS sur ses tickets enfants (collection
 * Feature, comme la page /feature) ; efficacité = Flow Efficiency (Production = Cycle − Waiting ;
 * % = Production / Cycle). Les colonnes Démarrage/Clôture affichent les dates de TRANSITION réelles,
 * lues dans le changelog de la Feature (cf. startDate() / closeDate()), et non created/resolutiondate.
 *
 * Chaque ligne porte en plus un `breakdown` ({@see FeatureBreakdown}) alimentant son détail dépliable :
 * total des Story Points, bugs et répartition par type de ticket. Contrairement aux métriques de flux,
 * il est restreint aux tickets enfants du PROJET DE L'ÉQUIPE : la page mesure la contribution de
 * l'équipe, là où /feature retient tous les contributeurs.
 *
 * Les Features du projet d'équipe sont fusionnées avec celles du projet parent filtrées sur le champ
 * Équipe (mêmes réglages parentProjectKey / releaseTeamName que les Releases, cf. page /config).
 *
 * Le calcul est mis en cache via FeatureMetricsCacheService (cache/feature_metrics.json), PARTAGÉ avec
 * la page /feature : Features Done sans expiration (métriques immuables), en cours avec un TTL de 60 min.
 * Le flag $forceRefresh ne recalcule que les Features en cours ; $refreshKeys cible une sélection et
 * recalcule aussi les Done (cf. AbstractPiEntities::mustRecompute()).
 *
 * @phpstan-import-type PiRow from AbstractPiEntities
 */
class CurrentPiFeatures extends AbstractPiEntities
{
    /** Clé du projet parent portant les tickets Feature (ex : "XYZ"), '' si absent. */
    private string $parentProjectKey = '';

    /** Nom d'équipe (champ Équipe) filtrant les Features du projet parent ; défaut = projectKey. */
    private string $releaseTeamName = '';

    /** Cache des projections calculées (Features), mutualisé avec /feature. */
    private FeatureMetricsCacheService $cache;

    /**
     * Calcule, pour le « PI en cours » [fromDate, toDate], les lignes Features et leurs agrégats d'en-tête.
     *
     * @param string           $projectKey       Clé du projet d'équipe Jira (ex: "ABC")
     * @param string           $fromDate         Borne basse de la fenêtre (Y-m-d) = début du sprint le plus ancien
     * @param string           $toDate           Borne haute de la fenêtre (Y-m-d) = fin du sprint le plus récent
     * @param bool             $forceRefresh     Recalcule les Features en cours en ignorant/réécrivant le cache
     * @param string           $parentProjectKey Clé du projet parent pour les tickets Feature (ex: "XYZ"), '' si absent
     * @param string           $releaseTeamName  Nom d'équipe filtrant les Features du projet parent, '' = défaut projectKey
     * @param bool             $countOnly        Comptage seul : liste les Features sans calculer leurs métriques
     * @param list<string>     $refreshKeys      Keys des seules Features à recalculer, terminées ou non (cf. mustRecompute())
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

        $this->items = $this->buildFeatureRows($projectKey);
        $this->computeStats();

        // Persistance unique en fin de calcul (évite N réécritures du fichier de cache). En comptage
        // seul, aucune collection n'a été résolue : rien de neuf à persister.
        if (!$this->countOnly) {
            $this->cache->save();
        }
    }

    /**
     * Fabrique le service de cache. Point d'extension isolant l'accès disque (surchargé en test).
     */
    protected function makeCache(): FeatureMetricsCacheService
    {
        return new FeatureMetricsCacheService();
    }

    /**
     * Fabrique le cache des statuts déclarés d'un projet. Point d'extension isolant l'accès Jira et
     * disque (surchargé en test), au même titre que makeCache().
     */
    protected function makeStatusCache(): JiraStatusCacheService
    {
        return new JiraStatusCacheService();
    }

    /**
     * {@inheritdoc}
     *
     * Deep-link vers /feature (la Feature est résolue par sa Key, sans besoin du projet porteur).
     */
    protected function analyseUrl(string $key, string $projectKey): string
    {
        return '/feature?featureId=' . rawurlencode($key);
    }

    // =========================================================================
    // Construction des lignes
    // =========================================================================

    /**
     * Construit les lignes pour les issues de type Feature du projet (et du projet parent) dans la fenêtre.
     *
     * @return array<int, PiRow>
     */
    private function buildFeatureRows(string $projectKey): array
    {
        // Échec sur le projet principal : on laisse remonter. Renvoyer [] afficherait « aucune Feature »,
        // c.-à-d. une réponse FAUSSE présentée comme un fait, alors qu'un JQL refusé ou un Jira injoignable
        // doivent se voir. Le projet parent, lui, reste en best-effort (cf. plus bas) : sa perte dégrade la
        // liste sans la rendre mensongère.
        $features = $this->jira->getFeaturesByProject(
            $projectKey,
            '',
            $this->fromDate,
            $this->toDate,
            $this->transitionStatuses($projectKey)
        );

        // Si un projet parent est configuré (ex : "XYZ"), on fusionne ses tickets Feature — filtrés
        // sur le champ Équipe pour ne garder que ceux de l'équipe — avec ceux du projet équipe.
        if ($this->parentProjectKey !== '' && $this->parentProjectKey !== $projectKey) {
            try {
                $parentFeatures = $this->jira->getFeaturesByProject(
                    $this->parentProjectKey,
                    $this->releaseTeamName,
                    $this->fromDate,
                    $this->toDate,
                    $this->transitionStatuses($this->parentProjectKey)
                );
                // Déduplique par clé Jira (impossible d'avoir un doublon inter-projets, mais défensif).
                $seen = array_flip(array_column($features, 'key'));
                foreach ($parentFeatures as $f) {
                    if (!isset($seen[$f['key']])) {
                        $features[] = $f;
                    }
                }
            } catch (Throwable $e) {
                error_log('CurrentPiFeatures: features projet parent inaccessibles : ' . $e->getMessage());
            }
        }

        // Restriction au lot demandé, AVANT le préchargement : tout le pipeline en aval — requêtes
        // groupées comprises — se limite alors aux seules Features du lot.
        if ($this->onlyKeys !== []) {
            $features = array_values(array_filter(
                $features,
                fn (array $feature): bool => in_array((string) ($feature['key'] ?? ''), $this->onlyKeys, true)
            ));
        }

        $this->prefetchChildren($features);

        $rows = [];
        foreach ($features as $f) {
            $fields = $f['fields'] ?? [];
            $isDone = ($fields['status']['statusCategory']['key'] ?? '') === 'done';
            $key    = (string) ($f['key'] ?? '');
            $status = $fields['status']['name'] ?? '';

            // Lead/Cycle/Waiting Time cumulés sur les tickets enfants de la Feature (collection),
            // pour refléter le travail réel de l'équipe — mêmes chiffres que /feature.
            $collection = $this->resolveCollection($key, $isDone);
            $metrics    = $this->metricsFrom($collection);

            $start = $this->startDate($collection, $fields);
            $close = $this->closeDate($collection, $fields);

            // AUCUN tri supplémentaire ici : la sélection du JQL fait foi. Filtrer sur la première date
            // de mise en chantier écarterait les Features démarrées AVANT la fenêtre et toujours en cours
            // pendant — or elles ont bien été travaillées sur la période (67 lignes contre 35 sur un cas
            // réel). Les colonnes Démarrage/Clôture peuvent donc porter des dates hors fenêtre : c'est
            // voulu, elles datent le cycle de vie de la Feature, pas son activité dans la période.

            // Détail dépliable de la ligne, sur la seule contribution de l'équipe (cf. teamIssues()).
            $breakdown = FeatureBreakdown::fromIssues($this->teamIssues($collection, $projectKey));

            $rows[] = $this->buildRow('feature', $key, $fields['summary'] ?? '', $this->featureUrl($key), $status, $start, $close, $isDone, $metrics, breakdown: $breakdown);
        }

        return $rows;
    }

    /**
     * Précharge en DEUX recherches groupées tout ce dont les Features à recalculer auront besoin :
     * leurs tickets enfants, et leurs propres métadonnées.
     *
     * Sans lui, chaque Feature déclenche son propre `parent = {key}` depuis Feature::loadIssues() et son
     * propre getIssueById() depuis Feature::loadCollectionData(), soit deux allers-retours par ligne. À
     * cache froid sur une fin de PI (53 Features clôturées la même semaine, cas réel), ces requêtes
     * sérialisées faisaient dépasser max_execution_time.
     *
     * La sélection reprend exactement la décision de resolveCollection(), via isFresh() qui évite
     * d'hydrater les projections juste pour savoir lesquelles sont périmées. Précharger les Features
     * servies du cache serait le pire des cas : on paierait des requêtes dont personne ne veut le
     * résultat.
     *
     * Best-effort : un échec laisse chaque Feature retomber sur sa requête unitaire.
     *
     * @param array<int, array<string, mixed>> $features Features brutes issues du JQL de fenêtre
     */
    private function prefetchChildren(array $features): void
    {
        // En comptage seul, aucune collection n'est résolue : il n'y a rien à précharger.
        if ($this->countOnly) {
            return;
        }

        $toFetch = [];
        foreach ($features as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $isDone = (($f['fields']['status']['statusCategory']['key'] ?? '') === 'done');
            if ($this->mustRecompute($key, $isDone) || !$this->cache->isFresh($key)) {
                $toFetch[] = $key;
            }
        }

        // En dessous de deux Features, la requête groupée ne remplacerait qu'une requête unitaire.
        if (count($toFetch) < 2) {
            return;
        }

        // Les deux préchargements sont indépendants : l'échec de l'un ne doit pas priver l'autre de son
        // gain, chacun retombant de son côté sur les requêtes unitaires.
        try {
            // Même filtre de types que Feature::loadIssues(), sans quoi le préchargement servirait un
            // ensemble de tickets différent de celui qu'aurait renvoyé la requête unitaire.
            $this->jira->prefetchChildrenByParent($toFetch, (new Config())->getMetricIssueTypes());
        } catch (Throwable $e) {
            error_log('CurrentPiFeatures: préchargement des tickets enfants impossible : ' . $e->getMessage());
        }

        try {
            $this->jira->prefetchFeatureDetails($toFetch);
        } catch (Throwable $e) {
            error_log('CurrentPiFeatures: préchargement des métadonnées de Features impossible : ' . $e->getMessage());
        }
    }

    /**
     * Récupère la collection (tickets enfants) d'une Feature via le cache, sinon la calcule en live
     * puis la met en cache. Retourne null si Jira est inaccessible : la Feature est alors listée sans
     * métriques plutôt que de casser tout l'onglet.
     *
     * Le choix cache / recalcul est porté par AbstractPiEntities::mustRecompute() : rafraîchissement
     * ciblé sur une sélection (terminées comprises), sinon Features en cours uniquement.
     *
     * En mode comptage seul, aucune collection n'est résolue : c'est ce qui évite l'aller-retour Jira
     * par Feature (cf. AbstractPiEntities::$countOnly).
     *
     * @param string $key    Key de la Feature (ex: ABC-123)
     * @param bool   $isDone Feature en catégorie Done
     */
    private function resolveCollection(string $key, bool $isDone): ?Feature
    {
        if ($this->countOnly) {
            return null;
        }

        if (!$this->mustRecompute($key, $isDone)) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $collection = $this->newCollection($key);
        } catch (Throwable $e) {
            error_log("CurrentPiFeatures: collection indisponible pour feature:$key : " . $e->getMessage());
            return null;
        }

        $this->cache->put($key, $isDone, $collection);

        return $collection;
    }

    /**
     * Statuts dont l'entrée vaut « mise en chantier » ou « clôture » d'une Feature, pour le JQL de fenêtre.
     *
     * Union des catégories `sprint_statuses` et `done_statuses` du workflow Feature — la catégorie sprint
     * est prise EN ENTIER, premier statut compris (« planifiée » vaut démarrage, choix KISS assumé).
     *
     * Croisée avec les statuts réellement déclarés sur le projet : JQL valide chaque libellé, et un seul
     * nom inconnu ferait échouer la requête entière. Un libellé renommé côté Jira, ou une coquille saisie
     * dans /config, ne coûte donc que la perte de ce critère au lieu de vider l'onglet.
     *
     * @param  string       $projectKey Projet interrogé (le parent porte souvent les Features)
     * @return list<string>
     */
    private function transitionStatuses(string $projectKey): array
    {
        $workflow = (new Config())->getJiraWorkflow(Config::ISSUE_TYPE_FEATURE);
        $wanted   = array_merge($workflow['sprint_statuses'] ?? [], $workflow['done_statuses'] ?? []);

        $declared = $this->makeStatusCache()->getStatusNames($projectKey);
        if ($declared === []) {
            // Statuts indisponibles : JiraService retombe sur un JQL sans liste, plus large mais valide.
            return [];
        }

        return array_values(array_intersect($wanted, $declared));
    }

    /**
     * Date de MISE EN CHANTIER de la Feature (colonne « Démarrage »), au format Y-m-d.
     *
     * Lue dans le changelog de la Feature via son ticket propre — disponible sans surcoût dès que la
     * collection est résolue. Repli sur la date de création en comptage seul, où aucune collection n'est
     * chargée : la création n'est pas un démarrage, mais c'est la seule date connue à ce stade, et les
     * cartes compteur n'exploitent que le nombre de lignes.
     *
     * @param array<string, mixed> $fields Champs bruts de la Feature (format API Jira)
     */
    private function startDate(?Feature $collection, array $fields): ?string
    {
        $started = $collection?->getSelfAsIssue()?->getFirstInProgressDate(false);
        if ($started instanceof \DateTime) {
            return $this->toYmd($started->format(\DateTime::ATOM));
        }

        return $this->toYmd(is_string($fields['created'] ?? null) ? $fields['created'] : null);
    }

    /**
     * Date de CLÔTURE de la Feature (colonne « Clôture »), au format Y-m-d, ou null si non terminée.
     *
     * Même principe que startDate() : la date réelle vient du changelog, seule source fiable ici. Jira ne
     * renseigne pas `resolutiondate` sur la plupart des Features terminées (le champ dépend de l'écran de
     * transition du workflow), et le champ `statuscategorychangedate` n'existe pas sur cette instance :
     * le repli brut ne vaut donc que pour le comptage seul, où aucune collection n'est chargée.
     *
     * @param array<string, mixed> $fields Champs bruts de la Feature (format API Jira)
     */
    private function closeDate(?Feature $collection, array $fields): ?string
    {
        $done = $collection?->getSelfAsIssue()?->getDoneDate(false);
        if ($done instanceof \DateTime) {
            return $this->toYmd($done->format(\DateTime::ATOM));
        }

        return $this->toYmd(is_string($fields['resolutiondate'] ?? null) ? $fields['resolutiondate'] : null);
    }

    /**
     * Restreint les tickets enfants d'une Feature à ceux imputables à l'équipe, c.-à-d. portés par SON
     * projet Jira : une Feature du projet parent agrège les contributions de plusieurs équipes, chacune
     * travaillant dans son propre projet.
     *
     * Collection absente (Jira inaccessible, ou mode comptage seul) : aucun ticket, la Feature reste
     * listée avec un détail vide plutôt que de casser l'onglet.
     *
     * @param  Feature|null $collection Collection résolue de la Feature, ou null
     * @param  string       $projectKey Clé du projet de l'équipe analysée (ex: "ABC")
     * @return Issue[]                  Tickets enfants de l'équipe uniquement
     */
    private function teamIssues(?Feature $collection, string $projectKey): array
    {
        if ($collection === null) {
            return [];
        }

        return array_values(array_filter(
            $collection->getIssues(),
            static fn (Issue $issue): bool => $issue->getProjectKey() === $projectKey
        ));
    }

    /**
     * Instancie la collection d'une Feature (ticket Feature + tickets enfants). Point d'extension
     * isolant l'accès Jira (surchargé dans les tests).
     *
     * @param string $key Key de la Feature (ex: ABC-123)
     */
    protected function newCollection(string $key): Feature
    {
        return new Feature($key);
    }

    /**
     * URL Jira d'une Feature (page browse).
     *
     * @param string $key Key de la Feature (ex: ABC-123)
     */
    private function featureUrl(string $key): string
    {
        return JiraUrl::browse($key);
    }
}
