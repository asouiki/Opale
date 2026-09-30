<?php

namespace App\Service;

use App\Support\ErrorLogger;
use RuntimeException;

/**
 * LlmPromptRepository
 *
 * Accès unique aux prompts versionnés du répertoire config_files/ (prompts système des synthèses IA
 * locales, prompt d'export vers une IA Web). Centralise la lecture et la validation « fichier présent
 * et non vide », qui étaient auparavant recopiées dans chaque contrôleur consommateur.
 */
class LlmPromptRepository
{
    /**
     * Charge un prompt versionné par son nom de fichier (ex: llm_sprint_prompt.md).
     *
     * @param  string $fileName Nom du fichier dans config_files/ (sans chemin)
     * @return string           Contenu brut du prompt
     *
     * @throws RuntimeException Si le fichier est absent ou vide
     */
    public function load(string $fileName): string
    {
        $path    = __DIR__ . '/../../config_files/' . $fileName;
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        if (trim($content) === '') {
            // Chemin réservé au journal : inutile à l'utilisateur, et il expose l'arborescence serveur.
            $error = new RuntimeException('Prompt système IA introuvable ou vide.');
            ErrorLogger::critical($error, 'Chemin visé : ' . $path);
            throw $error;
        }

        return $content;
    }
}
