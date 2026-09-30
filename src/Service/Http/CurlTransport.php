<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Transport HTTP réel vers l'API Jira, via cURL.
 *
 * Applique l'authentification Basic (email:token), des timeouts (connexion + global), la vérification TLS adossée au magasin de
 * certificats du système, l'éventuel bypass SSL du mode démo (proxy corporate) et capture l'en-tête `Retry-After`.
 * Ne fait NI retry NI décodage JSON : ces responsabilités relèvent de JiraHttpClient.
 */
final class CurlTransport implements JiraTransport
{
    /** Timeout d'établissement de connexion (secondes). */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** Timeout global de la requête (secondes) : borne la « page qui pend ». */
    private const TIMEOUT_SECONDS = 60;

    /**
     * @param string $userpwd   Identifiants Basic au format `email:token`
     * @param bool   $bypassSsl Désactive la vérification SSL (mode démo derrière proxy corporate)
     */
    public function __construct(
        private readonly string $userpwd,
        private readonly bool $bypassSsl,
    ) {
    }

    /**
     * @param non-empty-string   $method  Méthode HTTP ('GET', 'POST', 'PUT'...)
     * @param array<int, string> $headers En-têtes HTTP (ex: ['Accept: application/json'])
     */
    public function send(string $method, string $url, array $headers, ?string $body): JiraHttpResponse
    {
        $ch         = curl_init($url);
        $retryAfter = null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->userpwd,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            // Vérification TLS adossée au magasin de certificats du système d'exploitation.
            // Ne pas retirer : les builds Windows de PHP n'embarquent aucune autorité racine (aucun cacert.pem dans le zip
            // officiel, curl.cainfo commenté dans php.ini-development), donc toute requête HTTPS échouerait sur « unable to
            // get local issuer certificate ».
            // Le magasin système est de plus le seul à porter l'autorité interne d'un proxy d'inspection SSL d'entreprise,
            // qu'un bundle Mozilla ne contiendrait pas — c'est ce qui permet de se passer du bypass IS_DEMO.
            CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
            // Capture l'en-tête Retry-After (429/503) sans réinjecter les en-têtes dans le corps.
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'retry-after:') === 0) {
                    $value = trim(substr($header, strlen('retry-after:')));
                    if (is_numeric($value)) {
                        $retryAfter = (int) $value;
                    }
                }
                return strlen($header);
            },
        ]);

        // GET : méthode par défaut de cURL. Toute autre méthode (POST/PUT) est explicite ;
        // sur le fil, CUSTOMREQUEST + POSTFIELDS équivaut à l'ancien CURLOPT_POST.
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->bypassSsl) {
            // ⚠️ Démo uniquement ⚠️ (proxy SSL corporate) => on bypass la vérification SSL.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);

        // pas de curl_close() (déprécié en PHP 8.5, sans effet depuis 8.0)
        if ($response === false || curl_errno($ch)) {
            $error = curl_error($ch);

            return new JiraHttpResponse(0, '', $error !== '' ? $error : 'Erreur cURL inconnue');
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return new JiraHttpResponse($statusCode, (string) $response, null, $retryAfter);
    }
}
