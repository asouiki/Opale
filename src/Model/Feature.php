<?php

namespace App\Model;

use App\Repository\ProjectFeaturesRepository;
use Exception;

/**
 * Feature
 *
 * Agrégat d'issues Jira rattachées à une Feature (via champ parent).
 * Hérite de toute la logique de métriques via AbstractIssueCollection.
 */
class Feature extends AbstractIssueCollection
{
    private array $featureData = [];
    private ProjectFeaturesRepository $repository;
    /** Issue représentant le ticket Feature lui-même (pour ses propres métriques) */
    private ?Issue $selfAsIssue = null;

    public function __construct(string $id)
    {
        $this->repository = new ProjectFeaturesRepository();
        parent::__construct($id);
    }

    /**
     * {@inheritdoc}
     *
     * Charge les métadonnées de la Feature Jira, avec changelog et renderedFields
     * pour permettre le calcul des métriques propres au ticket Feature
     * et le rendu fidèle de la description.
     *
     * @throws \Exception Si la Feature Jira est introuvable
     */
    protected function loadCollectionData(string $id): void
    {
        $result = $this->jiraService->getIssueById($id, [
            'summary',
            'status',
            'issuetype',
            'priority',
            'description',
            'created',
            'duedate',                              // Date d'échéance (champ natif Jira)
            JiraFields::JALON,
            JiraFields::EPIC_NAME,
            JiraFields::TEAM,
            JiraFields::PLANNING_INTERVAL,
        ], ['renderedFields', 'changelog']);

        if (!isset($result['id'])) {
            throw new Exception("Feature Jira introuvable (ID: $id)");
        }

        $this->featureData = $result;

        // Crée un objet Issue depuis le ticket Feature lui-même
        // pour calculer ses métriques propres (Lead Time, Cycle Time, temps par statut)
        $this->selfAsIssue = new Issue($result);
    }

    /**
     * {@inheritdoc}
     *
     * Charge les issues enfants via JQL parent = {featureId}, avec changelog embarqué.
     *
     * @throws \Exception Si la récupération des tickets de la Feature échoue
     */
    protected function loadIssues(string $id): void
    {
        try {
            $rawIssues = $this->jiraService->getIssuesByParent($id, $this->config->getMetricIssueTypes());

            foreach ($rawIssues as $rawIssueData) {
                $this->issues[] = new Issue($rawIssueData);
            }

            $this->issuesCount = count($this->issues);
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération des tickets de la Feature : ' . $e->getMessage());
        }
    }

    // ==================== Getters Feature-spécifiques ====================

    public function getId(): ?string
    {
        return $this->featureData['id'] ?? null;
    }
    public function getKey(): ?string
    {
        return $this->featureData['key'] ?? null;
    }
    public function getName(): ?string
    {
        return $this->featureData['fields']['summary'] ?? null;
    }
    public function getStatusName(): ?string
    {
        return $this->featureData['fields']['status']['name'] ?? null;
    }
    public function getStatusLabel(): string
    {
        return $this->repository->getStatusLabel($this->getStatusName() ?? '');
    }
    /**
     * Retourne le Planning Interval le plus élevé, ou null si non renseigné.
     *
     * Le champ étant multivaleurs (cf. getPlanningIntervals()), on retient la
     * valeur maximale comme PI « courant » — l'ordre des étiquettes Jira n'étant
     * pas garanti, max() est plus fiable que la dernière valeur du tableau.
     * Ex : 19 pour une feature affectée à [15, 19].
     */
    public function getPlanningInterval(): ?int
    {
        $pis = $this->getPlanningIntervals();
        return $pis ? max($pis) : null;
    }

    /**
     * Retourne les valeurs numériques du Planning Interval (multivaleurs).
     *
     * @return int[]  Ex : [8, 10, 11] — vide si non renseigné
     */
    public function getPlanningIntervals(): array
    {
        return self::normalizePlanningIntervalList(
            $this->featureData['fields'][JiraFields::PLANNING_INTERVAL] ?? null
        );
    }

    /**
     * Normalise la valeur brute du champ Planning Interval (customfield_11400) en liste d'entiers.
     *
     * Ce champ est de type « étiquettes » (labels) : multivaleurs, ordre non garanti,
     * au contenu hétérogène selon le Train Jira :
     * - numérique          : "7"                  → 7
     * - alphanumérique     : "PI8"                → 8   (un autre Train préfixe par "PI")
     * - objet select Jira  : ["value" => "PI8"]   → 8
     *
     * L'algorithme retire tous les caractères non numériques avant le cast, ce qui unifie
     * "PI8" et "8" sur la même valeur logique 8. Valeurs vides et doublons écartés.
     *
     * Statique pour être réutilisée par ProjectFeaturesRepository sur la liste brute.
     *
     * @param  mixed $rawField Valeur brute du champ Jira (scalaire, tableau de scalaires ou d'objets)
     * @return int[]           Liste dédoublonnée des PI, ex : [8, 10, 11]
     */
    public static function normalizePlanningIntervalList(mixed $rawField): array
    {
        if ($rawField === null || $rawField === '') {
            return [];
        }

        // Uniformise en tableau pour traiter scalaire et multivaleurs de la même façon
        $items = is_array($rawField) ? $rawField : [$rawField];

        $pis = [];
        foreach ($items as $item) {
            // Objet select Jira : on récupère le libellé exploitable
            if (is_array($item)) {
                $item = $item['value'] ?? $item['name'] ?? null;
            }
            if ($item === null) {
                continue;
            }
            // Retire tout sauf les chiffres : "PI8" => "8", "7" => "7"
            $digits = preg_replace('/\D+/', '', (string) $item);
            if ($digits !== '') {
                $pis[] = (int) $digits;
            }
        }

        return array_values(array_unique($pis));
    }

    /**
     * Retourne l'URL Jira de la Feature.
     */
    public function getUrl(): string
    {
        $key = $this->getKey();
        return $key ? JiraUrl::browse($key) : '#';
    }

    /**
     * Nom de la priorité du ticket Feature (ex. « High », « Medium »), ou null si absent.
     */
    public function getPriorityName(): ?string
    {
        return $this->featureData['fields']['priority']['name'] ?? null;
    }

    /**
     * URL de l'icône de priorité, ou null si absente.
     */
    public function getPriorityIconUrl(): ?string
    {
        return $this->featureData['fields']['priority']['iconUrl'] ?? null;
    }

    /**
     * Date du Jalon (customfield_10075), au format Y-m-d (ex. « 2025-12-31 »), ou null.
     */
    public function getJalon(): ?string
    {
        return $this->featureData['fields'][JiraFields::JALON] ?? null;
    }

    /**
     * Date d'échéance native Jira (champ duedate), au format Y-m-d (ex. « 2025-12-31 »), ou null.
     */
    public function getDueDate(): ?string
    {
        return $this->featureData['fields']['duedate'] ?? null;
    }

    /**
     * Nom de l'Epic lié (customfield_10011), ou null si absent.
     */
    public function getEpicName(): ?string
    {
        return $this->featureData['fields'][JiraFields::EPIC_NAME] ?? null;
    }

    /**
     * Retourne la liste des équipes (customfield_10244).
     *
     * @return string[]  Ex : ["GREEN", "RED"]
     */
    public function getContributingTeams(): array
    {
        return self::normalizeTeamList(
            $this->featureData['fields'][JiraFields::TEAM] ?? null
        );
    }

    /**
     * Normalise la valeur brute du champ Équipe (customfield_10244) en liste de chaînes.
     *
     * Champ multivaleurs supportant les scalaires (string), les tableaux de strings,
     * et les tableaux d'objets Jira (clé 'name' ou 'value'). Valeurs vides et doublons écartés.
     *
     * Statique pour être réutilisée par ProjectFeaturesRepository sur la liste brute.
     *
     * @param  mixed $rawField Valeur brute du champ Jira
     * @return string[]        Ex : ["GREEN", "RED"]
     */
    public static function normalizeTeamList(mixed $rawField): array
    {
        if ($rawField === null || $rawField === '') {
            return [];
        }

        $items = is_array($rawField) ? $rawField : [$rawField];

        $teams = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['value'] ?? null;
            }
            if (is_string($item) && $item !== '') {
                $teams[] = $item;
            }
        }

        return array_values(array_unique($teams));
    }

    /**
     * Description de la Feature rendue en HTML par Jira (via renderedFields), prête à l'affichage ;
     * null si non renseignée.
     */
    public function getDescriptionHtml(): ?string
    {
        return $this->featureData['renderedFields']['description'] ?? null;
    }

    /**
     * Objet Issue représentant le ticket Feature lui-même, pour accéder à ses métriques propres
     * (Lead Time, Cycle Time, etc.) ; null s'il n'a pas été chargé.
     */
    public function getSelfAsIssue(): ?Issue
    {
        return $this->selfAsIssue;
    }

    /**
     * Indique si la Feature est en catégorie de statut « Terminé » (métriques immuables → cache permanent).
     */
    public function isDone(): bool
    {
        return ($this->featureData['fields']['status']['statusCategory']['key'] ?? '') === 'done';
    }

    /* =======================
     * == CACHE (projection) =
     * =======================
     */

    /**
     * Sérialise la Feature en projection légère : métadonnées + ticket Feature lui-même + tickets
     * enfants calculés, SANS changelog (déjà digéré en métriques).
     *
     * @return array<string, mixed> Structure JSON-sérialisable (frontière de cache)
     */
    public function toProjection(): array
    {
        $featureData = $this->featureData;
        unset($featureData['changelog']);

        return [
            'featureData' => $featureData,
            'selfAsIssue' => $this->selfAsIssue?->toProjection(),
            // allIssues = ensemble complet (avant filtrage métier) ; le filtrage est rejoué à l'hydratation.
            'issues'      => array_map(
                static fn (Issue $issue): array => $issue->toProjection(),
                $this->allIssues
            ),
        ];
    }

    /**
     * Reconstruit une Feature depuis une projection (cf. toProjection()) sans aucun accès Jira :
     * ticket Feature et tickets enfants hydratés, puis agrégats recalculés via computeAggregates().
     *
     * @param array<string, mixed> $p Projection produite par toProjection()
     */
    public static function fromProjection(array $p): self
    {
        $feature = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $feature->bootServices();
        $feature->repository  = new ProjectFeaturesRepository();
        $feature->featureData = is_array($p['featureData'] ?? null) ? $p['featureData'] : [];

        $config   = $feature->config;
        $timeline = $feature->timeline;

        $selfData = $p['selfAsIssue'] ?? null;
        $feature->selfAsIssue = is_array($selfData)
            ? Issue::fromProjection($selfData, $config, $timeline)
            : null;

        // computeAggregates() part de $this->issues = ensemble complet, comme loadIssues() en live.
        $issuesData = is_array($p['issues'] ?? null) ? $p['issues'] : [];
        $feature->issues = array_map(
            static fn ($ip): Issue => Issue::fromProjection(is_array($ip) ? $ip : [], $config, $timeline),
            $issuesData
        );
        $feature->issuesCount = count($feature->issues);

        $feature->computeAggregates();

        return $feature;
    }
}
