<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Transport HTTP bas niveau : exécute UNE requête et retourne la réponse brute.
 *
 * Constitue le seam de test du client Jira : l'implémentation réelle (CurlTransport) parle à Jira,
 * une implémentation factice (FakeTransport, côté tests) rejoue des réponses prédéfinies — ce qui
 * permet de caractériser la résilience (retry/backoff, parsing) sans réseau.
 */
interface JiraTransport
{
    /**
     * Exécute une requête HTTP et retourne la réponse brute (aucun retry, aucun décodage).
     *
     * @param non-empty-string   $method  Méthode HTTP ('GET', 'POST', 'PUT'...)
     * @param string             $url     URL complète de l'endpoint
     * @param array<int, string> $headers En-têtes HTTP (ex: ['Accept: application/json'])
     * @param string|null        $body    Corps de requête pré-encodé, ou null (GET)
     */
    public function send(string $method, string $url, array $headers, ?string $body): JiraHttpResponse;
}
