<?php

namespace Tests\Utils;

use App\Model\Config;
use App\Model\Issue;
use App\Model\JiraFields;
use App\Model\Timeline;

/**
 * IssueBuilder
 *
 * Construit des objets Issue avec des changelogs synthétiques pour les tests.
 */
class IssueBuilder
{
    private string $status  = 'In Progress';
    private string $created = '2025-01-01T08:00:00+00:00';
    private ?string $resolutionDate = null;
    private array $histories = [];
    private Config $config;

    /** Valeur courante du champ Story Points (customfield), ou null si non estimé. */
    private ?float $storyPoints = null;

    /** Valeur courante du champ « Reste à faire » (customfield), ou null si non renseigné. */
    private ?float $spRemaining = null;

    /** Valeur courante du champ « Story Points final » (customfield), ou null si non renseigné. */
    private ?float $spFinal = null;

    /** Étiquettes courantes de l'issue. */
    private array $labels = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withCreatedDate(string $isoDate): self
    {
        $clone = clone $this;
        $clone->created = $isoDate;
        return $clone;
    }

    public function withResolutionDate(string $isoDate): self
    {
        $clone = clone $this;
        $clone->resolutionDate = $isoDate;
        return $clone;
    }

    /**
     * Ajoute un changement de statut dans le changelog.
     */
    public function withStatusTransition(string $isoDate, string $from, string $to): self
    {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [
                ['field' => 'status', 'fromString' => $from, 'toString' => $to]
            ],
        ];
        return $clone;
    }

    /**
     * Ajoute un changement de labels dans le changelog.
     * @param string $fromLabels Labels avant (séparés par espace, comme Jira)
     * @param string $toLabels   Labels après (séparés par espace, comme Jira)
     */
    public function withLabelChange(string $isoDate, string $fromLabels, string $toLabels): self
    {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [
                ['field' => 'labels', 'fromString' => $fromLabels, 'toString' => $toLabels]
            ],
        ];
        return $clone;
    }

    /**
     * Définit la valeur courante (live) du champ Story Points.
     */
    public function withStoryPoints(?float $storyPoints): self
    {
        $clone = clone $this;
        $clone->storyPoints = $storyPoints;
        return $clone;
    }

    /**
     * Définit la valeur courante (live) du champ « Reste à faire ».
     */
    public function withSpRemaining(?float $spRemaining): self
    {
        $clone = clone $this;
        $clone->spRemaining = $spRemaining;
        return $clone;
    }

    /**
     * Définit la valeur courante (live) du champ « Story Points final ».
     */
    public function withSpFinal(?float $spFinal): self
    {
        $clone = clone $this;
        $clone->spFinal = $spFinal;
        return $clone;
    }

    /**
     * Définit les étiquettes courantes (live) de l'issue.
     *
     * @param string[] $labels
     */
    public function withLabels(array $labels): self
    {
        $clone = clone $this;
        $clone->labels = $labels;
        return $clone;
    }

    /**
     * Ajoute un changement de Story Points dans le changelog.
     *
     * Reproduit le format Jira pour un champ personnalisé numérique : la valeur
     * lisible est dans `fromString`/`toString`, et `from`/`to` restent null.
     *
     * @param string|null $from    Valeur avant (chaîne, null = champ vide)
     * @param string|null $to      Valeur après (chaîne, null = champ vide)
     * @param string      $fieldId Champ Jira concerné (par défaut Story Points standard)
     */
    public function withStoryPointChange(
        string $isoDate,
        ?string $from,
        ?string $to,
        string $fieldId = JiraFields::STORY_POINTS
    ): self {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [[
                'field'      => 'Story Points',
                'fieldId'    => $fieldId,
                'from'       => null,
                'to'         => null,
                'fromString' => $from,
                'toString'   => $to,
            ]],
        ];
        return $clone;
    }

    /**
     * Ajoute un changement du flag Jira (customfield « Flagged ») dans le changelog.
     *
     * Reproduit le format Jira : `toString` = « Impediment » à la pose, chaîne vide au retrait.
     *
     * @param string $from Valeur avant (« Impediment » ou "")
     * @param string $to   Valeur après (« Impediment » ou "")
     */
    public function withFlagChange(string $isoDate, string $from, string $to): self
    {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [[
                'field'      => 'Flagged',
                'fieldId'    => JiraFields::FLAGGED,
                'from'       => null,
                'to'         => null,
                'fromString' => $from,
                'toString'   => $to,
            ]],
        ];
        return $clone;
    }

    /**
     * Ajoute un changement du champ « Sprint » dans le changelog.
     *
     * @param string $from IDs de sprint avant (ex: "" ou "100, 101")
     * @param string $to   IDs de sprint après
     */
    public function withSprintChange(string $isoDate, string $from, string $to): self
    {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [[
                'field'      => 'Sprint',
                'from'       => $from,
                'to'         => $to,
                'fromString' => $from,
                'toString'   => $to,
            ]],
        ];
        return $clone;
    }

    /**
     * Ajoute un changement du champ « Sprint » en dissociant IDs et noms lisibles.
     *
     * Reproduit le format Jira où `from`/`to` portent les IDs et
     * `fromString`/`toString` les noms — utile pour simuler un renommage de
     * sprint (même ID, nom différent) que `withSprintChange()` ne permet pas.
     *
     * @param string $fromIds   IDs avant (ex: "" ou "100, 101")
     * @param string $toIds     IDs après
     * @param string $fromNames Noms avant, alignés positionnellement sur $fromIds
     * @param string $toNames   Noms après, alignés positionnellement sur $toIds
     */
    public function withSprintChangeNamed(string $isoDate, string $fromIds, string $toIds, string $fromNames, string $toNames): self
    {
        $clone = clone $this;
        $clone->histories[] = [
            'created' => $isoDate,
            'items'   => [[
                'field'      => 'Sprint',
                'from'       => $fromIds,
                'to'         => $toIds,
                'fromString' => $fromNames,
                'toString'   => $toNames,
            ]],
        ];
        return $clone;
    }

    public function build(): Issue
    {
        $data = [
            'key'    => 'TEST-1',
            'fields' => [
                'summary'        => 'Test issue',
                'status'         => ['name' => $this->status, 'statusCategory' => ['colorName' => 'green']],
                'issuetype'      => ['name' => 'Story', 'iconUrl' => ''],
                'priority'       => ['name' => 'Medium', 'iconUrl' => ''],
                'created'        => $this->created,
                'resolutiondate' => $this->resolutionDate,
                'labels'         => $this->labels,
                JiraFields::STORY_POINTS           => $this->storyPoints,
                JiraFields::STORY_POINTS_REMAINING => $this->spRemaining,
                JiraFields::STORY_POINTS_FINAL     => $this->spFinal,
            ],
            'changelog' => [
                'histories' => $this->histories,
            ],
        ];

        $timeline = new Timeline($this->config);
        return new Issue($data, $this->config, $timeline);
    }
}
