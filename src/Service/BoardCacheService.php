<?php

namespace App\Service;

/**
 * BoardCacheService
 *
 * Cache fichier des boards d'un projet et des sprints d'un board (cache/jira_boards.json).
 *
 * Ces deux listes alimentent le sélecteur de sprint présent sur /sprint, /team-metrics, /planification,
 * /capacity et /config : elles étaient donc redemandées à Jira à CHAQUE chargement de l'une de ces pages,
 * alors qu'elles ne bougent qu'à la création d'un sprint ou d'un board. Le coût réel dépassait deux appels,
 * les deux endpoints Agile étant paginés (cf. JiraService::fetchAllPaginated) : une équipe ancienne, riche
 * en sprints clôturés, payait plusieurs allers-retours par page.
 *
 * TTL calé sur une journée de travail (12 h ≈ 8 h travaillées + pause méridienne) : ces listes ne changent
 * qu'à la création d'un sprint, soit toutes les deux semaines environ, et cette durée ramène le
 * rapatriement à une fois par jour et par poste au lieu de plusieurs.
 *
 * Contrepartie assumée : un sprint créé le matin reste invisible jusqu'au lendemain. C'est acceptable
 * PARCE QUE les cinq sélecteurs portent un CTA « Rafraîchir la liste » ({@see $refresh}) : le recours est
 * immédiat et visible. Sans ce bouton, un TTL aussi long serait à proscrire — l'utilisateur n'a aucun
 * moyen de deviner qu'il regarde une liste périmée.
 *
 * Ce sont les réponses BRUTES de Jira qui sont mises en cache, pas les listes normalisées par
 * BoardRepository : la normalisation incorpore le board préféré et les sprints ignorés, lus dans la
 * configuration d'équipe. Les cacher figerait ces réglages jusqu'à expiration, alors qu'ils doivent
 * prendre effet immédiatement.
 *
 * Expiration PAR ENTRÉE (et non globale au fichier) : sans cela, le premier projet mis en cache rendrait
 * « frais » un fichier ne contenant pas encore les autres.
 */
class BoardCacheService extends AbstractJiraCacheService
{
    /** 12 h : une journée de travail, soit un rapatriement par jour et par poste. */
    protected const TTL = 43200;

    private const CACHE_FILE = __DIR__ . '/../../cache/jira_boards.json';

    protected function cacheFilePath(): string
    {
        return self::CACHE_FILE;
    }

    /**
     * Boards bruts du projet, servis du cache tant qu'ils ont moins de TTL secondes.
     *
     * @param  string $projectKey Clé du projet Jira (ex: "ABC")
     * @param  bool   $refresh    Ignore le cache et redemande à Jira (CTA « rafraîchir la liste »)
     * @return array<int, array<string, mixed>> Boards bruts [{id, name, type}, ...]
     *
     * @throws \Throwable Toute erreur Jira est propagée (cf. remember())
     */
    public function getBoards(string $projectKey, bool $refresh = false): array
    {
        return $this->remember('boards', $projectKey, $refresh, fn (): array => $this->jira->getBoardsByProject($projectKey));
    }

    /**
     * Sprints bruts du board, servis du cache tant qu'ils ont moins de TTL secondes.
     *
     * @param  string $boardId ID du board Agile
     * @param  bool   $refresh Ignore le cache et redemande à Jira (CTA « rafraîchir la liste »)
     * @return array<int, array<string, mixed>> Sprints bruts [{id, name, state, startDate, endDate}, ...]
     *
     * @throws \Throwable Toute erreur Jira est propagée (cf. remember())
     */
    public function getSprints(string $boardId, bool $refresh = false): array
    {
        return $this->remember('sprints', $boardId, $refresh, fn (): array => $this->jira->getSprintsByBoard($boardId));
    }

    /**
     * Sert une entrée depuis le cache, ou la reconstruit via $fetch puis la persiste.
     *
     * Contrairement à JiraStatusCacheService, une erreur Jira n'est PAS avalée : là-bas, une liste vide
     * dégrade un JQL sans mentir à l'utilisateur ; ici, elle afficherait « Aucun sprint trouvé pour ce
     * board », ce qui est faux et indiscernable d'un board réellement vide. On laisse donc remonter :
     * JiraBoardController rend déjà un {success:false, error} explicite.
     *
     * @param  string                            $bucket  Famille d'entrées ('boards' ou 'sprints')
     * @param  string                            $key     Identifiant dans la famille (clé projet ou id board)
     * @param  bool                              $refresh Ignore l'entrée en cache, même non expirée
     * @param  callable(): array<int, array<string, mixed>> $fetch Rapatriement depuis Jira
     * @return array<int, array<string, mixed>>
     */
    private function remember(string $bucket, string $key, bool $refresh, callable $fetch): array
    {
        $this->loadCache();

        $entries = is_array($this->cache[$bucket] ?? null) ? $this->cache[$bucket] : [];
        $entry   = is_array($entries[$key] ?? null) ? $entries[$key] : null;

        if (!$refresh && $entry !== null && (time() - (int) ($entry['fetchedAt'] ?? 0)) <= static::TTL) {
            return is_array($entry['items'] ?? null) ? $entry['items'] : [];
        }

        $items = $fetch();

        $entries[$key]            = ['fetchedAt' => time(), 'items' => $items];
        $this->cache[$bucket]     = $entries;
        $this->cache['updatedAt'] = time();
        $this->saveCache();

        return $items;
    }
}
