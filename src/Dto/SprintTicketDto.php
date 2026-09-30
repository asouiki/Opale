<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Ticket de sprint enrichi — état RECONSTRUIT à la date de référence du sprint
 * (clôture ou aujourd'hui), source unique de vérité de tous les calculs de Sprint.
 *
 * Réplique typée du tableau historique construit par Sprint::buildTickets() : le DTO circule
 * dans le pipeline de calcul interne (stats, atterrissage, objectifs, prédictibilité) ;
 * `toArray()` restitue la forme array EXACTE attendue par les vues, les phtml et le snapshot
 * JSON des sprints clos (contrat figé par SprintAggregatesTest — ne pas renommer une clé sans
 * bump de METRICS_VERSION).
 */
final readonly class SprintTicketDto
{
    /**
     * @param string                                     $key                 Clé Jira (ex: PROJ-123)
     * @param string                                     $summary             Résumé du ticket
     * @param string                                     $url                 URL Jira du ticket
     * @param string                                     $issueType           Type Jira (Story, Bug, Tâche...)
     * @param string                                     $issueTypeIcon       URL de l'icône du type
     * @param string                                     $status              Statut à la date de référence (libellé normalisé)
     * @param string                                     $statusCategory      'done'|'waiting'|'sprint'|'refinement'|'other'
     * @param int                                        $statusRank          Rang du statut dans le workflow (tri front)
     * @param float|null                                 $storyPoints         SP à la date de référence (null = non estimé)
     * @param float|null                                 $spFinal             SP rééstimés / finaux (null = non renseigné)
     * @param float|null                                 $spRemaining         « Reste à faire » (null = non renseigné)
     * @param string|null                                $parentKey           Clé de la Feature parente
     * @param string|null                                $parentSummary       Résumé de la Feature parente
     * @param array{name: string, icon: string, id: string} $priority         Priorité Jira (nom, icône, id)
     * @param float                                      $cycleTime           1er passage « en cours » → terminé (jours ouvrés)
     * @param float                                      $leadTime            Création → terminé (jours ouvrés)
     * @param float                                      $waitingTime         Total de l'attente BORNÉE au sprint (jours ouvrés)
     * @param array<string, float>                       $waitingTimes        Ventilation par cause, bornée au sprint
     * @param float                                      $waitingTimeFull     Total de l'attente sur TOUT le cycle de vie du ticket
     * @param array<string, float>                       $waitingTimesFull    Ventilation par cause sur tout le cycle de vie
     * @param list<string>                               $labels              Étiquettes à la date de référence
     * @param list<string>                               $sprintNames         Sprints affectés (ordre chronologique)
     * @param bool                                       $isDone              Statut « done » à la date de référence
     * @param bool                                       $addedDuringSprint   Ajouté après le démarrage du sprint
     * @param bool                                       $removedDuringSprint Retiré avant la date de référence
     * @param bool                                       $estimatedAfterAdd   Estimé après son entrée dans le sprint
     */
    public function __construct(
        public string $key,
        public string $summary,
        public string $url,
        public string $issueType,
        public string $issueTypeIcon,
        public string $status,
        public string $statusCategory,
        public int $statusRank,
        public ?float $storyPoints,
        public ?float $spFinal,
        public ?float $spRemaining,
        public ?string $parentKey,
        public ?string $parentSummary,
        public array $priority,
        public float $cycleTime,
        public float $leadTime,
        public float $waitingTime,
        public array $waitingTimes,
        public float $waitingTimeFull,
        public array $waitingTimesFull,
        public array $labels,
        public array $sprintNames,
        public bool $isDone,
        public bool $addedDuringSprint,
        public bool $removedDuringSprint,
        public bool $estimatedAfterAdd,
    ) {
    }

    /**
     * Construit un DTO depuis la forme array historique, tolérante aux clés absentes.
     *
     * Miroir de toArray() : les clés manquantes reçoivent la valeur neutre du pipeline
     * (null pour les SP « non renseignés », PHP_INT_MAX pour le rang d'un statut inconnu).
     *
     * @param array<string, mixed> $data Ticket sous forme array (fixtures de test, snapshot)
     */
    public static function fromArray(array $data): self
    {
        $priority = is_array($data['priority'] ?? null) ? $data['priority'] : [];

        return new self(
            key: (string) ($data['key'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            issueType: (string) ($data['issueType'] ?? ''),
            issueTypeIcon: (string) ($data['issueTypeIcon'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            statusCategory: (string) ($data['statusCategory'] ?? ''),
            statusRank: (int) ($data['statusRank'] ?? PHP_INT_MAX),
            storyPoints: isset($data['storyPoints']) ? (float) $data['storyPoints'] : null,
            spFinal: isset($data['spFinal']) ? (float) $data['spFinal'] : null,
            spRemaining: isset($data['spRemaining']) ? (float) $data['spRemaining'] : null,
            parentKey: isset($data['parentKey']) ? (string) $data['parentKey'] : null,
            parentSummary: isset($data['parentSummary']) ? (string) $data['parentSummary'] : null,
            priority: [
                'name' => (string) ($priority['name'] ?? ''),
                'icon' => (string) ($priority['icon'] ?? ''),
                'id'   => (string) ($priority['id'] ?? ''),
            ],
            cycleTime: (float) ($data['cycleTime'] ?? 0.0),
            leadTime: (float) ($data['leadTime'] ?? 0.0),
            waitingTime: (float) ($data['waitingTime'] ?? 0.0),
            waitingTimes: $data['waitingTimes'] ?? [],
            // Snapshots antérieurs au double affichage : repli sur la valeur bornée, qui ne fait
            // alors qu'afficher deux fois le même chiffre plutôt que d'annoncer un faux zéro.
            waitingTimeFull: (float) ($data['waitingTimeFull'] ?? $data['waitingTime'] ?? 0.0),
            waitingTimesFull: $data['waitingTimesFull'] ?? $data['waitingTimes'] ?? [],
            labels: array_values($data['labels'] ?? []),
            sprintNames: array_values($data['sprintNames'] ?? []),
            isDone: (bool) ($data['isDone'] ?? false),
            addedDuringSprint: (bool) ($data['addedDuringSprint'] ?? false),
            removedDuringSprint: (bool) ($data['removedDuringSprint'] ?? false),
            estimatedAfterAdd: (bool) ($data['estimatedAfterAdd'] ?? false),
        );
    }

    /**
     * Restitue la forme array historique (mêmes clés, même ordre) consommée par les
     * vues, les phtml et le snapshot JSON — contrat de sortie figé.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'                 => $this->key,
            'summary'             => $this->summary,
            'url'                 => $this->url,
            'issueType'           => $this->issueType,
            'issueTypeIcon'       => $this->issueTypeIcon,
            'status'              => $this->status,
            'statusCategory'      => $this->statusCategory,
            'statusRank'          => $this->statusRank,
            'storyPoints'         => $this->storyPoints,
            'spFinal'             => $this->spFinal,
            'spRemaining'         => $this->spRemaining,
            'parentKey'           => $this->parentKey,
            'parentSummary'       => $this->parentSummary,
            'priority'            => $this->priority,
            'cycleTime'           => $this->cycleTime,
            'leadTime'            => $this->leadTime,
            'waitingTime'         => $this->waitingTime,
            'waitingTimes'        => $this->waitingTimes,
            'waitingTimeFull'     => $this->waitingTimeFull,
            'waitingTimesFull'    => $this->waitingTimesFull,
            'labels'              => $this->labels,
            'sprintNames'         => $this->sprintNames,
            'isDone'              => $this->isDone,
            'addedDuringSprint'   => $this->addedDuringSprint,
            'removedDuringSprint' => $this->removedDuringSprint,
            'estimatedAfterAdd'   => $this->estimatedAfterAdd,
        ];
    }
}
