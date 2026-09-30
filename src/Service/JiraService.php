<?php

namespace App\Service;

use App\Exception\JiraCredentialsException;
use App\Model\Config;
use App\Model\JiraFields;
use App\Service\Http\CurlTransport;
use App\Service\Http\JiraHttpClient;
use App\Support\ErrorLogger;
use Exception;

/**
 * Client de l'API REST Jira (Cloud + Agile) : recherche d'issues, versions, sprints, boards, et
 * écritures (mise à jour de champs, commentaires). Centralise l'authentification et les appels cURL.
 */
class JiraService
{
    // API /rest/api/3/search dépréciée ! Utiliser /rest/api/3/search/jql à la place
    public const API_URL_SEARCH = '/rest/api/3/search/jql';
    public const API_URL_VERSION = '/rest/api/2/version';
    public const API_URL_PROJECT_VERSIONS = '/rest/api/2/project/{project_id}/versions';
    public const API_URL_PROJECT_STATUSES = '/rest/api/2/project/{project_id}/statuses';
    public const API_URL_FETCH_ISSUES = '/rest/api/3/issue/bulkfetch';
    public const API_URL_PROJECT_SEARCH = '/rest/api/3/project/search';
    public const API_URL_ISSUE = '/rest/api/3/issue';
    // API v2 : corps `body` en texte brut (l'API v3 imposerait le format ADF).
    public const API_URL_ISSUE_COMMENT = '/rest/api/2/issue/{issueIdOrKey}/comment';
    public const AGILE_API_BOARD = '/rest/agile/1.0/board';
    public const AGILE_API_SPRINT = '/rest/agile/1.0/sprint';
    // Endpoint interne du "Sprint Report" natif de Jira — NON documenté officiellement par
    // Atlassian (aucune garantie de stabilité). Seul moyen connu de retrouver les tickets
    // retirés d'un sprint et jamais réintégrés (invisibles via l'API Agile publique).
    public const GREENHOPPER_API_SPRINT_REPORT = '/rest/greenhopper/1.0/rapid/charts/sprintreport';

    /**
     * Préfixe de signature apposé sur tout commentaire d'action poussée par l'app.
     * Permet de distinguer dans l'historique Jira les modifications faites via
     * OPALE de celles faites manuellement (l'API attribue l'action au porteur du token).
     */
    public const OPALE_COMMENT_PREFIX = 'Modification poussée via OPALE : ';

    /** Nombre max d'issues acceptées par appel à l'API Agile « move issues to sprint ». */
    private const MAX_ISSUES_PER_SPRINT_MOVE = 50;

    /**
     * Champs Jira nécessaires au calcul des métriques d'un sprint.
     *
     * Partagés par getIssuesBySprint() (composition courante du sprint) et getIssuesByKeys() (tickets retirés du
     * sprint) : les deux alimentent le MÊME pipeline SprintTicketBuilder, donc toute divergence de champs
     * produirait des tickets partiellement reconstruits (Story Points ou étiquettes manquants).
     *
     * @var list<string>
     */
    private const SPRINT_ISSUE_FIELDS = [
        'summary',
        'status',
        'parent',
        'labels',
        'priority',
        'created',
        'resolutiondate',
        'issuetype',
        JiraFields::STORY_POINTS,
        JiraFields::STORY_POINTS_FINAL,
        JiraFields::STORY_POINTS_REMAINING,
    ];

    private string $baseUrl;
    private string $email;
    private string $token;
    private bool $isDemo = false;
    private JiraHttpClient $httpClient;

    /**
     * Identifiants Jira déjà validés durant ce processus PHP — donc durant cette requête HTTP.
     *
     * Statique et non d'instance : l'application construit un JiraService à de nombreux endroits (un par
     * collection via AbstractIssueCollection::bootServices(), un par repository, un par service de cache), tous
     * porteurs des MÊMES identifiants lus depuis l'environnement. Un drapeau d'instance imposait donc un
     * GET /myself de pré-vol par instance, soit jusqu'à une vingtaine d'allers-retours inutiles sur une page qui
     * construit autant de collections.
     */
    private static bool $areCredentialsVerified = false;

    /**
     * Tickets enfants préchargés en lot, indexés par key de Feature parente.
     *
     * Statique pour la même raison que self::$areCredentialsVerified : chaque collection construit son
     * propre JiraService via AbstractIssueCollection::bootServices(), une table d'instance ne serait donc
     * jamais partagée entre les Features d'un même onglet — or c'est précisément entre elles qu'il faut
     * mutualiser.
     *
     * Une clé PRÉSENTE avec un tableau vide signifie « préchargée, aucun enfant » ; une clé ABSENTE
     * signifie « jamais préchargée », et déclenche la requête unitaire historique.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $prefetchedChildren = [];

    /**
     * Métadonnées de Features préchargées en lot, indexées par key.
     *
     * Même motif et mêmes raisons que self::$prefetchedChildren : mutualiser entre les Features d'un
     * même onglet, que leurs JiraService respectifs ne partagent pas.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $prefetchedFeatureDetails = [];

    /**
     * Charge les identifiants Jira (URL, e-mail, token) depuis l'environnement.
     *
     * @throws JiraCredentialsException Si une variable d'environnement Jira requise est absente
     */
    public function __construct()
    {
        $this->baseUrl = $_ENV['JIRA_BASE_URL'] ?? throw new JiraCredentialsException('JIRA_BASE_URL manquant dans le fichier .env');
        $this->email   = $_ENV['JIRA_EMAIL'] ?? throw new JiraCredentialsException('JIRA_EMAIL manquant dans le fichier .env');
        $this->token   = $_ENV['JIRA_API_TOKEN'] ?? throw new JiraCredentialsException('JIRA_API_TOKEN manquant dans le fichier .env');
        $this->isDemo  = Config::isDemo();

        // Client HTTP bas niveau (cURL réel + résilience). Le bypass SSL du mode démo est porté
        // par le transport ; l'authentification Basic utilise email:token.
        $this->httpClient = new JiraHttpClient(
            new CurlTransport($this->email . ':' . $this->token, $this->isDemo)
        );
    }

    /**
     * Vérifie la validité des identifiants Jira via l'endpoint « myself ».
     *
     * Le résultat est mémorisé pour tout le processus PHP (cf. self::$areCredentialsVerified) : la vérification
     * n'a donc lieu qu'une fois par requête HTTP, avant le premier appel réel, quel que soit le nombre de
     * JiraService construits. Un échec ne mémorise rien : la vérification sera rejouée au prochain appel.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-myself/#api-rest-api-3-myself-get
     *
     * @throws JiraCredentialsException Si les identifiants Jira sont invalides
     */
    public function checkCredentials(): void
    {
        // Force pour 1er check, pour éviter boucle infinie entre request() et checkCredentials()
        self::$areCredentialsVerified = true;

        try {
            $url = $this->baseUrl . '/rest/api/3/myself';
            $response = $this->request($url);

            if (!isset($response['accountId'])) {
                self::$areCredentialsVerified = false;
                throw new JiraCredentialsException();
            }

            self::$areCredentialsVerified = true;
        } catch (JiraCredentialsException $e) {
            self::$areCredentialsVerified = false;
            throw $e;
        } catch (Exception $e) {
            self::$areCredentialsVerified = false;
            throw new JiraCredentialsException(JiraCredentialsException::USER_MESSAGE, $e);
        }
    }

    /**
     * Récupère une fixVersion Jira par son ID numérique.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-project-versions/#api-rest-api-2-version-id-get
     */
    public function getVersionById(string $versionId): array
    {
        $url = $this->baseUrl . self::API_URL_VERSION . '/' . $versionId;

        return $this->request($url);
    }

    /**
     * Récupère les fixVersions d'un projet Jira (par ID ou clé).
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-project-versions/#api-rest-api-2-project-project-id-versions-get
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function getVersionsByProject(string $projectKey): array
    {
        $url = $this->baseUrl . self::API_URL_PROJECT_VERSIONS;
        $url = str_replace('{project_id}', $projectKey, $url);

        return $this->request($url);
    }

    /**
     * Récupère les statuts réels d'un projet Jira, dédupliqués par nom.
     *
     * L'API renvoie un statut par type d'issue ; un même statut (ex: "En cours")
     * peut apparaître sur plusieurs types. On aplatit et déduplique par nom, en
     * conservant la catégorie Jira (statusCategory.key : new / indeterminate / done)
     * qui sert d'heuristique de répartition dans l'IHM de configuration.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-projects/#api-rest-api-2-project-projectidorkey-statuses-get
     *
     * @return array<int, array{name: string, category: string}>
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function getStatusesByProject(string $projectKey): array
    {
        $url = $this->baseUrl . str_replace('{project_id}', urlencode($projectKey), self::API_URL_PROJECT_STATUSES);

        $result = $this->request($url);

        $byName = [];
        foreach ($result as $issueType) {
            foreach ($issueType['statuses'] ?? [] as $status) {
                $name = $status['name'] ?? '';
                if ($name === '' || isset($byName[$name])) {
                    continue;
                }
                $byName[$name] = [
                    'name'     => $name,
                    'category' => $status['statusCategory']['key'] ?? '',
                ];
            }
        }

        return array_values($byName);
    }

    /**
     * Retourne les noms des types de tickets disponibles sur un projet Jira.
     *
     * Réutilise l'endpoint /rest/api/2/project/{key}/statuses : sa réponse est un
     * tableau groupé par type de ticket, chaque entrée portant le nom du type
     * (clé `name`). On en extrait les noms, dédoublonnés, dans l'ordre Jira.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-projects/#api-rest-api-2-project-projectidorkey-statuses-get
     *
     * @return string[] Ex: ['Story', 'Bug', 'Spike']
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function getIssueTypesByProject(string $projectKey): array
    {
        $url = $this->baseUrl . str_replace('{project_id}', urlencode($projectKey), self::API_URL_PROJECT_STATUSES);

        $result = $this->request($url);

        $names = [];
        foreach ($result as $issueType) {
            $name = $issueType['name'] ?? '';
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Nettoie un identifiant Jira (ID numérique ou clé « ABC-123 ») avant son interpolation dans une
     * requête JQL.
     *
     * Ne conserve que les caractères d'un identifiant légitime : tout guillemet, espace, parenthèse ou
     * opérateur est retiré, ce qui empêche d'ouvrir une clause supplémentaire depuis une valeur saisie
     * (les identifiants proviennent de formulaires : /release, /feature).
     */
    private function sanitizeJqlIdentifier(string $identifier): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $identifier) ?? '';
    }

    /**
     * Récupère les IDs (et champs de base) des issues d'une fixVersion via JQL `fixVersion = {id}`.
     */
    public function getIssuesIdsByVersion(string $versionId): array
    {
        $jql = 'fixVersion = ' . $this->sanitizeJqlIdentifier($versionId);

        $fields = [
            'summary',
            'issuetype',
            'assignee',
            'priority',
            'status',
            'created',
            'resolutiondate'
        ];

        $result = $this->callSearchApiGet($jql, $fields);

        return $result;
    }

    /**
     * Récupère le détail (avec changelog) d'un lot d'issues à partir de leurs IDs.
     */
    public function getIssuesDetails(array $issuesIds): array
    {
        // Si on passe des int ça ne fonctionnera pas, on force en string
        if (!is_string($issuesIds[0])) {
            $issuesIds = array_map('strval', array_values($issuesIds));
        }

        $safeIds = array_map(fn (string $id): string => $this->sanitizeJqlIdentifier($id), $issuesIds);

        $payload = [
            'jql' => 'id IN (' . implode(',', $safeIds) . ')',
            'fields' => [
                'summary',
                'project',
                'priority',
                'status',
                'created',
                'resolutiondate',
                'issuetype'
            ],
            'expand' => 'changelog',
            'maxResults' => count($issuesIds)
        ];

        $issues = $this->callSearchApiPost($payload);

        foreach ($issues as &$issue) {
            $issue = $this->completeIssueChangelog($issue);
        }
        unset($issue);

        return $issues;
    }

    /**
     * Exécute une recherche JQL (GET) et retourne TOUTES les issues correspondantes.
     *
     * ⚠️ L'endpoint plafonne chaque page à **100 issues**, quelle que soit la valeur demandée
     * (`maxResults=200` est silencieusement ramené à 100), et signale la suite par un curseur
     * `nextPageToken`. Sans cette boucle, toute recherche dépassant 100 résultats était tronquée
     * SANS erreur : des Features ou des Releases disparaissaient simplement de l'analyse.
     *
     * Pagination par CURSEUR, à ne pas confondre avec {@see fetchAllPaginated()} qui pagine en
     * `startAt` (API Agile et endpoints v2) : les deux mécaniques ne sont pas interchangeables.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issue-search/#api-rest-api-3-search-jql-get
     *
     * @param  int   $maxResults Taille de page demandée (plafonnée à 100 par Jira)
     * @return array             Issues de toutes les pages, concaténées
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function callSearchApiGet(string $jql, array $fields = [], int $maxResults = 100): array
    {
        $all   = [];
        $token = null;

        do {
            $params = [
                'jql'        => $jql,
                'fields'     => $fields ? implode(',', $fields) : '',
                'maxResults' => $maxResults,
            ];
            if ($token !== null) {
                $params['nextPageToken'] = $token;
            }

            $result = $this->request($this->baseUrl . self::API_URL_SEARCH . '?' . http_build_query($params));

            if (!isset($result['issues'])) {
                // Réponse brute journalisée, jamais renvoyée au client (elle peut peser plusieurs Mo).
                $error = new Exception('callSearchApiGet(...) : réponse Jira invalide (' . self::API_URL_SEARCH . ').');
                ErrorLogger::critical($error, 'Réponse brute : ' . print_r($result, true));
                throw $error;
            }

            $all   = array_merge($all, $result['issues']);
            $token = $result['nextPageToken'] ?? null;

            // Garde-fou anti boucle infinie : un curseur qui ne ramène plus rien arrête le parcours.
        } while ($token !== null && $result['issues'] !== []);

        return $all;
    }

    /**
     * Exécute une recherche JQL (POST) et retourne TOUTES les issues correspondantes.
     *
     * ⚠️ Même plafond de 100 par page que la variante GET, `maxResults` du payload ne le lève PAS :
     * une Release de 133 tickets n'en remontait que 100, et les cumuls affichés étaient donc faux.
     * Le curseur `nextPageToken` est réinjecté dans le payload à chaque tour.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issue-search/#api-rest-api-3-search-jql-post
     *
     * @param  array $payload Corps de recherche (jql, fields, expand, maxResults…)
     * @return array          Issues de toutes les pages, concaténées
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function callSearchApiPost(array $payload): array
    {
        $url  = $this->baseUrl . self::API_URL_SEARCH;
        $all  = [];
        $page = $payload;

        do {
            $result = $this->request($url, json_encode($page, JSON_THROW_ON_ERROR));

            if (!isset($result['issues'])) {
                // Réponse brute journalisée, jamais renvoyée au client (elle peut peser plusieurs Mo).
                $error = new Exception('callSearchApiPost(...) : réponse Jira invalide (' . self::API_URL_SEARCH . ').');
                ErrorLogger::critical($error, 'Réponse brute : ' . print_r($result, true));
                throw $error;
            }

            $all   = array_merge($all, $result['issues']);
            $token = $result['nextPageToken'] ?? null;

            $page['nextPageToken'] = $token;

            // Garde-fou anti boucle infinie : un curseur qui ne ramène plus rien arrête le parcours.
        } while ($token !== null && $result['issues'] !== []);

        return $all;
    }

    /**
     * Recherche des projets Jira par mot-clé.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-project-search/
     *
     * @throws \Exception Si la réponse de recherche Jira est invalide
     */
    public function searchProjects(string $query): array
    {
        $params = http_build_query([
            'query' => $query,
            'maxResults' => 10
        ]);

        $url = $this->baseUrl . self::API_URL_PROJECT_SEARCH . '?' . $params;

        $result = $this->request($url);

        if (!isset($result['values'])) {
            // Réponse brute journalisée, jamais renvoyée au client (elle peut peser plusieurs Mo).
            $error = new \Exception('searchProjects(...) : réponse Jira invalide (' . self::API_URL_PROJECT_SEARCH . ').');
            ErrorLogger::critical($error, 'Réponse brute : ' . print_r($result, true));
            throw $error;
        }

        return $result['values'];
    }

    /**
     * Précharge en UNE recherche les métadonnées de plusieurs Features, pour que les getIssueById()
     * suivants soient servis sans aller-retour Jira.
     *
     * Remplace N requêtes unitaires sérialisées par une recherche `key IN (...)`. Cas réel ayant motivé
     * ce lot : une fin de PI concentrant 53 Features clôturées la même semaine, dont le chargement à
     * cache froid dépassait max_execution_time.
     *
     * La recherche de fenêtre (getFeaturesByProject()) n'a volontairement PAS été enrichie pour servir
     * ce besoin : elle alimente aussi le sélecteur de la page /feature, à qui descriptions et changelogs
     * ne servent à rien.
     *
     * @param list<string> $featureKeys Keys des Features (ex: ["ABC-1", "ABC-2"])
     *
     * @throws Exception Si une recherche Jira échoue
     */
    public function prefetchFeatureDetails(array $featureKeys): void
    {
        $featureKeys = array_values(array_unique(array_filter($featureKeys, static fn (string $k): bool => $k !== '')));
        if ($featureKeys === []) {
            return;
        }

        foreach (array_chunk($featureKeys, 100) as $chunk) {
            $safeKeys = array_map([$this, 'sanitizeJqlIdentifier'], $chunk);

            $payload = [
                // Mêmes champs et expansions que la requête unitaire de Feature::loadCollectionData(),
                // sans quoi une Feature hydratée depuis le préchargement serait incomplète.
                'jql'    => 'key IN (' . implode(', ', $safeKeys) . ')',
                'fields' => [
                    'summary',
                    'status',
                    'issuetype',
                    'priority',
                    'description',
                    'created',
                    'duedate',
                    JiraFields::JALON,
                    JiraFields::EPIC_NAME,
                    JiraFields::TEAM,
                    JiraFields::PLANNING_INTERVAL,
                ],
                'expand'     => 'renderedFields,changelog',
                'maxResults' => 100,
            ];

            foreach ($this->callSearchApiPost($payload) as $issue) {
                $key = (string) ($issue['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                self::$prefetchedFeatureDetails[$key] = $this->completeIssueChangelog($issue);
            }
        }
    }

    /**
     * Récupère une issue Jira par son ID numérique.
     *
     * Sert d'abord le résultat du préchargement groupé ({@see prefetchFeatureDetails()}) quand il couvre
     * cette Feature ; sinon, requête unitaire — comportement historique dont dépend la page /feature.
     *
     * Le changelog est complété dans les DEUX cas. Il ne l'était auparavant dans aucun : une Feature
     * très éditée voyait ses métriques propres calculées sur un historique tronqué par l'API. Compléter
     * un seul des deux chemins ferait diverger l'onglet et la page /feature, qui partagent le cache des
     * métriques — le premier consulté imposerait ses chiffres à l'autre.
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issues/#api-rest-api-3-issue-issueidorkey-get
     *
     * @param string $issueId Identifiant numérique de l'issue
     * @param array  $fields  Liste des champs à retourner (vide = tous)
     * @param array  $expand  Liste des expansions Jira (ex: ['renderedFields', 'changelog'])
     */
    public function getIssueById(string $issueId, array $fields = [], array $expand = []): array
    {
        if (array_key_exists($issueId, self::$prefetchedFeatureDetails)) {
            return self::$prefetchedFeatureDetails[$issueId];
        }

        $params = [];

        if ($fields) {
            $params['fields'] = implode(',', $fields);
        }
        if ($expand) {
            $params['expand'] = implode(',', $expand);
        }

        $query = $params ? '?' . http_build_query($params) : '';
        $url   = $this->baseUrl . self::API_URL_ISSUE . '/' . $issueId . $query;

        return $this->completeIssueChangelog($this->request($url));
    }

    /**
     * Précharge en UNE recherche les tickets enfants de plusieurs Features, pour que les
     * getIssuesByParent() suivants soient servis sans aller-retour Jira.
     *
     * Remplace N requêtes `parent = {key}` sérialisées par une requête `parent IN (...)`. Sur l'onglet
     * Features à cache froid, c'est ce qui faisait dépasser max_execution_time : chaque Feature payait sa
     * propre latence réseau, sur un serveur intégré mono-processus.
     *
     * Les clés sont découpées par lots de 100 : un JQL `IN` accepte un nombre limité de valeurs, et une
     * fenêtre large peut porter plusieurs dizaines de Features.
     *
     * Le changelog est complété ticket par ticket, exactement comme dans getIssuesByParent() : l'endpoint
     * d'historique est mono-issue, il n'y a rien à grouper là: seul le nombre de RECHERCHES diminue.
     *
     * @param list<string> $parentKeys Keys des Features parentes (ex: ["ABC-1", "ABC-2"])
     * @param string[]     $issueTypes Types d'issues à conserver ; vide = tous les types
     *
     * @throws Exception Si une recherche Jira échoue
     */
    public function prefetchChildrenByParent(array $parentKeys, array $issueTypes = []): void
    {
        $parentKeys = array_values(array_unique(array_filter($parentKeys, static fn (string $k): bool => $k !== '')));
        if ($parentKeys === []) {
            return;
        }

        // Toutes les clés demandées sont marquées d'emblée : une Feature sans enfant doit rester
        // « préchargée avec zéro enfant », et non retomber sur une requête unitaire.
        //
        // Accumulé LOCALEMENT, puis publié d'un bloc : une recherche qui échoue en cours de route ne
        // doit laisser aucune clé marquée, sinon les Features du lot interrompu seraient servies avec
        // zéro enfant — une perte silencieuse de tickets, là où le repli unitaire les aurait servies.
        $children = array_fill_keys($parentKeys, []);

        $typeClause = $this->buildIssueTypeJqlClause($issueTypes);

        foreach (array_chunk($parentKeys, 100) as $chunk) {
            $safeKeys = array_map([$this, 'sanitizeJqlIdentifier'], $chunk);
            $jql      = 'parent IN (' . implode(', ', $safeKeys) . ')';
            if ($typeClause !== '') {
                $jql .= ' AND ' . $typeClause;
            }

            $payload = [
                'jql' => $jql,
                // Mêmes champs que getIssuesByParent(), plus `parent` : c'est lui qui permet de répartir
                // les résultats entre les Features (la requête groupée les renvoie mélangés).
                'fields' => [
                    'summary',
                    'project',
                    'priority',
                    'status',
                    'created',
                    'resolutiondate',
                    'issuetype',
                    'labels',
                    'parent',
                    JiraFields::STORY_POINTS,
                ],
                'expand'     => 'changelog',
                'maxResults' => 100,
            ];

            foreach ($this->callSearchApiPost($payload) as $issue) {
                $parentKey = (string) ($issue['fields']['parent']['key'] ?? '');
                if ($parentKey === '' || !array_key_exists($parentKey, $children)) {
                    continue;
                }

                $children[$parentKey][] = $this->completeIssueChangelog($issue);
            }
        }

        foreach ($children as $key => $issues) {
            self::$prefetchedChildren[$key] = $issues;
        }
    }

    /**
     * Récupère les issues enfants d'une Feature via JQL parent = {parentId}.
     * Embarque le changelog pour le calcul des timelines.
     *
     * Sert d'abord le résultat du préchargement groupé ({@see prefetchChildrenByParent()}) quand il
     * couvre cette Feature ; sinon, requête unitaire — comportement historique, dont dépendent les
     * appelants hors onglet Features (page /feature).
     *
     * @param string   $parentId   ID numérique de la Feature parente
     * @param string[] $issueTypes Types d'issues à conserver ; vide = tous les types
     * @return array               Tableau d'issues brutes (format API Jira)
     */
    public function getIssuesByParent(string $parentId, array $issueTypes = []): array
    {
        // Servi par le préchargement groupé si cette Feature en faisait partie. array_key_exists et non
        // isset : une Feature préchargée SANS enfant vaut [] et doit être servie telle quelle, sinon on
        // relancerait une requête unitaire pour chacune — le N+1 qu'on supprime.
        if (array_key_exists($parentId, self::$prefetchedChildren)) {
            return self::$prefetchedChildren[$parentId];
        }

        $jql        = 'parent = ' . $this->sanitizeJqlIdentifier($parentId);
        $typeClause = $this->buildIssueTypeJqlClause($issueTypes);
        if ($typeClause !== '') {
            $jql .= ' AND ' . $typeClause;
        }

        $payload = [
            'jql'    => $jql,
            // labels + Story Points : alimentent le détail par Feature (total SP, comptage des bugs et leur
            // ventilation par étiquette, camembert des types) sans requête Jira supplémentaire.
            'fields' => [
                'summary',
                'project',
                'priority',
                'status',
                'created',
                'resolutiondate',
                'issuetype',
                'labels',
                JiraFields::STORY_POINTS,
            ],
            'expand'     => 'changelog',
            'maxResults' => 100,
        ];

        $issues = $this->callSearchApiPost($payload);

        foreach ($issues as &$issue) {
            $issue = $this->completeIssueChangelog($issue);
        }
        unset($issue);

        return $issues;
    }

    /**
     * Récupère les issues liées à une issue donnée via la fonction JQL `linkedIssues()`.
     *
     * Utilisé pour les issues de type "Release" : leurs tickets ne sont pas des subtasks
     * (champ `parent`) mais des liens typés (issuelinks) — par défaut "Composition"
     * (inward: "is composed of" / outward: "composes"), mais d'autres types
     * peuvent être utilisés selon la configuration Jira du projet.
     *
     * Embarque le changelog pour le calcul des timelines.
     *
     * @param string      $issueKey     Key Jira (ex: "ABC-123")
     * @param string|null $linkTypeName Nom du type de lien à filtrer (ex: "is composed of") ; null = tous types
     * @return array                    Tableau d'issues brutes (format API Jira)
     */
    public function getIssuesByLinkedIssue(string $issueKey, ?string $linkTypeName = null): array
    {
        $safeKey = $this->sanitizeJqlIdentifier($issueKey);

        if ($linkTypeName !== null && $linkTypeName !== '') {
            $safeLinkType = str_replace('"', '\\"', $linkTypeName);
            $jql = 'issue in linkedIssues("' . $safeKey . '", "' . $safeLinkType . '")';
        } else {
            $jql = 'issue in linkedIssues("' . $safeKey . '")';
        }

        $payload = [
            'jql'        => $jql,
            'fields'     => ['summary', 'project', 'priority', 'status', 'created', 'resolutiondate', 'issuetype'],
            'expand'     => 'changelog',
            'maxResults' => 100,
        ];

        $issues = $this->callSearchApiPost($payload);

        foreach ($issues as &$issue) {
            $issue = $this->completeIssueChangelog($issue);
        }
        unset($issue);

        return $issues;
    }

    /**
     * Récupère les issues de type "Release" d'un projet Jira (créées dans l'année).
     *
     * Un filtre optionnel sur le champ Équipe (customfield_10244) permet de ne conserver
     * que les Releases rattachées à une équipe donnée : indispensable quand un projet parent
     * porte les Releases de plusieurs équipes (sinon on ramène celles de toutes les équipes).
     *
     * @param string $projectKey Clé du projet (ex: "ABC")
     * @param string $teamName   Nom d'équipe (champ Équipe) pour filtrer, ou '' pour tout récupérer
     * @return array Tableau d'issues brutes (format API Jira search)
     */
    public function getReleasesByProject(string $projectKey, string $teamName = ''): array
    {
        // Clé de projet interpolée HORS guillemets : filtrage strict (un simple retrait des guillemets
        // laisserait passer les espaces, donc une clause JQL supplémentaire).
        $safeProject = $this->sanitizeJqlIdentifier($projectKey);

        $jql = 'project = ' . $safeProject
            . ' AND issuetype = Release'
            . ' AND created >= -365d';

        // Filtre Équipe poussé dans le JQL : ne ramène que les Releases de l'équipe ciblée.
        if ($teamName !== '') {
            $safeTeam = str_replace(['"', "\r", "\n"], '', $teamName);
            $jql .= ' AND ' . JiraFields::toJql(JiraFields::TEAM) . ' = "' . $safeTeam . '"';
        }

        $jql .= ' ORDER BY created DESC';

        // created / resolutiondate : dates de démarrage et de clôture d'une issue Release
        // (une issue Release n'a pas de startDate/releaseDate comme une fixVersion).
        // updated : date de dernière modification, utilisée pour trier la dropdown /release.
        $fields = ['summary', 'status', 'project', 'issuetype', 'created', 'resolutiondate', 'updated'];

        return $this->callSearchApiGet($jql, $fields, 100);
    }

    /**
     * Récupère les 100 dernières Features d'un projet Jira, sur un an maximum.
     *
     * Un filtre optionnel sur le champ Équipe (customfield_10244) permet de ne conserver
     * que les Features rattachées à une équipe donnée : indispensable quand un projet parent
     * porte les Features de plusieurs équipes (sinon on ramène celles de toutes les équipes).
     *
     * @param string $projectKey Clé du projet (ex: "ABC")
     * @param string $teamName   Nom d'équipe (champ Équipe) pour filtrer, ou '' pour tout récupérer
     * @param string   $fromDate           Début de la fenêtre d'analyse (Y-m-d), '' = aucune fenêtre
     * @param string   $toDate             Fin de la fenêtre d'analyse (Y-m-d), '' = aucune fenêtre
     * @param string[] $transitionStatuses Statuts de mise en chantier / clôture, déjà croisés avec ceux
     *                                     réellement déclarés sur le projet par l'appelant
     * @return array Tableau d'issues brutes (format API Jira search)
     */
    public function getFeaturesByProject(string $projectKey, string $teamName = '', string $fromDate = '', string $toDate = '', array $transitionStatuses = []): array
    {
        // Clé de projet interpolée HORS guillemets : filtrage strict (un simple retrait des guillemets
        // laisserait passer les espaces, donc une clause JQL supplémentaire).
        $safeProject = $this->sanitizeJqlIdentifier($projectKey);

        $jql = 'project = ' . $safeProject . ' AND issuetype = Feature';

        // Sans fenêtre d'analyse (sélecteur de la page /feature), simple borne de fraîcheur : la liste
        // sert à choisir une Feature récente, pas à couvrir une période.
        $window = $this->featureWindowJql($fromDate, $toDate, $transitionStatuses);
        $jql .= $window !== '' ? $window : ' AND created >= -365d';

        // Filtre Équipe poussé dans le JQL : ne ramène que les Features de l'équipe ciblée.
        if ($teamName !== '') {
            $safeTeam = str_replace(['"', "\r", "\n"], '', $teamName);
            $jql .= ' AND ' . JiraFields::toJql(JiraFields::TEAM) . ' = "' . $safeTeam . '"';
        }

        $jql .= ' ORDER BY updated DESC';

        // created / resolutiondate : dates de démarrage et de clôture de la Feature (bornes de fenêtre
        // et colonnes Démarrage/Clôture de l'onglet Features de /team-metrics). project : projet porteur
        // (peut être le projet parent fusionné), transmis au deep-link d'analyse.
        $fields = [
            'summary',
            'status',
            'project',
            'created',
            'resolutiondate',
            JiraFields::PLANNING_INTERVAL,
            JiraFields::TEAM,
        ];

        return $this->callSearchApiGet($jql, $fields, 100);
    }

    /**
     * Clause JQL retenant les Features TRAVAILLÉES pendant la fenêtre [fromDate, toDate] : entrées dans
     * un statut de mise en chantier ou de clôture durant la période.
     *
     * Remplace l'ancienne limite glissante `created >= -365d`, qui filtrait sur un critère sans rapport
     * (« créée dans l'année ») et rendait invisibles les Features plus anciennes pourtant travaillées
     * pendant la fenêtre — sur un cas réel, 32 Features retenues contre 67 réellement concernées.
     *
     * ⚠️ Les libellés passés ici DOIVENT exister dans l'instance : JQL valide chaque valeur, et un seul
     * nom inconnu renvoie une 400 qui fait échouer la requête entière. L'appelant ne transmet donc que
     * des statuts croisés avec ceux réellement déclarés sur le projet (cf. JiraStatusCacheService).
     *
     * Cette sélection FAIT FOI : elle n'est pas rejouée côté PHP. Une Feature démarrée avant la fenêtre
     * et toujours en cours pendant y figure donc légitimement — elle a bien été travaillée sur la période.
     *
     * Liste vide (statuts indisponibles) : repli sur `status CHANGED DURING (...)`, qui n'embarque aucune
     * valeur à valider. Ce repli est plus large — il retient aussi une Feature qui n'a bougé qu'en
     * affinage — mais il vaut mieux quelques lignes en trop qu'un onglet vide.
     *
     * Dates non conformes à Y-m-d : chaîne vide, donc aucune restriction plutôt qu'un JQL approximatif.
     * Le format étant ainsi vérifié, l'interpolation entre guillemets est sûre.
     *
     * @param string[] $transitionStatuses Statuts marquant la mise en chantier ou la clôture
     */
    private function featureWindowJql(string $fromDate, string $toDate, array $transitionStatuses): string
    {
        $isYmd = static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;

        if (!$isYmd($fromDate) || !$isYmd($toDate)) {
            return '';
        }

        $window   = '("' . $fromDate . '", "' . $toDate . ' 23:59")';
        $statuses = $this->quoteJqlValues($transitionStatuses);

        return $statuses !== ''
            ? ' AND status CHANGED TO (' . $statuses . ') DURING ' . $window
            : ' AND status CHANGED DURING ' . $window;
    }

    /**
     * Met une liste de valeurs littérales entre guillemets pour une clause JQL de type `IN` / `CHANGED TO`.
     *
     * Les valeurs sont confinées par les guillemets ; on en retire donc seulement les caractères capables
     * de les refermer, comme pour le nom d'équipe. Un filtre strict à la sanitizeJqlIdentifier() casserait
     * les libellés légitimes, qui contiennent espaces, accents et parenthèses (« Terminé(e) »).
     *
     * @param string[] $values
     */
    private function quoteJqlValues(array $values): string
    {
        $quoted = [];
        foreach ($values as $value) {
            $clean = str_replace(['"', "\r", "\n"], '', $value);
            if ($clean !== '') {
                $quoted[] = '"' . $clean . '"';
            }
        }

        return implode(', ', $quoted);
    }

    /**
     * Complète le changelog d'une issue si la Search API l'a tronqué.
     *
     * L'API Jira Search (`/search` avec `expand=changelog`) ne retourne qu'une seule page
     * de l'historique. Pour les issues longues / très éditées, les anciennes transitions
     * de statut peuvent être absentes.
     */
    private function completeIssueChangelog(array $issue): array
    {
        $changelog = $issue['changelog'] ?? [];

        $total = (int) ($changelog['total'] ?? 0);
        $returnedCount = count($changelog['histories'] ?? []);

        // Changelog déjà complet
        if ($total <= $returnedCount) {
            return $issue;
        }

        $issueId = (string) ($issue['id'] ?? '');

        if ($issueId === '') {
            return $issue;
        }

        $issue['changelog']['histories'] = $this->fetchAllIssueChangelog($issueId);

        return $issue;
    }

    /**
     * Récupère l'intégralité du changelog d'une issue via l'endpoint dédié.
     */
    private function fetchAllIssueChangelog(string $issueId): array
    {
        $allEntries = [];

        $startAt = 0;
        $maxResults = 100;

        do {
            $url = $this->baseUrl
                . self::API_URL_ISSUE . '/' . $issueId . '/changelog'
                . '?startAt=' . $startAt
                . '&maxResults=' . $maxResults;

            $response = $this->request($url);

            $values = $response['values'] ?? [];
            $allEntries = array_merge($allEntries, $values);

            $isLast = $response['isLast'] ?? true;
            $startAt += count($values);

            // Garde-fou anti boucle infinie
            if (empty($values)) {
                break;
            }
        } while (!$isLast);

        return $allEntries;
    }

    /**
     * Récupère les Features d'un projet rattachées à un PI donné (customfield_11400),
     * avec changelog embarqué pour permettre le calcul des métriques individuelles.
     *
     * Le champ PI peut être stocké en numérique ("8"), en texte ("PI8") ou en select.
     * On envoie la valeur saisie telle quelle, quotée, pour couvrir les trois cas.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  string $piNumber   Valeur PI alphanumérique (ex: "8" ou "PI8")
     * @return array              Tableau d'issues brutes (format API Jira)
     */
    public function getFeaturesByProjectAndPI(string $projectKey, string $piNumber): array
    {
        // Valeur PI interpolée ENTRE guillemets : retirer les guillemets suffit à la confiner.
        $safePi      = str_replace(['"', "\r", "\n"], '', $piNumber);
        // Clé de projet interpolée HORS guillemets : filtrage strict (un simple retrait des guillemets
        // laisserait passer les espaces, donc une clause JQL supplémentaire).
        $safeProject = $this->sanitizeJqlIdentifier($projectKey);

        $payload = [
            'jql' => 'project = ' . $safeProject
                . ' AND issuetype = Feature'
                . ' AND ' . JiraFields::PLANNING_INTERVAL . ' = "' . $safePi . '"'
                . ' ORDER BY key ASC',
            'fields' => [
                'summary',
                'status',
                'created',
                'resolutiondate',
                'priority',
                JiraFields::PLANNING_INTERVAL,
            ],
            'expand'     => 'changelog',
            'maxResults' => 100,
        ];

        return $this->callSearchApiPost($payload);
    }

    /**
     * Récupère les boards Agile rattachés à un projet Jira.
     *
     * @see https://developer.atlassian.com/cloud/jira/software/rest/api-group-board/#api-rest-agile-1-0-board-get
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array              Boards bruts [{id, name, type}, ...]
     */
    public function getBoardsByProject(string $projectKey): array
    {
        $url = $this->baseUrl . self::AGILE_API_BOARD
            . '?projectKeyOrId=' . urlencode($projectKey);

        return $this->fetchAllPaginated($url, 'values');
    }

    /**
     * Récupère les sprints d'un board (board de type Scrum uniquement).
     *
     * @see https://developer.atlassian.com/cloud/jira/software/rest/api-group-board/#api-rest-agile-1-0-board-boardid-sprint-get
     *
     * @param  string $boardId ID du board Agile
     * @return array           Sprints bruts [{id, name, state, startDate, endDate, completeDate}, ...]
     */
    public function getSprintsByBoard(string $boardId): array
    {
        $url = $this->baseUrl . self::AGILE_API_BOARD . '/' . $boardId . '/sprint';

        return $this->fetchAllPaginated($url, 'values');
    }

    /**
     * Récupère les métadonnées d'un sprint (nom, état, dates, objectif).
     *
     * @see https://developer.atlassian.com/cloud/jira/software/rest/api-group-sprint/#api-rest-agile-1-0-sprint-sprintid-get
     *
     * @param  string $sprintId ID du sprint
     * @return array            Données brutes du sprint
     */
    public function getSprintById(string $sprintId): array
    {
        return $this->request($this->baseUrl . self::AGILE_API_SPRINT . '/' . $sprintId);
    }

    /**
     * Récupère le "Sprint Report" natif d'un sprint (tickets retirés, complétés ailleurs...).
     *
     * Endpoint interne non documenté officiellement (celui utilisé par l'IHM Jira pour le
     * burndown/rapport de sprint) : aucune garantie de stabilité contractuelle côté Atlassian.
     * À n'utiliser qu'en enrichissement best-effort, avec dégradation silencieuse à l'appel
     * (cf. Sprint::fetchPuntedTickets()) — ne jamais bloquer une fonctionnalité dessus.
     *
     * @param  string $boardId  ID du board Agile (rapidViewId)
     * @param  string $sprintId ID du sprint
     * @return array<string, mixed> Réponse brute décodée (clé utile : contents.puntedIssues)
     *
     * @throws Exception Si le transport échoue, si le code HTTP est >= 400, ou si la réponse n'est pas du JSON
     */
    public function getSprintReport(string $boardId, string $sprintId): array
    {
        $url = $this->baseUrl . self::GREENHOPPER_API_SPRINT_REPORT
            . '?rapidViewId=' . rawurlencode($boardId) . '&sprintId=' . rawurlencode($sprintId);

        return $this->request($url);
    }

    /**
     * Récupère les issues d'un sprint avec changelog embarqué.
     *
     * Le changelog est nécessaire pour reconstituer l'historique du champ Sprint
     * (détection des tickets ajoutés en cours de sprint) et la timeline des statuts.
     *
     * @see https://developer.atlassian.com/cloud/jira/software/rest/api-group-sprint/#api-rest-agile-1-0-sprint-sprintid-issue-get
     *
     * @param  string   $sprintId   ID du sprint
     * @param  string[] $issueTypes Types d'issues à conserver ; vide = tous les types
     * @return array                Issues brutes (format API Jira)
     */
    public function getIssuesBySprint(string $sprintId, array $issueTypes = []): array
    {
        $fields = implode(',', self::SPRINT_ISSUE_FIELDS);

        $url = $this->baseUrl . self::AGILE_API_SPRINT . '/' . $sprintId . '/issue'
            . '?fields=' . urlencode($fields)
            . '&expand=changelog';

        // Restreint la requête aux types voulus (param jql de l'API Agile).
        $typeClause = $this->buildIssueTypeJqlClause($issueTypes);
        if ($typeClause !== '') {
            $url .= '&jql=' . urlencode($typeClause);
        }

        $issues = $this->fetchAllPaginated($url, 'issues');

        // Complète le changelog si la réponse l'a tronqué (issues très éditées)
        foreach ($issues as &$issue) {
            $issue = $this->completeIssueChangelog($issue);
        }
        unset($issue);

        return $issues;
    }

    /**
     * Récupère un lot d'issues par leurs CLÉS (ABC-123), avec les mêmes champs et le même changelog que
     * getIssuesBySprint().
     *
     * Sert la reconstruction du périmètre d'un sprint à une date passée : les tickets retirés du sprint ne sont plus
     * rattachés à celui-ci et sont donc absents de l'API Agile ; seules leurs clés remontent, via le "Sprint Report"
     * interne (cf. Sprint::loadIssues()). Ils sont ensuite repassés dans le pipeline normal (SprintTicketBuilder),
     * ce qui impose un changelog complet — d'où le refetch plutôt qu'un usage direct du payload du rapport.
     *
     * @param  list<string>                     $issueKeys  Clés d'issues ; liste vide = aucun appel réseau
     * @param  string[]                         $issueTypes Types d'issues à conserver ; vide = tous. Doit refléter le
     *                                                      filtre appliqué par getIssuesBySprint(), sinon un ticket
     *                                                      d'un type exclu des métriques réapparaîtrait dans le
     *                                                      périmètre reconstruit.
     * @return array<int, array<string, mixed>>             Issues brutes (format API Jira)
     *
     * @throws Exception Si la réponse Jira est invalide
     */
    public function getIssuesByKeys(array $issueKeys, array $issueTypes = []): array
    {
        if ($issueKeys === []) {
            return [];
        }

        $safeKeys = array_map(fn (string $key): string => $this->sanitizeJqlIdentifier($key), $issueKeys);

        $jql        = 'key IN (' . implode(',', $safeKeys) . ')';
        $typeClause = $this->buildIssueTypeJqlClause($issueTypes);
        if ($typeClause !== '') {
            $jql .= ' AND ' . $typeClause;
        }

        $payload = [
            'jql'        => $jql,
            'fields'     => self::SPRINT_ISSUE_FIELDS,
            'expand'     => 'changelog',
            'maxResults' => count($safeKeys),
        ];

        $issues = $this->callSearchApiPost($payload);

        // Complète le changelog si la réponse l'a tronqué (issues très éditées)
        foreach ($issues as &$issue) {
            $issue = $this->completeIssueChangelog($issue);
        }
        unset($issue);

        return $issues;
    }

    /**
     * Construit une clause JQL `issuetype in (...)` à partir d'une liste de types.
     *
     * Chaque nom est entre guillemets pour gérer les espaces (ex: "Enabler Story")
     * et les accents (ex: "Tâche"). Les guillemets éventuels dans un nom sont
     * échappés, par sécurité — les noms proviennent d'un fichier de config maîtrisé.
     *
     * @param  string[] $issueTypes
     * @return string Ex: `issuetype in ("Story", "Bug")` ; chaîne vide si liste vide
     */
    private function buildIssueTypeJqlClause(array $issueTypes): string
    {
        if ($issueTypes === []) {
            return '';
        }

        $quoted = array_map(
            static fn (string $type): string => '"' . str_replace('"', '\\"', $type) . '"',
            $issueTypes
        );

        return 'issuetype in (' . implode(', ', $quoted) . ')';
    }

    /**
     * Récupère toutes les pages d'un endpoint Jira paginé.
     *
     * Gère les deux conventions de pagination Jira :
     * - API Agile (boards, sprints) : clé "values" + drapeau "isLast"
     * - Issues de sprint            : clé "issues" + champ "total"
     *
     * @param  string $url      URL complète, sans paramètres de pagination
     * @param  string $itemsKey Clé du tableau d'éléments ("values" ou "issues")
     * @return array            Tous les éléments concaténés
     */
    private function fetchAllPaginated(string $url, string $itemsKey): array
    {
        $all     = [];
        $startAt = 0;
        // Taille de page : ne change rien au résultat (la boucle va jusqu'au bout), seulement le nombre
        // d'allers-retours Jira — deux fois moins qu'avec 50. 100 est le plafond usuel des endpoints
        // concernés ; au-delà, Jira réduit silencieusement la page et la pagination tournerait pour rien.
        $maxResults = 100;

        do {
            $separator = str_contains($url, '?') ? '&' : '?';
            $pageUrl   = $url . $separator . 'startAt=' . $startAt . '&maxResults=' . $maxResults;

            $response = $this->request($pageUrl);
            $items    = $response[$itemsKey] ?? [];
            $all      = array_merge($all, $items);

            $fetched  = count($items);
            $startAt += $fetched;

            // isLast (API Agile) prioritaire ; sinon comparaison au total
            if (array_key_exists('isLast', $response)) {
                $isLast = (bool) $response['isLast'];
            } else {
                $isLast = $startAt >= (int) ($response['total'] ?? 0);
            }

            // Garde-fou anti boucle infinie
            if ($fetched === 0) {
                break;
            }
        } while (!$isLast);

        return $all;
    }

    /**
     * Met à jour les champs de planification d'une issue Jira (PUT).
     *
     * Pousse les deux valeurs saisies dans la page de planification :
     *   - « Reste à faire »            → JiraFields::STORY_POINTS_REMAINING
     *   - « Points rééstimés (final) » → JiraFields::STORY_POINTS_FINAL
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issues/#api-rest-api-3-issue-issueidorkey-put
     *
     * Une valeur null efface le champ correspondant côté Jira (au lieu de le
     * mettre à 0) : l'API interprète `{"customfield_xxx": null}` comme un effacement.
     *
     * @param string     $issueIdOrKey ID numérique ou clé Jira (ex: "ABC-123")
     * @param float|null $remaining    Nouvelle valeur « Reste à faire » (null = effacer)
     * @param float|null $reestimated  Nouvelle valeur « Points rééstimés (final) » (null = effacer)
     */
    public function updateIssuePlanningFields(string $issueIdOrKey, ?float $remaining, ?float $reestimated): void
    {
        $url = $this->baseUrl . self::API_URL_ISSUE . '/' . rawurlencode($issueIdOrKey);

        $payload = json_encode([
            'fields' => [
                JiraFields::STORY_POINTS_REMAINING => $remaining,
                JiraFields::STORY_POINTS_FINAL     => $reestimated,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->sendWriteRequest($url, 'PUT', $payload);
    }

    /**
     * Ajoute un commentaire en texte brut à une issue Jira (POST).
     *
     * Utilise l'API v2 dont le champ `body` accepte du texte simple ; l'API v3
     * imposerait le format ADF (Atlassian Document Format, JSON structuré).
     *
     * @see https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-issue-comments/#api-rest-api-2-issue-issueidorkey-comment-post
     *
     * @param string $issueIdOrKey ID numérique ou clé Jira (ex: "ABC-123")
     * @param string $comment      Texte du commentaire (texte brut)
     */
    public function addCommentToIssue(string $issueIdOrKey, string $comment): void
    {
        $url = $this->baseUrl . str_replace(
            '{issueIdOrKey}',
            rawurlencode($issueIdOrKey),
            self::API_URL_ISSUE_COMMENT
        );

        $payload = json_encode(['body' => $comment], JSON_THROW_ON_ERROR);

        $this->sendWriteRequest($url, 'POST', $payload);
    }

    /**
     * Déplace un ou plusieurs tickets vers un sprint donné (POST, API Agile).
     *
     * L'API borne chaque appel à 50 issues (self::MAX_ISSUES_PER_SPRINT_MOVE) : les clés
     * fournies sont automatiquement découpées en lots, transparent pour l'appelant.
     *
     * @see https://developer.atlassian.com/cloud/jira/software/rest/api-group-sprint/#api-rest-agile-1-0-sprint-sprintid-issue-post
     *
     * @param string   $sprintId  ID Agile du sprint cible
     * @param string[] $issueKeys Clés Jira à déplacer (ex: ["ABC-123", "ABC-124"])
     */
    public function moveIssuesToSprint(string $sprintId, array $issueKeys): void
    {
        $url = $this->baseUrl . self::AGILE_API_SPRINT . '/' . $sprintId . '/issue';

        foreach (array_chunk($issueKeys, self::MAX_ISSUES_PER_SPRINT_MOVE) as $batch) {
            $payload = json_encode(['issues' => $batch], JSON_THROW_ON_ERROR);
            $this->sendWriteRequest($url, 'POST', $payload);
        }
    }

    /**
     * Envoie une requête d'écriture (PUT/POST/...) tolérant un corps de réponse vide.
     *
     * Contrairement à request(), n'exige pas un corps JSON : l'API Jira renvoie
     * « 204 No Content » (corps vide) après une mise à jour d'issue réussie, ce qui
     * ferait échouer le json_decode() de request().
     *
     * @param string           $url     URL complète de l'endpoint
     * @param non-empty-string $method  Méthode HTTP (ex: 'PUT')
     * @param string|null      $payload Corps JSON, ou null
     *
     * @return array Réponse décodée, ou [] si le corps est vide
     *
     * @throws Exception Si le transport échoue, si le code HTTP est >= 400, ou si la réponse n'est pas du JSON
     */
    private function sendWriteRequest(string $url, string $method, ?string $payload = null): array
    {
        if (!self::$areCredentialsVerified) {
            $this->checkCredentials();
        }

        // Écriture : jamais retentée automatiquement (cf. JiraHttpClient) — pas de double-écriture.
        return $this->httpClient->requestJson($method, $url, $payload);
    }

    /**
     * Exécute une requête de lecture (ou de recherche JQL POST) vers l'API REST Jira.
     *
     * `protected` plutôt que `private` : c'est le point d'extension permettant aux tests de rejouer des
     * réponses Jira sans réseau (même rôle que newCollection()/makeCache() côté modèles). L'injection du
     * transport n'étant pas offerte par le constructeur, c'est le seul seam disponible à ce niveau.
     *
     * @param  string|null $payload Corps JSON pré-encodé (recherche POST), ou null pour un GET.
     * @throws Exception            Si le transport échoue, si le code HTTP est >= 400, ou si la réponse n'est pas du JSON
     */
    protected function request(string $url, ?string $payload = null): array
    {
        if (!self::$areCredentialsVerified) {
            $this->checkCredentials();
        }

        $method = $payload !== null ? 'POST' : 'GET';

        return $this->httpClient->requestJson($method, $url, $payload);
    }
}
