<?php

namespace App\Model;

/**
 * JiraFields
 *
 * Registre unique des identifiants de custom fields Jira utilisés par l'application.
 * Toute valeur « customfield_XXXX » doit être déclarée ici et référencée via
 * JiraFields::*, jamais réécrite en dur ailleurs (source de vérité centrale).
 */
final class JiraFields
{
    /** Story Points : estimation initiale du ticket. */
    public const STORY_POINTS = 'customfield_10024';

    /** Story Points restants (« Reste à faire »). */
    public const STORY_POINTS_REMAINING = 'customfield_10090';

    /** Story Points rééstimés (valeur finale). */
    public const STORY_POINTS_FINAL = 'customfield_10313';

    /** Planning Interval : PI de rattachement d'une Feature. */
    public const PLANNING_INTERVAL = 'customfield_11400';

    /** Équipe (champ multi-valué). */
    public const TEAM = 'customfield_10244';

    /** Jalon : date d'échéance d'une Feature. */
    public const JALON = 'customfield_10075';

    /** Nom de l'Epic lié. */
    public const EPIC_NAME = 'customfield_10011';

    /** Flag (indicateur d'empêchement Jira) : valeur « Impediment » quand le ticket est flaggé. Si non flaggé : valeur = null */
    public const FLAGGED = 'customfield_10021';

    /**
     * Traduit un identifiant de champ personnalisé en sa forme JQL : `customfield_10244` → `cf[10244]`.
     *
     * ⚠️ PIÈGE : l'identifiant REST `customfield_XXXXX` n'est valable que dans le paramètre `fields` de
     * l'API. L'analyseur JQL, lui, n'accepte que le NOM du champ ou la notation `cf[id]` — l'y écrire
     * sous sa forme REST fait échouer la requête ENTIÈRE (« Le champ 'customfield_10244' n'existe pas »),
     * ce qui, derrière un try/catch, se traduit par une liste vide plutôt que par une erreur visible.
     *
     * @param string $customFieldId Identifiant REST (ex: JiraFields::TEAM)
     */
    public static function toJql(string $customFieldId): string
    {
        return 'cf[' . str_replace('customfield_', '', $customFieldId) . ']';
    }
}
