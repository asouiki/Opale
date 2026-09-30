<?php

namespace App\Model;

use App\Service\GovApiService;
use App\Service\TeamConfigRepository;
use App\Support\JsonFile;

/**
 * Accès à la configuration applicative stockée dans config_files/ : workflows de statuts, types
 * d'issues, projets suivis et jours fériés. Lecture des fichiers JSON mutualisée par un cache statique.
 */
class Config
{
    public const CONFIG_FILES_DIR = 'config_files';
    public const JIRA_WORKFLOW_FILE_ISSUES = 'jira_workflow_issues.json';
    public const JIRA_WORKFLOW_FILE_FEATURES = 'jira_workflow_features.json';
    public const ISSUE_TYPE_FEATURE = 'Feature';
    public const JIRA_WATCHED_PROJECTS_FILE = 'watched_projects.json';
    public const JIRA_ISSUE_TYPES_FILE = 'jira_issues_types.json';

    /**
     * Cache statique des fichiers de config déjà lus, indexé par nom de fichier.
     *
     * Statique (et non d'instance) : Config est instancié plusieurs fois par
     * requête (AbstractIssueCollection, Timeline...) ; le cache doit être
     * partagé pour ne lire chaque JSON qu'une seule fois.
     *
     * @var array<string, array>
     */
    private static array $fileCache = [];

    /**
     * Cache mémoire de la liste des jours fériés (résultat de getNonWorkingDays()), pour tout le
     * processus PHP.
     *
     * Statique, même motif que self::$fileCache : Config est ré-instancié plusieurs fois par requête
     * (AbstractIssueCollection, Timeline...), et la liste est invariante sur la durée d'une requête
     * (elle ne dépend que de la date du jour). Sans ce cache, Timeline la reconstruit (array_filter +
     * array_keys + array_values sur ~120 dates) à chaque calcul de durée, soit plusieurs milliers de
     * fois par sprint.
     *
     * @var string[]|null
     */
    private static ?array $nonWorkingDaysCache = null;

    /**
     * Index des jours fériés pour un test d'appartenance en O(1) : seules les CLÉS portent le sens
     * (dates d/m/Y), les valeurs sont les index rendus par array_flip() et ne sont jamais lues.
     *
     * Volontairement NON statique (contrairement à $nonWorkingDaysCache) : getNonWorkingDays() peut
     * être surchargée avec des données propres à l'instance (FakeConfig, en test) ; un index statique
     * partagé entre instances renverrait alors des résultats périmés d'un test à l'autre. Le surcoût
     * est négligeable : un array_flip sur ~120 dates, contre le in_array() linéaire qu'il remplace.
     *
     * @var array<string, int>|null
     */
    private ?array $nonWorkingDaysMap = null;

    /**
     * Clé du projet Jira courant, posée en début de requête par les controllers.
     *
     * Permet à getJiraWorkflow() de charger en priorité un workflow propre au
     * projet (clé "workflow" de teams/<KEY>_team_config.json) plutôt que le global.
     * Statique car Config est ré-instancié plusieurs fois durant une requête.
     */
    private static string $currentProjectKey = '';

    /**
     * Définit le projet Jira courant pour la résolution du workflow par projet.
     */
    public static function setCurrentProjectKey(string $projectKey): void
    {
        self::$currentProjectKey = $projectKey;
    }

    /**
     * Indique si l'application tourne en mode démo (variable d'environnement IS_DEMO).
     */
    public static function isDemo(): bool
    {
        return (bool) ($_ENV['IS_DEMO'] ?? false);
    }

    /**
     * Retourne l'URL de base de l'instance Jira (variable d'environnement JIRA_BASE_URL).
     *
     * La construction des URLs Jira paramétriques est centralisée dans {@see \App\Model\JiraUrl}.
     */
    public static function jiraBaseUrl(): string
    {
        return (string) ($_ENV['JIRA_BASE_URL'] ?? '');
    }

    /**
     * Lit et décode un fichier JSON du dossier config_files.
     *
     * Le résultat (y compris le cas « fichier absent » → []) est mémorisé dans
     * $fileCache : les appels suivants pour le même fichier ne rouvrent pas le
     * disque. Le cache vit le temps du processus PHP, donc le temps d'une requête.
     */
    protected function getFileContent(string $fileName): array
    {
        if (isset(self::$fileCache[$fileName])) {
            return self::$fileCache[$fileName];
        }

        $filePath = $this->getFilePath($fileName);
        return self::$fileCache[$fileName] = JsonFile::read($filePath);
    }

    /**
     * Construit le chemin absolu d'un fichier du dossier config_files.
     */
    protected function getFilePath(string $fileName): string
    {
        return __DIR__ . '/../../' . self::CONFIG_FILES_DIR . '/' . $fileName;
    }

    /**
     * Écrit (pretty-print) un fichier JSON global du dossier config_files et
     * invalide son entrée de cache pour relecture à jour dans la même requête.
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    protected function writeConfigFile(string $fileName, array $data): void
    {
        $filePath = $this->getFilePath($fileName);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false || file_put_contents($filePath, $json) === false) {
            throw new \RuntimeException("Impossible d'écrire le fichier de config : $fileName");
        }

        unset(self::$fileCache[$fileName]);
    }

    /**
     * Retourne la liste des dates de jours fériés pour les 4 années précédentes, l'année en cours et
     * l'année suivante, issues de l'API du gouvernement français.
     *
     * Algorithme :
     * 1. Récupère tous les jours fériés disponibles via GovApiService (un seul appel HTTP)
     * 2. Filtre sur la plage d'années [année_courante - 4 ; année_courante + 1]
     * 3. Retourne un tableau plat de dates au format d/m/Y
     *
     * @return string[]  Ex: ['01/01/2027', '01/05/2027', ...]
     *
     * @throws \RuntimeException Si l'API est inaccessible
     */
    public function getNonWorkingDays(): array
    {
        if (self::$nonWorkingDaysCache !== null) {
            return self::$nonWorkingDaysCache;
        }

        $currentYear = (int) date('Y') + 1;
        $minYear     = $currentYear - 5;

        // Récupération (avec cache statique interne à GovApiService)
        $govApi      = new GovApiService();
        $allHolidays = $govApi->fetchAllHolidays();

        // Filtre sur la plage d'années voulue, et retourne les dates (clés du tableau)
        return self::$nonWorkingDaysCache = array_values(
            array_filter(
                array_keys($allHolidays),
                static function (string $date) use ($minYear, $currentYear): bool {
                    $year = (int) substr($date, -4);
                    return $year >= $minYear && $year <= $currentYear;
                }
            )
        );
    }

    /**
     * Indique si une date (format d/m/Y, cf. getNonWorkingDays()) est un jour férié.
     *
     * Lookup en O(1) via une map construite une fois par instance à partir de getNonWorkingDays(), au
     * lieu du in_array() linéaire précédemment répété à chaque appel par Timeline.
     */
    public function isNonWorkingDay(string $frenchDate): bool
    {
        if ($this->nonWorkingDaysMap === null) {
            $this->nonWorkingDaysMap = array_flip($this->getNonWorkingDays());
        }

        return isset($this->nonWorkingDaysMap[$frenchDate]);
    }

    /**
     * Workflow Jira (catégories de statuts) à appliquer.
     *
     * Celui des Features si $issueType vaut « Feature », sinon le workflow d'issues : celui du
     * projet courant s'il en possède un, à défaut le workflow global.
     */
    public function getJiraWorkflow(string $issueType = ''): array
    {
        if ($issueType === self::ISSUE_TYPE_FEATURE) {
            return $this->getFileContent(self::JIRA_WORKFLOW_FILE_FEATURES);
        }

        // Workflow propre au projet courant s'il en possède un, sinon le global.
        if (self::$currentProjectKey !== '') {
            $teamConfig = new TeamConfigRepository();
            if ($teamConfig->hasWorkflow(self::$currentProjectKey)) {
                return $teamConfig->getWorkflow(self::$currentProjectKey);
            }
        }

        return $this->getFileContent(self::JIRA_WORKFLOW_FILE_ISSUES);
    }

    /**
     * Retourne les statuts du workflow dans leur ordre d'affichage :
     * refinement → sprint → done (ou l'inverse si $reverseOrder = true).
     *
     * @param  string $issueType   Voir getJiraWorkflow()
     * @param  bool   $reverseOrder Inverse l'ordre (done → sprint → refinement)
     * @return string[]
     */
    public function getWorkflowStatusOrder(string $issueType = '', bool $reverseOrder = false): array
    {
        $workflow = $this->getJiraWorkflow($issueType);

        $order = array_merge(
            $workflow['refinement_statuses'] ?? [],
            $workflow['sprint_statuses']     ?? [],
            $workflow['done_statuses']       ?? []
        );

        return $reverseOrder ? array_reverse($order) : $order;
    }

    /**
     * Liste des clés de projets suivis (watched_projects.json).
     */
    public function getWatchedProjects(): array
    {
        return $this->getFileContent(self::JIRA_WATCHED_PROJECTS_FILE);
    }

    /**
     * Ajoute une clé de projet à la liste des projets suivis si elle est absente.
     *
     * Sert lors de la configuration d'un projet via la page /config : tout projet
     * configuré doit figurer dans watched_projects.json. Sans effet (aucune
     * écriture) si la clé y est déjà présente.
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function addWatchedProject(string $projectKey): void
    {
        $projects = $this->getWatchedProjects();
        if (in_array($projectKey, $projects, true)) {
            return;
        }

        $projects[] = $projectKey;
        $this->writeConfigFile(self::JIRA_WATCHED_PROJECTS_FILE, $projects);
    }

    /**
     * Structure de jira_issues_types.json :
     *   {
     *     "defaultTypes": [...],  // types cochés par défaut sur un projet neuf
     *     "types":        [...]   // liste globale enrichie (fallback métriques)
     *   }
     * Ces deux accesseurs lisent leurs nœuds respectifs (tableau vide si absent).
     */

    /**
     * Liste globale enrichie des types (nœud "types"), utilisée comme valeur par
     * défaut des métriques pour un projet sans liste propre.
     *
     * @return string[]
     */
    private function getGlobalIssueTypes(): array
    {
        return $this->getFileContent(self::JIRA_ISSUE_TYPES_FILE)['types'] ?? [];
    }

    /**
     * Types de tickets cochés par défaut lors de l'initialisation d'un projet
     * (nœud "defaultTypes"). Les autres types du projet restent décochés.
     *
     * @return string[]
     */
    public function getDefaultMetricIssueTypes(): array
    {
        return $this->getFileContent(self::JIRA_ISSUE_TYPES_FILE)['defaultTypes'] ?? [];
    }

    /**
     * Ajoute au nœud "types" de la liste globale (jira_issues_types.json) les types
     * absents, en préservant l'ordre existant et le nœud "defaultTypes". N'écrit
     * que si au moins un type est ajouté. Retourne les types réellement ajoutés.
     *
     * @param  string[] $types Noms de types détectés (ex: ['Story', 'Spike'])
     * @return string[]        Types nouvellement ajoutés
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function addIssueTypes(array $types): array
    {
        $content  = $this->getFileContent(self::JIRA_ISSUE_TYPES_FILE);
        $existing = $content['types'] ?? [];
        $added    = [];

        foreach ($types as $type) {
            $type = (string) $type;
            if ($type !== '' && !in_array($type, $existing, true)) {
                $existing[] = $type;
                $added[]    = $type;
            }
        }

        if ($added !== []) {
            $content['types'] = $existing;
            $this->writeConfigFile(self::JIRA_ISSUE_TYPES_FILE, $content);
        }

        return $added;
    }

    /**
     * Retourne la liste des types d'issues Jira à prendre en compte dans le calcul
     * des métriques.
     *
     * Résolution : liste propre au projet courant (clé "issueTypes" de
     * teams/<KEY>_team_config.json) si elle y figure, sinon la liste globale par
     * défaut (nœud "types" de config_files/jira_issues_types.json).
     *
     * Tout type absent de la liste retenue (ex: « Test », « Test Set ») est exclu
     * des calculs. Une liste vide retourne [] : l'appelant interprète alors
     * « aucun filtrage » et préserve le comportement existant.
     *
     * @return string[] Ex: ['Story', 'Bug', 'Task', ...]
     */
    public function getMetricIssueTypes(): array
    {
        if (self::$currentProjectKey !== '') {
            $teamConfig = new TeamConfigRepository();
            if ($teamConfig->hasIssueTypes(self::$currentProjectKey)) {
                return $teamConfig->getIssueTypes(self::$currentProjectKey);
            }
        }

        return $this->getGlobalIssueTypes();
    }

    /**
     * Retourne les noms de sprints « fictifs » à exclure de la liste des sprints
     * affectés, propres au projet courant (clé "ignoredSprints" de
     * teams/<KEY>_team_config.json).
     *
     * Cas d'usage : un sprint qui n'est pas une itération réelle, ex. une backlog
     * de priorisation tenue par le PO. La comparaison côté appelant est insensible
     * à la casse. Sans projet courant ou sans config, retourne [] : aucun filtrage.
     *
     * @return string[] Ex: ['ABC prio']
     */
    public function getIgnoredSprintNames(): array
    {
        if (self::$currentProjectKey === '') {
            return [];
        }

        return (new TeamConfigRepository())->getIgnoredSprintNames(self::$currentProjectKey);
    }

    /**
     * Indique si un workflow d'issues propre au projet existe déjà (clé "workflow"
     * de teams/<KEY>_team_config.json).
     */
    public function hasProjectIssuesWorkflow(string $projectKey): bool
    {
        return (new TeamConfigRepository())->hasWorkflow($projectKey);
    }

    /**
     * Retourne le workflow d'issues d'un projet : sa config propre si elle existe,
     * sinon le fichier global (fallback). Sert à pré-remplir l'IHM de config.
     *
     * @return array{source: string, categories: array}
     */
    public function getProjectIssuesWorkflow(string $projectKey): array
    {
        $teamConfig = new TeamConfigRepository();
        if ($teamConfig->hasWorkflow($projectKey)) {
            return [
                'source'     => 'project',
                'categories' => $teamConfig->getWorkflow($projectKey),
            ];
        }

        return [
            'source'     => 'global',
            'categories' => $this->getFileContent(self::JIRA_WORKFLOW_FILE_ISSUES),
        ];
    }

    /**
     * Écrit le workflow d'issues propre à un projet (clé "workflow" de
     * teams/<KEY>_team_config.json), sans toucher au reste de la config, et retourne le nom du
     * fichier écrit.
     *
     * Les listes reçues sont conservées dans l'ordre fourni (l'ordre porte le
     * sens métier : du statut de départ au statut final de chaque catégorie).
     *
     * @param string $projectKey Clé du projet (ex: "ABC"), déjà validée par l'appelant
     * @param array  $categories Tableau avec les 4 clés *_statuses (string[])
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function saveIssuesWorkflow(string $projectKey, array $categories): string
    {
        return (new TeamConfigRepository())->setWorkflow($projectKey, $categories);
    }
}
