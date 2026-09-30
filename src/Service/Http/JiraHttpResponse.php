<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Réponse HTTP bas niveau d'un transport Jira (une seule requête, sans retry ni parsing JSON).
 *
 * Sépare le résultat brut du transport (statut + corps + erreur réseau éventuelle) de la logique
 * de résilience et de décodage, portée par JiraHttpClient.
 */
final readonly class JiraHttpResponse
{
    /**
     * @param int         $statusCode        Code HTTP (0 si la requête n'a pas abouti au réseau)
     * @param string      $body              Corps brut de la réponse (chaîne vide si 204/aucun corps)
     * @param string|null $transportError    Message d'erreur cURL/réseau, ou null si la requête a abouti
     * @param int|null    $retryAfterSeconds Valeur de l'en-tête `Retry-After` (secondes), si présente
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public ?string $transportError = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    /**
     * Vrai si la requête n'a pas abouti au niveau réseau (échec cURL, DNS, timeout...).
     */
    public function hasTransportError(): bool
    {
        return $this->transportError !== null;
    }
}
