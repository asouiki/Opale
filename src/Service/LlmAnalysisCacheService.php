<?php

namespace App\Service;

use App\Support\ErrorLogger;
use App\Support\JsonFile;

/**
 * LlmAnalysisCacheService
 *
 * Cache fichier des synthèses IA de sprints.
 *
 * Interroger le modèle est lent : la dernière synthèse produite est mémorisée pour être
 * réaffichée directement au rechargement de la page. Un sprint en cours est aussi mis en
 * cache — l'analyse est alors une photo datée, et la date de génération restituée permet
 * d'en juger l'ancienneté et de régénérer. Le cache est volontairement séparé de
 * SprintCacheService : il porte sur un autre artefact (texte généré) et possède sa propre
 * version, sans impacter le snapshot du sprint.
 *
 * Format du fichier (JSON) :
 * - "cached_at"        : timestamp Unix de la mise en cache
 * - "analysis_version" : version du format de prompt/analyse ; un changement
 *                        invalide automatiquement les anciennes synthèses
 * - "model"            : modèle ayant produit l'analyse (métadonnée d'affichage)
 * - "analysis"         : texte de la synthèse IA
 * - "extra_prompt"     : indications libres ayant servi à la génération, repeuplées dans
 *                        le formulaire au rechargement (absent des entrées antérieures)
 *
 * @see \App\Service\SprintCacheService Pour le même pattern de cache fichier.
 */
class LlmAnalysisCacheService
{
    /** Répertoire de stockage des synthèses IA. */
    public const CACHE_DIR = __DIR__ . '/../../cache/llm_analysis';

    /**
     * Version du format d'analyse (prompt + structure attendue).
     * À INCRÉMENTER si le prompt change significativement, pour régénérer les synthèses.
     */
    public const ANALYSIS_VERSION = 3;

    /**
     * Lit la synthèse en cache pour un sprint donné.
     *
     * Retourne null si le cache est absent, illisible, ou produit par une version
     * d'analyse différente (→ régénération nécessaire).
     *
     * @param string $projectKey Clé du projet Jira — incluse dans le nom de fichier
     *
     * @return array{cached_at:int, model:string, analysis:string, extraPrompt:string}|null
     */
    public function get(string $sprintId, string $projectKey = ''): ?array
    {
        $path = $this->getCacheFilePath($sprintId, $projectKey);
        if (!file_exists($path)) {
            return null;
        }

        $content = json_decode((string) file_get_contents($path), true);

        if (!is_array($content) || empty($content['analysis'])) {
            return null;
        }

        // Synthèse produite par une version d'analyse obsolète → on l'ignore.
        if (($content['analysis_version'] ?? null) !== self::ANALYSIS_VERSION) {
            return null;
        }

        return [
            'cached_at'   => (int) ($content['cached_at'] ?? 0),
            'model'       => (string) ($content['model'] ?? ''),
            'analysis'    => (string) $content['analysis'],
            // Entrées écrites avant l'ajout du champ : lecture tolérante, pas d'invalidation.
            'extraPrompt' => (string) ($content['extra_prompt'] ?? ''),
        ];
    }

    /**
     * Écrit la synthèse d'un sprint en cache.
     *
     * @param string $analysis    Texte de la synthèse IA
     * @param string $model       Modèle ayant produit l'analyse
     * @param string $projectKey  Clé du projet Jira — incluse dans le nom de fichier
     * @param string $extraPrompt Indications libres ayant servi à la génération
     *
     * @throws \RuntimeException Si le répertoire ou l'écriture échoue
     */
    public function put(
        string $sprintId,
        string $analysis,
        string $model,
        string $projectKey = '',
        string $extraPrompt = ''
    ): void {
        $content = json_encode([
            'cached_at'        => time(),
            'analysis_version' => self::ANALYSIS_VERSION,
            'model'            => $model,
            'analysis'         => $analysis,
            'extra_prompt'     => $extraPrompt,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $path = $this->getCacheFilePath($sprintId, $projectKey);
        if (!JsonFile::write($path, $content)) {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new \RuntimeException('LlmAnalysisCacheService : impossible d\'écrire le cache de la synthèse IA.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }
    }

    /**
     * Retourne le chemin absolu du fichier cache pour un sprint donné.
     *
     * Format : analysis_{projectKey}_{sprintId}.json (ex: analysis_ABC_1234.json).
     * Si projectKey est vide, fallback : analysis_{sprintId}.json.
     */
    private function getCacheFilePath(string $sprintId, string $projectKey = ''): string
    {
        $safeId = preg_replace('/[^a-z0-9_-]/i', '', $sprintId);
        if ($projectKey !== '') {
            $safeKey = preg_replace('/[^a-z0-9_-]/i', '', $projectKey);
            return self::CACHE_DIR . '/analysis_' . $safeKey . '_' . $safeId . '.json';
        }
        return self::CACHE_DIR . '/analysis_' . $safeId . '.json';
    }
}
