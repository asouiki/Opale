<?php

namespace App\Model;

/**
 * Planification
 *
 * Agrégat d'aide à la planification : pour un projet donné, rassemble
 *  - le reliquat du sprint EN COURS (sujets non terminés à date),
 *  - le contenu planifié d'un ou plusieurs sprints À VENIR (la « backlog »),
 *
 * afin d'offrir une vue unique de ce qu'il reste à planifier au prochain sprint.
 *
 * Ne recalcule rien : s'appuie entièrement sur le modèle Sprint, ce qui garantit
 * que la définition du reliquat reste identique à celle de la page /sprint
 * (sujets dont le statut n'appartient pas aux « done_statuses » du workflow).
 */
class Planification
{
    /** Sprint en cours analysé. */
    private Sprint $currentSprint;

    /**
     * Sprints à venir retenus pour la backlog.
     *
     * @var array<int, array{
     *     sprintId:string,
     *     name:string,
     *     planned:array{count:int, storyPoints:float, issues:array},
     *     readiness:array{total:int, ready:int, percent:?float}
     * }>
     */
    private array $backlogSprints = [];

    /** Nombre de statuts de la catégorie affinage dans le workflow du projet. */
    private int $refinementCount;

    /**
     * Charge le sprint en cours et les sprints de backlog retenus (dédoublonnés, le sprint en cours
     * étant exclu de la backlog s'il y figure par erreur).
     *
     * @param string   $currentSprintId  ID Agile du sprint en cours
     * @param string[] $backlogSprintIds IDs des sprints à venir à inclure dans la backlog
     * @param string   $projectKey       Clé du projet (pour les URLs de rapport)
     * @param string   $boardId          ID du board (pour les URLs de rapport)
     */
    public function __construct(
        string $currentSprintId,
        array $backlogSprintIds = [],
        string $projectKey = '',
        string $boardId = ''
    ) {
        $this->currentSprint = new Sprint($currentSprintId, $projectKey, $boardId);

        // Nombre de statuts de la catégorie affinage : posé une seule fois pour toute la page.
        // Config::setCurrentProjectKey() a déjà été appelé par PlanificationController::process()
        // avant la construction de cet agrégat, donc getJiraWorkflow() résout bien le workflow projet.
        $this->refinementCount = count((new Config())->getJiraWorkflow()['refinement_statuses'] ?? []);

        // Dédoublonnage + exclusion du sprint en cours s'il est coché par erreur.
        $seen = [$currentSprintId => true];

        foreach ($backlogSprintIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $sprint  = new Sprint($id, $projectKey, $boardId);
            $planned = $sprint->getStats()['reliquat'];

            $this->backlogSprints[] = [
                'sprintId'  => $id,
                'name'      => $sprint->getName(),
                // Pour un sprint à venir, le « reliquat » (non terminé) correspond
                // à l'intégralité des sujets planifiés restant à réaliser.
                'planned'   => $planned,
                'readiness' => self::computeReadiness($planned['issues'], $this->refinementCount),
            ];
        }
    }

    /**
     * Rang du DERNIER statut de la catégorie affinage — celui qui marque le passage à « prêt à
     * planifier » (ex. « A planifier » pour GCS). Un ticket de rang STRICTEMENT inférieur est encore
     * en cours d'affinage.
     *
     * Vaut -1 si le workflow ne déclare aucun statut d'affinage : aucun rang réel (>= 0) ne peut alors
     * l'égaler ni lui être inférieur, donc aucun ticket n'est traité comme « en affinage ».
     */
    public function getLastRefinementRank(): int
    {
        return $this->refinementCount - 1;
    }

    /**
     * Part des sujets prêts à être embarqués dans un bucket de sujets planifiés : un ticket encore en
     * cours d'affinage (statut d'affinage autre que le DERNIER de la catégorie) n'est pas prêt ; à
     * partir de ce dernier statut il est planifiable, et au-delà il est déjà démarré.
     *
     * `statusRank` (déjà calculé par SprintTicketBuilder, indexé sur affinage + sprint + terminé) rend
     * la comparaison robuste à la casse/aux accents d'un nom de statut : un statut hors workflow
     * (`PHP_INT_MAX`) est compté PRÊT, puisqu'il n'appartient précisément pas à la catégorie affinage.
     *
     * @param  list<array{statusRank?:int}> $issues          Tickets du bucket
     * @param  int                          $refinementCount Nb de statuts de la catégorie affinage
     * @return array{total:int, ready:int, percent:?float}   percent null si le bucket est vide
     */
    public static function computeReadiness(array $issues, int $refinementCount): array
    {
        $total = count($issues);
        if ($total === 0) {
            return ['total' => 0, 'ready' => 0, 'percent' => null];
        }

        $lastRefinementRank = $refinementCount - 1;
        $ready              = 0;
        foreach ($issues as $issue) {
            $rank = $issue['statusRank'] ?? PHP_INT_MAX;
            if ($rank >= $lastRefinementRank) {
                $ready++;
            }
        }

        return [
            'total'   => $total,
            'ready'   => $ready,
            'percent' => round($ready / $total * 100, 1),
        ];
    }

    /**
     * Nom du sprint en cours.
     */
    public function getCurrentSprintName(): string
    {
        return $this->currentSprint->getName();
    }

    /**
     * Indique si le sprint « en cours » est réellement actif (non clôturé) dans Jira.
     *
     * Règle métier : déplacer le reliquat d'un sprint ENCORE ACTIF vers le sprint suivant fausse définitivement ses métriques de clôture
     * (atterrissage, prédictibilité, vélocité), puisque Jira ne verra plus que les tickets terminés au moment de la clôture.
     *
     * Sur un sprint déjà clôturé, le déplacement est sans conséquence : l'avertissement de la page ne doit alors PAS se déclencher,
     * sous peine de redevenir un message permanent que l'on n'apprend plus à lire.
     */
    public function isCurrentSprintActive(): bool
    {
        return $this->currentSprint->getState() === Sprint::STATE_ACTIVE;
    }

    /**
     * Reliquat du sprint en cours : sujets non terminés à date.
     *
     * @return array{count:int, storyPoints:float, issues:array}
     */
    public function getCurrentSprintReliquat(): array
    {
        return $this->currentSprint->getStats()['reliquat'];
    }

    /**
     * Sprints à venir retenus, avec leurs sujets planifiés.
     *
     * @return array<int, array{sprintId:string, name:string, planned:array, readiness:array{total:int, ready:int, percent:?float}}>
     */
    public function getBacklogSprints(): array
    {
        return $this->backlogSprints;
    }

    /**
     * Total agrégé (nb de sujets + Story Points) de la backlog des sprints à venir.
     *
     * @return array{count:int, storyPoints:float}
     */
    public function getBacklogTotals(): array
    {
        $count       = 0;
        $storyPoints = 0.0;

        foreach ($this->backlogSprints as $sprint) {
            $count       += $sprint['planned']['count'];
            $storyPoints += $sprint['planned']['storyPoints'];
        }

        return ['count' => $count, 'storyPoints' => $storyPoints];
    }
}
