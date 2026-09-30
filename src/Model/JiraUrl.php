<?php

declare(strict_types=1);

namespace App\Model;

/**
 * JiraUrl
 *
 * Constructeur unique des URLs de l'instance Jira. Centralise la convention (base rtrim + segments
 * rawurlencodés) auparavant dispersée en 3 styles (str_replace / concat brut / rtrim+rawurlencode).
 *
 * Ne couvre que les URLs Jira paramétriques réutilisées ; les liens applicatifs internes
 * (/release, /feature...) et le lien « rapport rétrospective » (mono-usage) restent chez l'appelant.
 */
final class JiraUrl
{
    /**
     * Page « browse » d'une issue ou d'une Release (`{base}/browse/{key}`).
     */
    public static function browse(string $key): string
    {
        return self::base() . '/browse/' . rawurlencode($key);
    }

    /**
     * Page d'une fixVersion (`{base}/projects/{projectId}/versions/{versionId}`).
     */
    public static function version(string $projectId, string $versionId): string
    {
        return self::base() . '/projects/' . rawurlencode($projectId) . '/versions/' . rawurlencode($versionId);
    }

    /**
     * URL de base de l'instance Jira, sans slash final.
     */
    private static function base(): string
    {
        return rtrim(Config::jiraBaseUrl(), '/');
    }
}
