<?php

namespace App\Service;

use App\Support\ErrorLogger;
use App\Support\JsonFile;

/**
 * TeamConfigRepository
 *
 * Hub unique de lecture/écriture de la configuration d'un projet, regroupée dans
 * un seul fichier JSON par projet : config_files/teams/<KEY>_team_config.json.
 *
 * Structure du fichier (toutes les clés sont optionnelles ; absentes par défaut) :
 *     {
 *         "projectKey": "ABC",
 *         "preferredBoardId": 123,
 *         "workflow": {
 *             "refinement_statuses": [...],
 *             "sprint_statuses":     [...],
 *             "done_statuses":       [...],
 *             "waiting_statuses":    [...]
 *         },
 *         "issueTypes": ["Story", "Tâche"],
 *         "ignoredSprints": [ { "id": "22370", "name": "ABC prio" } ],
 *         "sprints": {
 *             "10496": {
 *                 "manDays": 25,
 *                 "capacityPoints": 12,
 *                 "capacity": { "members": {...}, "totals": { "presenceTotal": .., "capa": {..}, "capaTotal": .. } }
 *             }
 *         },
 *         "avgVelocity": 0.26,
 *         "planningMargin": 30,
 *         "lastCapacityImportAt": "2026-07-17T14:32:05+02:00"
 *     }
 *
 * Réglages manuels (manDays, capacityPoints) et grille de capacité d'un sprint partagent le même nœud
 * sprints.<id> : la grille est lue/écrite via getSprintCapacity()/setSprintCapacity().
 *
 * Ce fichier est la seule source de vérité par projet.
 */
class TeamConfigRepository
{
    /** Répertoire de stockage par défaut des configs par projet. */
    public const CONFIG_DIR = __DIR__ . '/../../config_files/teams';

    /** Suffixe du fichier de config par projet. */
    public const FILE_SUFFIX = '_team_config.json';

    /** Marge (%) appliquée par défaut à la capacité brute sur la page /planification. */
    public const DEFAULT_PLANNING_MARGIN = 20;

    /** Répertoire effectif (injectable pour les tests, défaut = CONFIG_DIR). */
    private string $configDir;

    /**
     * Cache statique indexé par chemin de fichier : ne lit chaque fichier qu'une
     * fois par requête. Statique car le repository est ré-instancié plusieurs fois
     * durant une requête (Config, controllers, modèles).
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * @param string|null $configDir Répertoire de stockage (tests) ; null = défaut.
     */
    public function __construct(?string $configDir = null)
    {
        $this->configDir = $configDir ?? self::CONFIG_DIR;
    }

    /**
     * Lit et décode le fichier de config du projet (cache statique par chemin).
     * Un fichier absent ou illisible retourne [] (config vide).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function load(string $projectKey): array
    {
        $path = $this->getFilePath($projectKey);
        if (array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }

        return self::$cache[$path] = JsonFile::read($path);
    }

    /**
     * Indique si le fichier de config du projet est présent mais illisible (corrompu) : contenu
     * non vide qui n'est pas un JSON valide, ou fichier présent mais non lisible (droits/IO).
     *
     * Garde-fou avant toute (ré)écriture : un fichier corrompu ne doit JAMAIS être écrasé
     * silencieusement par une sauvegarde partielle (qui repartirait d'un load() vide → perte du
     * reste de la config).
     * À distinguer d'un fichier absent ou vide ({}, [], 0 octet), lui légitimement (ré)généré.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function isCorrupted(string $projectKey): bool
    {
        $path = $this->getFilePath($projectKey);
        if (!is_file($path)) {
            return false; // Absent → projet neuf, régénération légitime.
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return true; // Présent mais illisible (droits/IO) → échec bruyant.
        }

        $trimmed = trim($raw);
        // Vide (0 octet, {}, []) → régénération légitime, pas une corruption.
        if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]') {
            return false;
        }

        // Contenu non vide qui ne se décode pas en tableau → corrompu.
        return !is_array(json_decode($raw, true));
    }

    /**
     * Écrit la config complète du projet et garde le cache cohérent.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  array  $data       Config complète à persister
     * @return string             Nom relatif du fichier écrit (ex: "teams/ABC_team_config.json")
     *
     * @throws \RuntimeException Si le répertoire ou l'écriture échoue
     */
    public function save(string $projectKey, array $data): string
    {
        $data['projectKey'] = $projectKey;
        $this->writeFile($projectKey, $data);

        return 'teams/' . basename($this->getFilePath($projectKey));
    }

    /**
     * Retourne l'ID du board par défaut du projet, ou null si non configuré.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function getPreferredBoardId(string $projectKey): ?int
    {
        $id = $this->load($projectKey)['preferredBoardId'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Enregistre (ou efface) le board par défaut du projet.
     *
     * @param  string   $projectKey Clé du projet Jira (ex: "ABC")
     * @param  int|null  $boardId    ID du board, ou null pour effacer
     * @return string                Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setPreferredBoardId(string $projectKey, ?int $boardId): string
    {
        $data = $this->load($projectKey);
        if ($boardId === null) {
            unset($data['preferredBoardId']);
        } else {
            $data['preferredBoardId'] = $boardId;
        }

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne la vélocité moyenne de l'équipe (points terminés par jour/homme) mémorisée lors de la
     * dernière analyse de la page Métriques d'équipe, ou null si jamais calculée.
     *
     * Une valeur <= 0 est traitée comme absente : elle ne permettrait aucune suggestion de capacité.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function getAvgVelocity(string $projectKey): ?float
    {
        $velocity = $this->load($projectKey)['avgVelocity'] ?? null;

        return (is_numeric($velocity) && (float) $velocity > 0) ? (float) $velocity : null;
    }

    /**
     * Mémorise la vélocité moyenne de l'équipe. Elle alimente la capacité suggérée de /planification.
     *
     * Volontairement non-nullable : une vélocité non calculable (aucun jour/homme saisi sur la
     * sélection) ne doit PAS effacer la dernière valeur connue — l'appelant s'abstient alors d'écrire.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  float  $velocity   Points terminés par jour/homme
     * @return string             Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setAvgVelocity(string $projectKey, float $velocity): string
    {
        $data                = $this->load($projectKey);
        $data['avgVelocity'] = $velocity;

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne la marge (%) appliquée à la capacité brute sur la page /planification.
     *
     * Ce réglage est partagé par toute l'équipe (il n'est plus mémorisé dans le navigateur).
     * Absent, non numérique ou hors bornes → repli sur DEFAULT_PLANNING_MARGIN / bornage 0..100,
     * pour qu'une édition manuelle du JSON ne puisse pas produire une capacité négative.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function getPlanningMargin(string $projectKey): int
    {
        $margin = $this->load($projectKey)['planningMargin'] ?? null;
        if (!is_numeric($margin)) {
            return self::DEFAULT_PLANNING_MARGIN;
        }

        return max(0, min(100, (int) $margin));
    }

    /**
     * Enregistre la marge (%) de planification. La validation des bornes incombe à l'appelant
     * (\App\Controller\PlanificationController::savePlanningMargin()).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  int    $margin     Marge en pourcentage
     * @return string             Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setPlanningMargin(string $projectKey, int $margin): string
    {
        $data                  = $this->load($projectKey);
        $data['planningMargin'] = $margin;

        return $this->save($projectKey, $data);
    }

    /**
     * Indique si le projet possède une config de workflow propre (non vide).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function hasWorkflow(string $projectKey): bool
    {
        $workflow = $this->load($projectKey)['workflow'] ?? null;

        return is_array($workflow) && $workflow !== [];
    }

    /**
     * Retourne les 4 catégories de statuts du workflow du projet, ou [] si absent.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function getWorkflow(string $projectKey): array
    {
        return $this->load($projectKey)['workflow'] ?? [];
    }

    /**
     * Persiste les 4 listes de statuts du workflow (ordre conservé) dans la clé
     * "workflow", sans toucher au reste de la config.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  array  $categories Tableau des 4 clés *_statuses (string[])
     * @return string             Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setWorkflow(string $projectKey, array $categories): string
    {
        $data             = $this->load($projectKey);
        $data['workflow'] = $categories;

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne la liste des sprints ignorés du projet (objets {id, name}).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<int, array{id: string, name: string}>
     */
    public function getIgnoredSprints(string $projectKey): array
    {
        $ignored = $this->load($projectKey)['ignoredSprints'] ?? [];

        return is_array($ignored) ? $ignored : [];
    }

    /**
     * Retourne les seuls noms des sprints ignorés (pour le filtrage serveur par nom).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return string[]
     */
    public function getIgnoredSprintNames(string $projectKey): array
    {
        $names = array_map(
            static fn ($sprint): string => $sprint['name'],
            $this->getIgnoredSprints($projectKey)
        );

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    /**
     * Remplace la liste des sprints ignorés du projet (objets {id, name}).
     *
     * @param  string $projectKey     Clé du projet Jira (ex: "ABC")
     * @param  array  $ignoredSprints Liste d'objets {id, name}
     * @return string                 Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setIgnoredSprints(string $projectKey, array $ignoredSprints): string
    {
        $data                   = $this->load($projectKey);
        $data['ignoredSprints'] = array_values($ignoredSprints);

        return $this->save($projectKey, $data);
    }

    /**
     * Indique si le projet possède une liste de types de tickets propre (clé
     * "issueTypes" présente, même vide). Sert à arbitrer le fallback global.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function hasIssueTypes(string $projectKey): bool
    {
        return array_key_exists('issueTypes', $this->load($projectKey));
    }

    /**
     * Retourne la liste des types de tickets propres au projet (utilisés dans les
     * métriques), ou [] si non configurée.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return string[]
     */
    public function getIssueTypes(string $projectKey): array
    {
        $types = $this->load($projectKey)['issueTypes'] ?? [];

        return is_array($types) ? $types : [];
    }

    /**
     * Remplace la liste des types de tickets propres au projet.
     *
     * @param  string   $projectKey Clé du projet Jira (ex: "ABC")
     * @param  string[] $types      Noms des types retenus pour les métriques
     * @return string                Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setIssueTypes(string $projectKey, array $types): string
    {
        $data               = $this->load($projectKey);
        $data['issueTypes'] = array_values($types);

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne le roster de l'équipe du projet (membres avec rôle et période de présence),
     * ou [] si non configuré. Sert de source aux colonnes de l'onglet Capacité.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<int, array{id: string, name: string, role: string, entryDate: string, exitDate: string}>
     */
    public function getTeamMembers(string $projectKey): array
    {
        $members = $this->load($projectKey)['members'] ?? [];

        return is_array($members) ? $members : [];
    }

    /**
     * Remplace le roster de l'équipe du projet (liste de membres déjà normalisée).
     *
     * @param  string                                                                                          $projectKey Clé du projet Jira (ex: "ABC")
     * @param  array<int, array{id: string, name: string, role: string, entryDate: string, exitDate: string}> $members    Membres à persister
     * @return string                                                                                                     Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setTeamMembers(string $projectKey, array $members): string
    {
        $data            = $this->load($projectKey);
        $data['members'] = array_values($members);

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne la date/heure du dernier import d'absences réussi (ISO 8601), ou null si jamais importé.
     * Écrite par CapacityImportController::apply() à chaque import réussi.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     */
    public function getLastCapacityImportAt(string $projectKey): ?string
    {
        $value = $this->load($projectKey)['lastCapacityImportAt'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Retourne la clé du projet Jira parent portant les tickets Release et Feature
     * (ex : "XYZ"). Retourne une chaîne vide si non configuré.
     *
     * @param string $projectKey Clé du projet d'équipe (ex: "ABC")
     */
    public function getParentProjectKey(string $projectKey): string
    {
        return (string) ($this->load($projectKey)['parentProjectKey'] ?? '');
    }

    /**
     * Enregistre (ou efface) la clé du projet Jira parent portant les tickets Release et Feature.
     * Une chaîne vide supprime le champ.
     *
     * @param  string $projectKey Clé du projet d'équipe (ex: "ABC")
     * @param  string $parentKey  Clé du projet parent (ex: "XYZ"), ou '' pour supprimer
     * @return string             Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setParentProjectKey(string $projectKey, string $parentKey): string
    {
        $data = $this->load($projectKey);
        if ($parentKey === '') {
            unset($data['parentProjectKey']);
        } else {
            $data['parentProjectKey'] = strtoupper($parentKey);
        }

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne le nom d'équipe (champ Équipe Jira) servant à filtrer les Releases du projet
     * parent. Si non configuré, retourne le projectKey par défaut (cas où le champ Équipe
     * porte directement la clé du projet d'équipe).
     *
     * @param string $projectKey Clé du projet d'équipe (ex: "ABC")
     */
    public function getReleaseTeamName(string $projectKey): string
    {
        $name = (string) ($this->load($projectKey)['releaseTeamName'] ?? '');

        return $name !== '' ? $name : $projectKey;
    }

    /**
     * Enregistre (ou efface) le nom d'équipe filtrant les Releases du projet parent.
     * Une chaîne vide supprime le champ (retour au défaut = projectKey).
     *
     * @param  string $projectKey Clé du projet d'équipe (ex: "ABC")
     * @param  string $teamName   Nom d'équipe (champ Équipe), ou '' pour revenir au défaut
     * @return string             Nom relatif du fichier écrit
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setReleaseTeamName(string $projectKey, string $teamName): string
    {
        $data = $this->load($projectKey);
        if ($teamName === '') {
            unset($data['releaseTeamName']);
        } else {
            $data['releaseTeamName'] = $teamName;
        }

        return $this->save($projectKey, $data);
    }

    /**
     * Retourne la map complète des réglages par sprint (sprintId → réglages).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<string, array> Ex: ['10496' => ['manDays' => 25.0], ...]
     */
    public function getAllSprintSettings(string $projectKey): array
    {
        $sprints = $this->load($projectKey)['sprints'] ?? [];
        if (!is_array($sprints)) {
            return [];
        }

        // Réglages scalaires uniquement (manDays, capacityPoints) : la grille de capacité, logée dans le
        // même nœud depuis la fusion, se lit via getSprintCapacity() et n'a pas sa place ici.
        $settings = [];
        foreach ($sprints as $sprintId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            unset($entry['capacity']);
            if ($entry !== []) {
                $settings[(string) $sprintId] = $entry;
            }
        }

        return $settings;
    }

    /**
     * Retourne la grille de capacité saisie pour un sprint (présence par membre, événements, ratio et
     * totaux), ou [] si absente. Stockée sous la clé "capacity" (indépendante de "sprints").
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  string $sprintId   ID Agile du sprint
     * @return array{members?: array<string, array{presence?: array<string, float>, forecast?: list<string>, events?: float, ratio?: float}>, dayEvents?: array<string, float>, totals?: array{presenceTotal: float, capa: array<string, float>, capaTotal: float}}
     */
    public function getSprintCapacity(string $projectKey, string $sprintId): array
    {
        $capacity = $this->load($projectKey)['sprints'][$sprintId]['capacity'] ?? [];

        return is_array($capacity) ? $capacity : [];
    }

    /**
     * Enregistre (ou efface) la grille de capacité d'un sprint, sans toucher aux autres sprints.
     *
     * Une grille est conservée dès qu'elle porte une capacité exploitable — c'est-à-dire un roster
     * présent (totals.presenceTotal > 0), même SANS absence saisie — afin que capaTotal alimente les
     * jours/homme des pages Sprint / Métriques d'équipe. Elle n'est retirée que si elle est totalement
     * vide (ni membre, ni événement, ni présence).
     *
     * @param string                                                                                                                                                                                   $projectKey Clé du projet Jira (ex: "ABC")
     * @param string                                                                                                                                                                                   $sprintId   ID Agile du sprint
     * @param array{members?: array<string, array{presence?: array<string, float>, forecast?: list<string>, events?: float, ratio?: float}>, dayEvents?: array<string, float>, totals?: array{presenceTotal: float, capa: array<string, float>, capaTotal: float}} $capacity   Grille normalisée
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setSprintCapacity(string $projectKey, string $sprintId, array $capacity): void
    {
        $data    = $this->load($projectKey);
        $sprints = $data['sprints'] ?? [];

        if ($this->isEmptyCapacity($capacity)) {
            unset($sprints[$sprintId]['capacity']);
        } else {
            $sprints[$sprintId]['capacity'] = $capacity;
        }

        // L'entrée du sprint ne porte plus aucun réglage (ni manuel, ni grille) → on la retire.
        if (($sprints[$sprintId] ?? []) === []) {
            unset($sprints[$sprintId]);
        }

        $data['sprints'] = $sprints;
        $this->save($projectKey, $data);
    }

    /**
     * Indique si une grille de capacité est totalement vide (aucune donnée exploitable).
     *
     * Non vide dès qu'elle porte un membre, un événement, OU une présence d'équipe (presenceTotal > 0) :
     * une grille « tout le monde présent » (sans absence) reste donc significative et persistée.
     *
     * @param array{members?: array<string, mixed>, dayEvents?: array<string, mixed>, totals?: array{presenceTotal?: mixed}} $capacity Grille normalisée
     */
    private function isEmptyCapacity(array $capacity): bool
    {
        $hasMembers   = ($capacity['members'] ?? []) !== [];
        $hasDayEvents = ($capacity['dayEvents'] ?? []) !== [];
        $presence     = $capacity['totals']['presenceTotal'] ?? 0;
        $hasPresence  = is_numeric($presence) && (float) $presence > 0;

        return !$hasMembers && !$hasDayEvents && !$hasPresence;
    }

    /**
     * Retourne les jours/homme issus de la grille de capacité d'un sprint (total capaTotal), ou null
     * si la grille est absente ou sans capacité exploitable (<= 0).
     *
     * L'onglet Capacité fait foi pour les jours/homme : cette valeur, quand elle existe, prime sur la
     * saisie manuelle (cf. \App\Service\SprintConfigRepository::getManDays()).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function getCapacityManDays(string $projectKey, string $sprintId): ?float
    {
        return $this->capaTotalOf($this->getSprintCapacity($projectKey, $sprintId));
    }

    /**
     * Retourne la map sprintId → jours/homme de capacité (capaTotal exploitable) pour tous les sprints
     * du projet dotés d'une grille. Sert à surcharger en masse les jours/homme (page Métriques d'équipe).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<string, float>
     */
    public function getAllCapacityManDays(string $projectKey): array
    {
        $sprints = $this->load($projectKey)['sprints'] ?? [];
        if (!is_array($sprints)) {
            return [];
        }

        $result = [];
        foreach ($sprints as $sprintId => $entry) {
            $capacity  = (is_array($entry) && is_array($entry['capacity'] ?? null)) ? $entry['capacity'] : null;
            $capaTotal = $capacity !== null ? $this->capaTotalOf($capacity) : null;
            if ($capaTotal !== null) {
                $result[(string) $sprintId] = $capaTotal;
            }
        }

        return $result;
    }

    /**
     * Extrait le total de capacité exploitable (totals.capaTotal > 0) d'une grille, ou null.
     *
     * @param array{totals?: array{capaTotal?: mixed}} $capacity Grille de capacité d'un sprint
     */
    private function capaTotalOf(array $capacity): ?float
    {
        $capaTotal = $capacity['totals']['capaTotal'] ?? null;

        return (is_numeric($capaTotal) && (float) $capaTotal > 0) ? (float) $capaTotal : null;
    }

    /**
     * Retourne les jours/homme saisis pour un sprint donné, ou null si absent.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function getManDays(string $projectKey, string $sprintId): ?float
    {
        $value = $this->getAllSprintSettings($projectKey)[$sprintId]['manDays'] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * Enregistre (ou efface) les jours/homme d'un sprint, sans toucher aux autres.
     *
     * Une valeur null ou <= 0 retire la clé "manDays" ; si l'entrée du sprint
     * devient vide, elle est supprimée de la map.
     *
     * @param string     $projectKey Clé du projet Jira (ex: "ABC")
     * @param string     $sprintId   ID Agile du sprint
     * @param float|null $manDays    Jours/homme, ou null/<= 0 pour effacer
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setManDays(string $projectKey, string $sprintId, ?float $manDays): void
    {
        $this->setSprintSetting($projectKey, $sprintId, 'manDays', $manDays);
    }

    /**
     * Retourne la capacité en points saisie pour un sprint donné, ou null si absente.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function getCapacityPoints(string $projectKey, string $sprintId): ?float
    {
        $value = $this->getAllSprintSettings($projectKey)[$sprintId]['capacityPoints'] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * Enregistre (ou efface) la capacité en points d'un sprint, sans toucher aux autres.
     *
     * @param string     $projectKey     Clé du projet Jira (ex: "ABC")
     * @param string     $sprintId       ID Agile du sprint
     * @param float|null $capacityPoints Capacité en points, ou null/<= 0 pour effacer
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setCapacityPoints(string $projectKey, string $sprintId, ?float $capacityPoints): void
    {
        $this->setSprintSetting($projectKey, $sprintId, 'capacityPoints', $capacityPoints);
    }

    /**
     * Enregistre (ou efface) un réglage numérique d'un sprint, sans toucher aux autres.
     *
     * Une valeur null ou <= 0 retire la clé ; si l'entrée du sprint ne porte plus
     * aucun réglage, elle est supprimée de la map.
     *
     * @param string     $projectKey Clé du projet Jira (ex: "ABC")
     * @param string     $sprintId   ID Agile du sprint
     * @param string     $key        Nom du réglage (ex: "manDays", "capacityPoints")
     * @param float|null $value      Valeur, ou null/<= 0 pour effacer
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    private function setSprintSetting(string $projectKey, string $sprintId, string $key, ?float $value): void
    {
        $data    = $this->load($projectKey);
        $sprints = $data['sprints'] ?? [];

        if ($value === null || $value <= 0) {
            unset($sprints[$sprintId][$key]);
            // L'entrée du sprint ne porte plus aucun réglage → on la retire.
            if (empty($sprints[$sprintId])) {
                unset($sprints[$sprintId]);
            }
        } else {
            $sprints[$sprintId][$key] = $value;
        }

        $data['sprints'] = $sprints;
        $this->save($projectKey, $data);
    }

    /**
     * Écrit la config JSON du projet (Pretty Print, écriture ATOMIQUE via JsonFile::write) et met
     * le cache à jour.
     *
     * L'écriture atomique (fichier temporaire + rename) garantit qu'un process interrompu en plein
     * write — timeout, fatal error — ne laisse jamais une config tronquée sur disque.
     * Crée le répertoire teams/ s'il est absent. Pas de JSON_FORCE_OBJECT : la structure contient
     * des listes ordonnées (statuts du workflow) qui doivent rester des tableaux JSON, pas des objets.
     *
     * @throws \RuntimeException Si l'encodage ou l'écriture échoue
     */
    private function writeFile(string $projectKey, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $path = $this->getFilePath($projectKey);
        if ($json === false || !JsonFile::write($path, $json)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('TeamConfigRepository : impossible d\'écrire la configuration d\'équipe.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }

        self::$cache[$path] = $data;
    }

    /**
     * Construit le chemin absolu du fichier de config d'un projet.
     *
     * Le nom est assaini (mêmes caractères autorisés que SprintCacheService) pour
     * empêcher toute traversée de répertoire via une clé de projet malformée.
     */
    private function getFilePath(string $projectKey): string
    {
        $safeKey = preg_replace('/[^a-z0-9_-]/i', '', $projectKey);

        return $this->configDir . '/' . $safeKey . self::FILE_SUFFIX;
    }
}
