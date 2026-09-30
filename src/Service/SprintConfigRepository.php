<?php

namespace App\Service;

/**
 * SprintConfigRepository
 *
 * Persiste les réglages par sprint saisis manuellement (hors Jira) : aujourd'hui
 * les jours/homme. Conservé pour ne pas changer ses appelants (SprintController,
 * ManDaysController, TeamMetrics), il délègue désormais le stockage à
 * \App\Service\TeamConfigRepository (clé "sprints" de <KEY>_team_config.json).
 *
 * @see \App\Service\TeamConfigRepository Fichier unique de config par projet.
 */
class SprintConfigRepository
{
    /** Hub d'I/O du fichier de config du projet. */
    private TeamConfigRepository $teamConfig;

    /**
     * @param string|null $configDir Répertoire de stockage (tests) ; null = défaut.
     */
    public function __construct(?string $configDir = null)
    {
        $this->teamConfig = new TeamConfigRepository($configDir);
    }

    /**
     * Retourne la map complète des réglages d'un projet (sprintId → réglages), jours/homme unifiés.
     *
     * L'onglet Capacité fait foi : pour chaque sprint doté d'une grille de capacité exploitable, son
     * total (capaTotal) surcharge (ou crée) la valeur manDays, et un drapeau manDaysFromCapacity=true
     * signale la source (champ en lecture seule côté UI).
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @return array<string, array> Ex: ['10496' => ['manDays' => 25.0, 'manDaysFromCapacity' => true], ...]
     */
    public function getAll(string $projectKey): array
    {
        $settings = $this->teamConfig->getAllSprintSettings($projectKey);

        foreach ($this->teamConfig->getAllCapacityManDays($projectKey) as $sprintId => $capaTotal) {
            $settings[$sprintId]['manDays']             = $capaTotal;
            $settings[$sprintId]['manDaysFromCapacity'] = true;
        }

        return $settings;
    }

    /**
     * Retourne les jours/homme effectifs d'un sprint, ou null si aucune source.
     *
     * L'onglet Capacité fait foi : le total de la grille (capaTotal) prime sur la saisie manuelle ;
     * à défaut de grille exploitable, repli sur la valeur saisie.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function getManDays(string $projectKey, string $sprintId): ?float
    {
        return $this->teamConfig->getCapacityManDays($projectKey, $sprintId)
            ?? $this->teamConfig->getManDays($projectKey, $sprintId);
    }

    /**
     * Indique si les jours/homme du sprint proviennent de la grille de capacité (donc en lecture seule
     * côté UI, la saisie manuelle étant alors ignorée).
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function hasCapacityManDays(string $projectKey, string $sprintId): bool
    {
        return $this->teamConfig->getCapacityManDays($projectKey, $sprintId) !== null;
    }

    /**
     * Enregistre (ou efface) les jours/homme d'un sprint, sans toucher aux autres.
     *
     * @param string     $projectKey Clé du projet Jira (ex: "ABC")
     * @param string     $sprintId   ID Agile du sprint
     * @param float|null $manDays    Jours/homme, ou null/<= 0 pour effacer
     *
     * @throws \RuntimeException Si l'écriture échoue
     */
    public function setManDays(string $projectKey, string $sprintId, ?float $manDays): void
    {
        $this->teamConfig->setManDays($projectKey, $sprintId, $manDays);
    }

    /**
     * Retourne la capacité en points saisie pour un sprint donné, ou null si absente.
     *
     * @param string $projectKey Clé du projet Jira (ex: "ABC")
     * @param string $sprintId   ID Agile du sprint
     */
    public function getCapacityPoints(string $projectKey, string $sprintId): ?float
    {
        return $this->teamConfig->getCapacityPoints($projectKey, $sprintId);
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
        $this->teamConfig->setCapacityPoints($projectKey, $sprintId, $capacityPoints);
    }
}
