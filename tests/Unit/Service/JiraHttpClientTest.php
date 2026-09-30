<?php

namespace Tests\Unit\Service;

use App\Service\Http\JiraHttpResponse;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FakeTransport;
use Tests\Utils\RecordingJiraHttpClient;

/**
 * Tests du client HTTP Jira (résilience + décodage), via un transport factice.
 *
 * Ce filet naît AVEC l'extraction (lot A1) : les anciens request()/sendWriteRequest() en cURL brut
 * n'étaient pas caractérisables sans réseau ; la découpe transport/client crée le seam.
 *
 * Couvre : succès, 204 vide, erreur HTTP, JSON invalide, erreur transport, retry sur 5xx/429,
 * abandon après épuisement, respect de Retry-After, et NON-retry des écritures (POST/PUT).
 */
class JiraHttpClientTest extends TestCase
{
    /**
     * Client dont l'attente inter-tentatives est neutralisée et enregistrée (déterminisme).
     */
    private function client(FakeTransport $transport): RecordingJiraHttpClient
    {
        return new RecordingJiraHttpClient($transport);
    }

    #[Test]
    public function test_requestJson_succes_retourneJsonDecode(): void
    {
        $transport = new FakeTransport(new JiraHttpResponse(200, '{"accountId":"abc","total":3}'));

        $result = $this->client($transport)->requestJson('GET', 'https://jira/x');

        $this->assertSame(['accountId' => 'abc', 'total' => 3], $result);
        $this->assertSame(1, $transport->callCount());
    }

    #[Test]
    public function test_requestJson_corpsVide_retourneTableauVide(): void
    {
        // 204 No Content (ex: PUT issue réussi) : rien à décoder.
        $transport = new FakeTransport(new JiraHttpResponse(204, ''));

        $this->assertSame([], $this->client($transport)->requestJson('PUT', 'https://jira/x', '{}'));
    }

    #[Test]
    public function test_requestJson_ajouteContentType_siPayload(): void
    {
        $transport = new FakeTransport(new JiraHttpResponse(200, '{}'));

        $this->client($transport)->requestJson('POST', 'https://jira/x', '{"a":1}');

        $headers = $transport->sent[0]['headers'];
        $this->assertContains('Accept: application/json', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    #[Test]
    public function test_requestJson_get_sansPayload_pasDeContentType(): void
    {
        $transport = new FakeTransport(new JiraHttpResponse(200, '{}'));

        $this->client($transport)->requestJson('GET', 'https://jira/x');

        $this->assertSame(['Accept: application/json'], $transport->sent[0]['headers']);
    }

    #[Test]
    public function test_requestJson_erreurHttp_lanceException(): void
    {
        $transport = new FakeTransport(new JiraHttpResponse(404, '{"error":"not found"}'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('erreur HTTP 404');

        $this->client($transport)->requestJson('GET', 'https://jira/x');
    }

    #[Test]
    public function test_requestJson_jsonInvalide_lanceException(): void
    {
        $transport = new FakeTransport(new JiraHttpResponse(200, 'pas du json'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('non-JSON');

        $this->client($transport)->requestJson('GET', 'https://jira/x');
    }

    #[Test]
    public function test_requestJson_erreurTransport_get_retenteePuisReussit(): void
    {
        $transport = new FakeTransport(
            new JiraHttpResponse(0, '', 'Connexion refusée'),
            new JiraHttpResponse(200, '{"ok":true}'),
        );

        $client = $this->client($transport);
        $result = $client->requestJson('GET', 'https://jira/x');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $transport->callCount());
        $this->assertSame([500], $client->sleeps); // 1 backoff avant la 2e tentative
    }

    #[Test]
    public function test_requestJson_statut503_retente_backoffExponentiel(): void
    {
        $transport = new FakeTransport(
            new JiraHttpResponse(503, 'indispo'),
            new JiraHttpResponse(503, 'indispo'),
            new JiraHttpResponse(200, '{"ok":true}'),
        );

        $client = $this->client($transport);
        $result = $client->requestJson('GET', 'https://jira/x');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(3, $transport->callCount());
        $this->assertSame([500, 1000], $client->sleeps); // backoff x2
    }

    #[Test]
    public function test_requestJson_epuiseLesTentatives_lanceLaDerniereErreur(): void
    {
        $transport = new FakeTransport(
            new JiraHttpResponse(503, 'ko'),
            new JiraHttpResponse(503, 'ko'),
            new JiraHttpResponse(503, 'ko'),
        );

        $client = $this->client($transport);

        try {
            $client->requestJson('GET', 'https://jira/x');
            $this->fail('Une exception était attendue après épuisement des tentatives.');
        } catch (Exception $e) {
            $this->assertStringContainsString('erreur HTTP 503', $e->getMessage());
        }

        $this->assertSame(3, $transport->callCount()); // MAX_ATTEMPTS
    }

    #[Test]
    public function test_requestJson_respecteRetryAfter(): void
    {
        $transport = new FakeTransport(
            new JiraHttpResponse(429, 'rate limited', null, 2), // Retry-After: 2s
            new JiraHttpResponse(200, '{"ok":true}'),
        );

        $client = $this->client($transport);
        $client->requestJson('GET', 'https://jira/x');

        $this->assertSame([2000], $client->sleeps); // 2s prioritaire sur le backoff
    }

    #[Test]
    public function test_requestJson_ecriturePost_neRetentePas_surErreurServeur(): void
    {
        // Sécurité anti-doublon : un POST (ex: commentaire) ne doit jamais être rejoué.
        $transport = new FakeTransport(new JiraHttpResponse(503, 'ko'));

        $client = $this->client($transport);

        try {
            $client->requestJson('POST', 'https://jira/x', '{"body":"hello"}');
            $this->fail('Une exception HTTP était attendue.');
        } catch (Exception $e) {
            $this->assertStringContainsString('erreur HTTP 503', $e->getMessage());
        }

        $this->assertSame(1, $transport->callCount()); // aucune reprise
        $this->assertSame([], $client->sleeps);
    }
}
