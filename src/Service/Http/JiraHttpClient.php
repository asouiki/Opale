<?php

declare(strict_types=1);

namespace App\Service\Http;

use Exception;

/**
 * Client HTTP Jira : orchestre un transport bas niveau (JiraTransport) en ajoutant la résilience
 * (retry + backoff exponentiel, respect de `Retry-After`) et le décodage JSON.
 *
 * Retry limité aux requêtes GET : les lectures sont idempotentes, donc sûres à rejouer. Les
 * écritures (PUT/POST) ne sont jamais retentées automatiquement — rejouer un POST de commentaire
 * créerait un doublon. Le timeout du transport, lui, protège toutes les requêtes.
 */
class JiraHttpClient
{
    /** Nombre total de tentatives (1 initiale + 2 retries) pour une requête retentable. */
    private const MAX_ATTEMPTS = 3;

    /** Codes HTTP transitoires justifiant un retry (rate-limit + erreurs serveur). */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** Backoff de base (ms), doublé à chaque tentative (500 → 1000 → ...). */
    private const BASE_BACKOFF_MS = 500;

    public function __construct(private readonly JiraTransport $transport)
    {
    }

    /**
     * Exécute une requête Jira et retourne la réponse JSON décodée.
     *
     * @param  non-empty-string $method Méthode HTTP ('GET', 'POST', 'PUT'...)
     * @param  string      $url    URL complète de l'endpoint
     * @param  string|null $body   Corps JSON pré-encodé, ou null (GET)
     * @return array<mixed>        Réponse décodée, ou [] si le corps est vide (204 No Content)
     *
     * @throws Exception Si le transport échoue, si le code HTTP est >= 400, ou si le corps n'est pas du JSON
     */
    public function requestJson(string $method, string $url, ?string $body = null): array
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        // Seules les lectures (GET) sont rejouables sans risque d'effet de bord.
        $retryable = ($method === 'GET');
        $attempt   = 0;

        while (true) {
            $attempt++;
            $response = $this->transport->send($method, $url, $headers, $body);

            if ($response->hasTransportError()) {
                if ($retryable && $attempt < self::MAX_ATTEMPTS) {
                    $this->sleepMs($this->backoffMs($attempt, null));
                    continue;
                }
                throw new Exception('Requête Jira : erreur de transport : ' . $response->transportError);
            }

            if (
                $retryable
                && $attempt < self::MAX_ATTEMPTS
                && in_array($response->statusCode, self::RETRYABLE_STATUSES, true)
            ) {
                $this->sleepMs($this->backoffMs($attempt, $response->retryAfterSeconds));
                continue;
            }

            if ($response->statusCode >= 400) {
                throw new Exception(
                    "Requête Jira : erreur HTTP {$response->statusCode} : {$response->body}"
                );
            }

            return $this->decode($response->body);
        }
    }

    /**
     * Décode le corps JSON de la réponse ; un corps vide (204 No Content, ex: PUT réussi) → [].
     *
     * @return array<mixed>
     *
     * @throws Exception Si le corps non vide n'est pas un tableau JSON valide
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('Requête Jira : réponse non-JSON reçue');
        }

        return $data;
    }

    /**
     * Délai avant la prochaine tentative : `Retry-After` (si fourni) prioritaire, sinon backoff
     * exponentiel basé sur le numéro de tentative.
     */
    private function backoffMs(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return $retryAfterSeconds * 1000;
        }

        return self::BASE_BACKOFF_MS * (2 ** ($attempt - 1));
    }

    /**
     * Met en pause entre deux tentatives. Seam de test : surchargé pour neutraliser l'attente réelle.
     */
    protected function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
