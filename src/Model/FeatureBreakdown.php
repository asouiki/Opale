<?php

namespace App\Model;

/**
 * FeatureBreakdown
 *
 * Calcul pur (aucun accès Jira) du détail d'une Feature à partir de ses tickets enfants : volume livré
 * (Story Points), qualité (bugs et leur ventilation par étiquette) et nature du travail (répartition par
 * type de ticket, en nombre ET en Story Points).
 *
 * Règles métier :
 * - Total Story Points : somme sur TOUS les types de ticket confondus ; un ticket non estimé compte 0.
 * - Bug : tout ticket dont le NOM DE TYPE contient « bug » (insensible à la casse) — couvre « Bug »,
 *   « Bug Prod », « Bug Qualif », « Sous-tâche Bug » de config_files/jira_issues_types.json.
 * - Ventilation des bugs : toute ÉTIQUETTE contenant « bug » (ex. BUG_Fonctionnel, BUG_Technique,
 *   Bug_Conception) devient une entrée, sans liste en dur — une nouvelle étiquette apparaît d'elle-même.
 *   ⚠️ bugsByLabel n'est PAS une partition de bugCount : un bug portant deux étiquettes « bug » compte
 *   dans les deux, et un bug sans étiquette n'y figure pas du tout. La somme peut donc être supérieure
 *   OU inférieure au nombre de bugs.
 *
 * Le périmètre exact des tickets pris en compte est du ressort de l'appelant : l'onglet Features de
 * /team-metrics ne transmet que les enfants du projet de l'équipe (contribution de l'équipe), la page
 * /feature les transmet tous (cross-équipes).
 *
 * @phpstan-type Breakdown array{issuesCount: int, totalStoryPoints: float, bugCount: int, bugStoryPoints: float, bugsByLabel: array<string, int>, byType: list<array{type: string, count: int, storyPoints: float}>}
 */
final class FeatureBreakdown
{
    /** Mot-clef repéré dans un nom de type ou une étiquette pour qualifier un bug (comparaison insensible à la casse). */
    private const BUG_KEYWORD = 'bug';

    /**
     * Calcule le détail d'un ensemble de tickets enfants.
     *
     * Une collection vide retourne une structure neutre (compteurs à zéro, byType vide) : l'appelant peut
     * l'exploiter sans test préalable, et le front n'a rien à afficher.
     *
     * @param  Issue[]   $issues Tickets enfants de la Feature, déjà filtrés par l'appelant
     * @return Breakdown         Détail agrégé, byType trié par nombre de tickets décroissant
     */
    public static function fromIssues(array $issues): array
    {
        $totalStoryPoints = 0.0;
        $bugCount         = 0;
        $bugStoryPoints   = 0.0;

        /** @var array<string, array{count: int, storyPoints: float}> $byType */
        $byType = [];
        /** @var array<string, int> $bugsByLabel */
        $bugsByLabel = [];

        foreach ($issues as $issue) {
            $type        = $issue->getIssueTypeName();
            $storyPoints = $issue->getStoryPoints(JiraFields::STORY_POINTS) ?? 0.0;

            $totalStoryPoints += $storyPoints;

            $byType[$type]['count']       = ($byType[$type]['count'] ?? 0) + 1;
            $byType[$type]['storyPoints'] = ($byType[$type]['storyPoints'] ?? 0.0) + $storyPoints;

            if (!self::mentionsBug($type)) {
                continue;
            }

            $bugCount++;
            $bugStoryPoints += $storyPoints;

            $bugLabels = array_filter(
                $issue->getLabels(),
                static fn (string $label): bool => self::mentionsBug($label)
            );

            foreach ($bugLabels as $label) {
                $bugsByLabel[$label] = ($bugsByLabel[$label] ?? 0) + 1;
            }
        }

        // Étiquettes les plus fréquentes en tête (ordre d'affichage de la liste des bugs).
        arsort($bugsByLabel);

        return [
            'issuesCount'      => count($issues),
            'totalStoryPoints' => round($totalStoryPoints, 2),
            'bugCount'         => $bugCount,
            'bugStoryPoints'   => round($bugStoryPoints, 2),
            'bugsByLabel'      => $bugsByLabel,
            'byType'           => self::sortTypes($byType),
        ];
    }

    /**
     * Indique si un libellé (nom de type ou étiquette) porte le mot-clef « bug », quelle que soit sa casse.
     */
    private static function mentionsBug(string $label): bool
    {
        return stripos($label, self::BUG_KEYWORD) !== false;
    }

    /**
     * Met la répartition par type à plat et la trie par nombre de tickets décroissant, puis par nom de
     * type (départage stable, indispensable à un ordre de parts reproductible dans le camembert).
     *
     * @param  array<string, array{count: int, storyPoints: float}>  $byType
     * @return list<array{type: string, count: int, storyPoints: float}>
     */
    private static function sortTypes(array $byType): array
    {
        $types = [];
        foreach ($byType as $name => $aggregate) {
            $types[] = [
                'type'        => (string) $name,
                'count'       => $aggregate['count'],
                'storyPoints' => round($aggregate['storyPoints'], 2),
            ];
        }

        usort($types, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['type'], $b['type']);
        });

        return $types;
    }
}
