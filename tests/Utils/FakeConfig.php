<?php

namespace Tests\Utils;

use App\Model\Config;

/**
 * FakeConfig
 *
 * Fournit un workflow et des jours fériés déterministes pour les tests.
 * Évite tout appel HTTP à GovApiService.
 */
class FakeConfig extends Config
{
    /** @var string[] */
    private array $nonWorkingDays;

    /** @var array */
    private array $workflow;

    /** @var string[] Noms de sprints « fictifs » à ignorer (déterministe, sans disque). */
    private array $ignoredSprints = [];

    public function __construct(array $nonWorkingDays = [], array $workflow = [])
    {
        // Ne pas appeler parent::__construct() — Config n'a pas de constructeur propre
        $this->nonWorkingDays = $nonWorkingDays;
        $this->workflow       = $workflow ?: self::defaultWorkflow();
    }

    /**
     * Retourne une copie configurée avec la liste des sprints à ignorer.
     *
     * @param  string[] $names
     */
    public function withIgnoredSprints(array $names): self
    {
        $clone = clone $this;
        $clone->ignoredSprints = $names;
        return $clone;
    }

    public function getIgnoredSprintNames(): array
    {
        return $this->ignoredSprints;
    }

    public function getNonWorkingDays(): array
    {
        return $this->nonWorkingDays;
    }

    public function getJiraWorkflow(string $issueType = ''): array
    {
        return $this->workflow;
    }

    public static function defaultWorkflow(): array
    {
        return [
            'refinement_statuses' => ['To Do'],
            'sprint_statuses'     => ['À faire', 'In Progress'],
            'done_statuses'       => ['Done'],
            'waiting_statuses'    => ['En Attente'],
        ];
    }
}
