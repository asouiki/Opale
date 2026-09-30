<?php

declare(strict_types=1);

namespace Tests\Utils;

use App\Service\Http\JiraHttpClient;

/**
 * Client HTTP Jira de test : neutralise l'attente inter-tentatives et enregistre les délais
 * demandés, pour vérifier le backoff / Retry-After de façon déterministe (sans usleep réel).
 */
final class RecordingJiraHttpClient extends JiraHttpClient
{
    /** @var list<int> Délais (ms) qui auraient été « dormis » entre les tentatives. */
    public array $sleeps = [];

    protected function sleepMs(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
