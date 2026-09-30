<?php

namespace App\Model;

use Exception;

/**
 * ProgramIncrement
 *
 * Agrégat des Features Jira appartenant à un même Program Increment (PI),
 * identifié par le champ customfield_11400 (alphanumérique, ex: "8" ou "PI8").
 *
 * Hérite de toute la logique d'agrégation des métriques (LeadTime, CycleTime,
 * WaitingTime, timeline par statut) via AbstractIssueCollection.
 *
 * Note : chaque "issue" de la collection EST un ticket Feature traité comme
 * un Issue standard — on n'agrège PAS les tickets enfants des Features,
 * mais bien les métriques propres de chaque Feature.
 */
class ProgramIncrement extends AbstractIssueCollection
{
    /** Clé du projet Jira (ex: "ABC"). */
    private string $projectKey;

    /** Numéro de PI alphanumérique (ex: "8" ou "PI8"). */
    private string $piNumber;

    /** @var string[] Clés Jira des Features à exclure de l'agrégat (ex: ["ABC-123"]) */
    private array $excludedFeatureKeys;

    /**
     * Détails individuels par Feature, pour affichage tableau dans la vue.
     *
     * @var array<int, array{key:string, name:string, status:string, url:string, leadTime:float, cycleTime:float, waitingTime:float, excluded:bool}>
     */
    private array $featuresDetails = [];

    /** Issues brutes Jira, mémorisées entre loadCollectionData et loadIssues. */
    private array $rawFeatures = [];

    /**
     * Mémorise le PI ciblé et les Features exclues, puis déclenche le chargement de la collection.
     *
     * @param string   $projectKey          Clé du projet Jira (ex: "ABC")
     * @param string   $piNumber            Numéro de PI alphanumérique (ex: "8" ou "PI8")
     * @param string[] $excludedFeatureKeys Clés des Features à exclure du cumul
     */
    public function __construct(string $projectKey, string $piNumber, array $excludedFeatureKeys = [])
    {
        $this->projectKey          = $projectKey;
        $this->piNumber            = $piNumber;
        $this->excludedFeatureKeys = $excludedFeatureKeys;

        // Le parent appellera loadCollectionData($piNumber) puis loadIssues($piNumber).
        // On lui passe le piNumber pour conserver une trace dans la signature historique.
        parent::__construct($piNumber);
    }

    /**
     * {@inheritdoc}
     *
     * Récupère toutes les Features du PI via JQL (un seul appel API, embarque le changelog).
     * Le filtre d'exclusion est appliqué ici pour ne pas surcharger AbstractIssueCollection.
     */
    protected function loadCollectionData(string $id): void
    {
        $rawFeatures = $this->jiraService->getFeaturesByProjectAndPI($this->projectKey, $this->piNumber);

        // Exclusion (avant instanciation des Issue pour éviter le calcul inutile)
        $this->rawFeatures = array_values(array_filter(
            $rawFeatures,
            fn (array $f): bool => !in_array($f['key'] ?? '', $this->excludedFeatureKeys, true)
        ));
    }

    /**
     * {@inheritdoc}
     *
     * Pour chaque Feature retenue, instancie un Issue (calcule LeadTime/CycleTime/WaitingTime
     * à partir du changelog) et alimente la collection parente pour l'agrégat.
     * Mémorise en parallèle les détails par Feature pour l'affichage tableau.
     *
     * @throws \Exception Si le calcul des métriques des Features du PI échoue
     */
    protected function loadIssues(string $id): void
    {
        try {
            foreach ($this->rawFeatures as $raw) {
                $issue = new Issue($raw);
                $this->issues[] = $issue;

                $this->featuresDetails[] = [
                    'key'         => $raw['key'] ?? '',
                    'name'        => $raw['fields']['summary'] ?? '',
                    'status'      => $raw['fields']['status']['name'] ?? '',
                    'url'         => JiraUrl::browse($raw['key'] ?? ''),
                    'leadTime'    => $issue->getLeadTime(),
                    'cycleTime'   => $issue->getCycleTime(),
                    'waitingTime' => $this->sumWaitingTimes($issue),
                    'excluded'    => false,
                ];
            }

            $this->issuesCount = count($this->issues);
        } catch (Exception $e) {
            throw new Exception('Erreur lors du calcul des métriques des Features du PI : ' . $e->getMessage());
        }
    }

    /**
     * Temps d'attente total d'une Issue : union dédupliquée des périodes (statuts + labels + flag),
     * sans double comptage des jours recouverts par plusieurs causes.
     */
    private function sumWaitingTimes(Issue $issue): float
    {
        return $issue->getTimeSpentInWaiting();
    }

    // ==================== Getters spécifiques au PI ====================

    public function getProjectKey(): string
    {
        return $this->projectKey;
    }

    public function getPiNumber(): string
    {
        return $this->piNumber;
    }

    /**
     * @return array<int, array{key:string, name:string, status:string, url:string, leadTime:float, cycleTime:float, waitingTime:float, excluded:bool}>
     */
    public function getFeaturesDetails(): array
    {
        return $this->featuresDetails;
    }
}
