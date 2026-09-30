<?php

declare(strict_types=1);

namespace Tests\Utils;

use App\Service\Http\JiraHttpResponse;
use App\Service\Http\JiraTransport;
use LogicException;

/**
 * Transport factice : rejoue une file de réponses prédéfinies et enregistre les requêtes reçues.
 * Support de test du seam HTTP (JiraHttpClient) sans réseau ni cURL.
 */
final class FakeTransport implements JiraTransport
{
    /** @var list<JiraHttpResponse> File des réponses à retourner, dans l'ordre. */
    private array $queue;

    /** @var list<array{method: string, url: string, headers: array<int, string>, body: string|null}> */
    public array $sent = [];

    public function __construct(JiraHttpResponse ...$responses)
    {
        $this->queue = array_values($responses);
    }

    public function send(string $method, string $url, array $headers, ?string $body): JiraHttpResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->queue === []) {
            throw new LogicException('FakeTransport : file de réponses épuisée (send() appelé de trop).');
        }

        return array_shift($this->queue);
    }

    /** Nombre de requêtes effectivement émises. */
    public function callCount(): int
    {
        return count($this->sent);
    }
}
