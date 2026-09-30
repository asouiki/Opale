<?php

namespace App\Model;

use App\Service\JiraReleaseService;
use Exception;

/**
 * Version
 *
 * Agrégat d'issues Jira rattachées à :
 * - une Version (fixVersion) — résolution par ID numérique ou nom exact
 * - une issue de type "Release" — résolution par Key (ABC-123) ou nom exact
 *
 * Détection automatique du type à partir de la saisie :
 * - numérique         → fixVersion ID
 * - regex [A-Z]+-\d+ → Release issue (Key)
 * - sinon texte       → recherche dans les deux caches (Version + Release)
 *
 * Hérite des métriques via AbstractIssueCollection.
 */
class Version extends AbstractIssueCollection
{
    private array $versionData = [];
    private array $versionIssuesIds = [];

    /** Identifiant résolu utilisé pour loadIssues() : ID numérique (fixVersion) ou Key (Release). */
    private string $resolvedVersionId = '';

    /** True si la collection cible une issue de type Release (parent=...), false si une fixVersion. */
    private bool $isRelease = false;

    private ?JiraReleaseService $jiraReleaseService = null;

    /** Projet porteur transmis par l'appelant (deep-link) pour résoudre une Key hors watched_projects. */
    private string $projectHint = '';

    /**
     * @param string $id          ID numérique fixVersion, Key Release (ABC-123) ou nom exact
     * @param string $projectHint Clé du projet porteur, utilisée en repli pour résoudre une Key Release
     *                            absente des watched_projects (ex: Release d'un projet parent)
     */
    public function __construct(string $id, string $projectHint = '')
    {
        $this->projectHint = trim($projectHint);
        parent::__construct($id);
    }

    /**
     * {@inheritdoc}
     *
     * Charge les métadonnées soit d'une fixVersion soit d'une issue Release,
     * selon la nature de la saisie utilisateur.
     *
     * @param  mixed $id ID numérique fixVersion, Key Release (ABC-123), ou nom exact
     * @throws \DomainException Si la résolution échoue (0 ou N résultats)
     * @throws \Exception       Si l'API Jira retourne une réponse invalide
     */
    protected function loadCollectionData(mixed $id): void
    {
        $refresh = !empty($_POST['refresh']);
        $idStr   = trim((string) $id);

        // 1) Key Release (ex: ABC-123) — détecté avant numérique car spécifique
        if (preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $idStr)) {
            $this->loadAsRelease($idStr, $refresh);
            return;
        }

        // 2) ID numérique de fixVersion — résolution directe par API
        if (is_numeric($idStr)) {
            $result = $this->jiraService->getVersionById($idStr);
            $this->applyVersionData($result);
            return;
        }

        // 3) Texte libre — recherche dans les deux caches (Version + Release)
        $versions = $this->jiraVersionService->findAllByName($idStr, $refresh);
        $releases = $this->getReleaseService()->findAllByName($idStr, $refresh);

        $total = count($versions) + count($releases);

        if ($total === 0) {
            throw new \DomainException(
                "Aucune Release ou Version trouvée pour le nom \"{$idStr}\". Utilisez les listes déroulantes par projet pour affiner votre recherche."
            );
        }

        if ($total > 1) {
            $projectIds = array_merge(
                array_column($versions, 'projectId'),
                array_column($releases, 'projectKey')
            );
            throw new \DomainException(
                "Plusieurs Releases ou Versions trouvées pour le nom \"{$idStr}\" (projets : " . implode(', ', $projectIds) . '). Utilisez les listes déroulantes par projet pour préciser votre recherche.'
            );
        }

        // Un et un seul résultat — déterminer la provenance
        if (count($versions) === 1) {
            $this->applyVersionData($versions[0]);
        } else {
            $this->applyReleaseData($releases[0]);
        }
    }

    /**
     * Résout une Key Release via le cache JiraReleaseService et alimente versionData.
     *
     * @throws \DomainException Si aucune Release ne correspond à la Key
     */
    private function loadAsRelease(string $key, bool $refresh): void
    {
        $entry = $this->getReleaseService()->findByKey($key, $refresh, $this->projectHint);

        if ($entry === null) {
            throw new \DomainException(
                "Aucune Release trouvée pour la Key \"{$key}\". Vérifiez la saisie ou utilisez la liste déroulante par projet."
            );
        }

        $this->applyReleaseData($entry);
    }

    /**
     * Alimente versionData depuis le payload fixVersion (API /version/{id} ou cache
     * JiraVersionService).
     *
     * @throws \Exception Si le payload fixVersion est invalide (id manquant)
     */
    private function applyVersionData(array $result): void
    {
        if (empty($result['id'])) {
            throw new Exception("Réponse inattendue de l'API Jira lors de la récupération de la Version.");
        }

        $this->resolvedVersionId = (string) $result['id'];
        $this->isRelease         = false;

        $result['version_url'] = JiraUrl::version(
            (string) ($result['projectId'] ?? ''),
            (string) $result['id']
        );

        $this->versionData = $result;
    }

    /**
     * Alimente versionData depuis une entrée Release du cache JiraReleaseService.
     */
    private function applyReleaseData(array $entry): void
    {
        $this->resolvedVersionId = $entry['key'];
        $this->isRelease         = true;

        $url = JiraUrl::browse($entry['key']);

        $this->versionData = [
            'id'          => $entry['key'],
            'name'        => $entry['name'],
            'description' => $entry['description'] ?? null,
            'projectId'   => $entry['projectId'] ?? null,
            'version_url' => $url,
            'released'    => (bool) ($entry['released'] ?? false),
            'overdue'     => false,
            // startDate / releaseDate non disponibles sur une issue Release
        ];
    }

    /**
     * Instancie JiraReleaseService à la demande (lazy).
     */
    private function getReleaseService(): JiraReleaseService
    {
        if ($this->jiraReleaseService === null) {
            $this->jiraReleaseService = new JiraReleaseService();
        }
        return $this->jiraReleaseService;
    }

    /**
     * {@inheritdoc}
     *
     * - fixVersion : JQL `fixVersion = {id}` (deux étapes : IDs puis détails+changelog)
     * - Release    : JQL `parent = {key}` (en une seule étape via getIssuesByParent)
     *
     * @param string $id Non utilisé (présent pour satisfaire le contrat abstrait)
     *
     * @throws \Exception Si la récupération des tickets de la Release ou Version échoue
     */
    protected function loadIssues(string $id): void
    {
        try {
            if ($this->isRelease) {
                // Issues liées via issuelinks (Composition ou autre type) — pas parent/subtask
                $rawIssues = $this->jiraService->getIssuesByLinkedIssue($this->resolvedVersionId);
            } else {
                $this->loadIssueIds($this->resolvedVersionId);
                $rawIssues = $this->jiraService->getIssuesDetails($this->versionIssuesIds);
            }

            foreach ($rawIssues as $rawIssueData) {
                $this->issues[] = new Issue($rawIssueData);
            }

            $this->issuesCount = count($this->issues);
        } catch (Exception $e) {
            throw new Exception(
                'Erreur lors de la récupération des tickets de la Release ou Version : ' . $e->getMessage()
            );
        }
    }

    /**
     * Charge les IDs des issues d'une fixVersion via JQL fixVersion.
     *
     * @param string $versionId ID numérique fixVersion
     */
    private function loadIssueIds(string $versionId): void
    {
        $issues = $this->jiraService->getIssuesIdsByVersion($versionId);

        $this->versionIssuesIds = array_map(
            static fn ($issue) => $issue['id'] ?? '',
            $issues
        );
    }

    // ==================== Getters Version-spécifiques ====================

    /**
     * {@inheritdoc}
     *
     * La page affiche TOUS les tickets rattachés, quel que soit leur type.
     *
     * @return Issue[]
     */
    public function getIssues(): array
    {
        return $this->allIssues;
    }

    /**
     * {@inheritdoc}
     */
    public function getIssuesCount(): int
    {
        return count($this->allIssues);
    }

    public function getId(): ?string
    {
        return $this->versionData['id'] ?? null;
    }
    public function getName(): ?string
    {
        return $this->versionData['name'] ?? null;
    }
    public function getDescription(): ?string
    {
        return $this->versionData['description'] ?? null;
    }
    public function getUrl(): string
    {
        return $this->versionData['version_url'] ?? '#';
    }
    public function getStartDate(): ?string
    {
        return $this->versionData['startDate'] ?? null;
    }
    public function getReleaseDate(): ?string
    {
        return $this->versionData['releaseDate'] ?? null;
    }
    public function isReleased(): bool
    {
        return (bool) ($this->versionData['released'] ?? false);
    }
    public function isOverdue(): bool
    {
        return (bool) ($this->versionData['overdue'] ?? false);
    }
    public function getProjectId(): mixed
    {
        return $this->versionData['projectId'] ?? null;
    }
    public function isRelease(): bool
    {
        return $this->isRelease;
    }

    /* =======================
     * == CACHE (projection) =
     * =======================
     */

    /**
     * Sérialise la Version en projection légère : métadonnées + tickets liés calculés (sans changelog).
     */
    public function toProjection(): array
    {
        return [
            'versionData'       => $this->versionData,
            'resolvedVersionId' => $this->resolvedVersionId,
            'isRelease'         => $this->isRelease,
            // allIssues = ensemble complet (avant filtrage métier) ; le filtrage est rejoué à l'hydratation.
            'issues'            => array_map(
                static fn (Issue $issue): array => $issue->toProjection(),
                $this->allIssues
            ),
        ];
    }

    /**
     * Reconstruit une Version depuis une projection (cf. toProjection()) sans aucun accès Jira :
     * les tickets liés sont hydratés, puis les agrégats recalculés via le commun computeAggregates().
     *
     * @param array $p Projection produite par toProjection()
     */
    public static function fromProjection(array $p): self
    {
        $version = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $version->bootServices();

        $version->versionData       = $p['versionData'] ?? [];
        $version->resolvedVersionId = (string) ($p['resolvedVersionId'] ?? '');
        $version->isRelease         = (bool) ($p['isRelease'] ?? false);

        // computeAggregates() part de $this->issues = ensemble complet, comme loadIssues() en live.
        $config   = $version->config;
        $timeline = $version->timeline;
        $version->issues = array_map(
            static fn (array $ip): Issue => Issue::fromProjection($ip, $config, $timeline),
            $p['issues'] ?? []
        );
        $version->issuesCount = count($version->issues);

        $version->computeAggregates();

        return $version;
    }
}
