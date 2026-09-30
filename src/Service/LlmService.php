<?php

namespace App\Service;

use Exception;

/**
 * LlmService
 *
 * Client générique pour un LLM exposant une API compatible OpenAI Chat
 * Completions (Ollama, LiteLLM, LM Studio...). Lit sa configuration depuis le
 * .env (LLM_ENABLED, LLM_BASE_URL, LLM_API_KEY, LLM_MODEL) et n'embarque aucune
 * logique métier : la construction des prompts reste à la charge de l'appelant.
 *
 * Même style cURL que JiraService (aucune dépendance HTTP externe).
 */
class LlmService
{
    /** Délai max d'attente d'une réponse du modèle (s) — un modèle local peut être lent. */
    public const REQUEST_TIMEOUT = 120;

    /** Drapeau LLM_ENABLED du .env (active/désactive toute la fonctionnalité). */
    private bool $enabled;

    /** URL de base de l'API (ex: http://localhost:11434/v1), sans slash final. */
    private string $baseUrl;

    /** Clé d'API Bearer (factice avec Ollama). */
    private string $apiKey;

    /** Nom du modèle (ex: qwen2.5:14b). */
    private string $model;

    public function __construct()
    {
        $this->enabled = filter_var($_ENV['LLM_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->baseUrl = rtrim((string) ($_ENV['LLM_BASE_URL'] ?? ''), '/');
        $this->apiKey  = (string) ($_ENV['LLM_API_KEY'] ?? '');
        $this->model   = (string) ($_ENV['LLM_MODEL'] ?? '');
    }

    /**
     * Indique si la fonctionnalité IA est activée ET configurée.
     *
     * Faux si LLM_ENABLED n'est pas à true, ou si l'URL ou le modèle manquent.
     * Seul point de vérité : l'UI (affichage de la carte) et l'API (refus d'un
     * appel direct) s'appuient sur cette méthode.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->baseUrl !== '' && $this->model !== '';
    }

    /**
     * Nom du modèle configuré (métadonnée d'affichage / de cache).
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Envoie une conversation system + user au LLM et retourne le texte de la réponse.
     *
     * Les messages d'exception levés ici sont affichés tels quels à l'utilisateur (les contrôleurs les
     * relaient dans le JSON de la carte « Synthèse IA ») : ils décrivent l'action à mener, pas la panne
     * technique. Cas le plus courant en local : le serveur de modèles n'est pas démarré.
     *
     * @param  string $systemPrompt Prompt système (rôle, consignes, format attendu)
     * @param  string $userPrompt   Prompt utilisateur (données à analyser)
     * @return string               Contenu textuel de la réponse du modèle
     *
     * @throws Exception Si l'IA est désactivée, ou en cas d'échec réseau / HTTP / JSON
     */
    public function chat(string $systemPrompt, string $userPrompt): string
    {
        if (!$this->isEnabled()) {
            throw new Exception('LlmService::chat(...) : fonctionnalité IA désactivée ou non configurée.');
        }

        $url = $this->baseUrl . '/chat/completions';

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            // Température basse : favorise une synthèse stable et fidèle aux chiffres.
            'temperature' => 0.3,
            'max_tokens'  => 1500,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);

        // pas de curl_close() (déprécié en PHP 8.5, sans effet depuis 8.0)

        if (curl_errno($ch)) {
            // Échec réseau : les contrôleurs renvoient ce message tel quel dans la carte « Synthèse IA »,
            // il doit donc rester actionnable et sans détail technique (hôte, port, code cURL).
            // Un LLM injoignable ne bloque aucune autre fonctionnalité : rien n'est journalisé ici.
            throw new Exception(match (curl_errno($ch)) {
                CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => 'Le serveur d\'IA local ne répond pas :'
                    . ' il n\'est probablement pas démarré. Lancez-le, puis relancez la synthèse.',
                CURLE_OPERATION_TIMEDOUT => 'Le serveur d\'IA local n\'a pas répondu dans le délai imparti ('
                    . self::REQUEST_TIMEOUT . ' s). Il est peut-être surchargé : réessayez dans quelques instants.',
                default => 'Impossible de contacter le serveur d\'IA local.'
                    . ' Vérifiez qu\'il est bien démarré, puis relancez la synthèse.',
            });
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 400) {
            throw new Exception("LlmService::chat(...) : erreur HTTP LLM ($httpCode) : $response");
        }

        $data    = json_decode((string) $response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            throw new Exception('LlmService::chat(...) : réponse LLM vide ou invalide.');
        }

        return trim($content);
    }
}
