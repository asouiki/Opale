<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Filet de sécurité pour les erreurs FATALES non capturables par le ErrorMiddleware Slim
 * (qui ne voit que les Throwable).
 *
 * Cas visé : dépassement de max_execution_time (fatal E_ERROR « Maximum execution time exceeded »)
 * quand trop de sprints sont sélectionnés sur /team-metrics — le script est tué en plein traitement
 * et l'utilisateur voit une page blanche brute.
 * Branché via register_shutdown_function(), ce guard détecte le fatal en fin de requête et rend une
 * réponse propre (page HTML d'erreur, ou JSON pour /api/) à la place du fatal brut.
 * Même traitement pour un dépassement mémoire (« Allowed memory size exhausted »).
 *
 * markCompleted() est appelé après $app->run() : une requête terminée normalement ne déclenche jamais
 * le rendu (error_get_last() peut renvoyer un simple warning bénin sans rapport avec un fatal).
 */
final class FatalErrorGuard
{
    /** Masque des types d'erreurs PHP réellement fatales (interrompent le script, non catchables). */
    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private bool $completed = false;

    /**
     * @param string $errorTemplatePath Chemin absolu de la vue HTML d'erreur (views/pages/error.phtml)
     */
    public function __construct(private readonly string $errorTemplatePath)
    {
    }

    /** Branche le handler de fin de requête. */
    public function register(): void
    {
        register_shutdown_function([$this, 'onShutdown']);
    }

    /** À appeler après $app->run() : marque la requête comme terminée normalement. */
    public function markCompleted(): void
    {
        $this->completed = true;
    }

    /**
     * Handler de fin de requête : si le script a été tué par un fatal (et non terminé normalement),
     * rend une réponse propre à la place de la page blanche.
     */
    public function onShutdown(): void
    {
        if ($this->completed) {
            return;
        }

        $error = error_get_last();
        if ($error === null || !self::isFatal($error['type'])) {
            return;
        }

        // En-têtes déjà partis (réponse partiellement émise) → impossible de remplacer proprement.
        if (headers_sent()) {
            return;
        }

        // Purge toute sortie partielle mise en tampon avant le fatal.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $isApi      = str_starts_with($requestUri, '/api/');
        $message    = self::userMessage($error['message'], $requestUri);

        http_response_code(500);
        if ($isApi) {
            header('Content-Type: application/json');
            echo (string) json_encode(['success' => false, 'error' => $message]);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHtml($message);
    }

    /** Indique si un type d'erreur PHP est un fatal interrompant le script. */
    public static function isFatal(int $type): bool
    {
        return ($type & self::FATAL_TYPES) !== 0;
    }

    /**
     * Traduit le message technique du fatal en message actionnable pour l'utilisateur.
     * Distingue le dépassement de temps du dépassement mémoire, et adapte le conseil à la requête en cours :
     * sur les endpoints d'analyse LLM locale (`.../analysis`, pages Sprint et Métriques d'équipe), la lenteur
     * vient du modèle et non du volume chargé depuis Jira — y conseiller de réduire la sélection de sprints
     * serait trompeur, puisque le périmètre est déjà figé au moment où l'analyse est lancée.
     *
     * @param string $rawMessage Message technique brut remonté par error_get_last()
     * @param string $requestUri REQUEST_URI de la requête interrompue (avec ou sans query string)
     */
    public static function userMessage(string $rawMessage, string $requestUri): string
    {
        $isLlmAnalysis = str_ends_with((string) parse_url($requestUri, PHP_URL_PATH), '/analysis');

        $advice = $isLlmAnalysis
            ? ' Le modèle local est peut-être surchargé : réessayez dans quelques instants.'
            : (' Réduisez le nombre de sprints sélectionnés, puis relancez l\'analyse.'
                . ' Les sprints avec ⚡ ont pu être cachés pour limiter les prochains appels.');

        if (stripos($rawMessage, 'maximum execution time') !== false) {
            return 'Le traitement a dépassé le temps maximum autorisé.' . $advice;
        }
        if (stripos($rawMessage, 'allowed memory size') !== false) {
            return 'Le traitement a dépassé la mémoire disponible.' . $advice;
        }

        return 'Une erreur inattendue a interrompu le traitement de votre demande.';
    }

    /** Rend la page HTML d'erreur (même vue que le ErrorMiddleware, pour un rendu cohérent). */
    private function renderHtml(string $message): string
    {
        $error         = $message;
        $errorDetail   = null;
        $isConfigIssue = false;

        ob_start();
        require $this->errorTemplatePath;

        return (string) ob_get_clean();
    }
}
