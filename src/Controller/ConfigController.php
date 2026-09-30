<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ConfigController
 *
 * Affiche la page /config : configuration d'un projet Jira (board par défaut,
 * workflow, types de tickets, sprints à ignorer). La page est entièrement
 * pilotée côté client (Alpine.js) via les endpoints /api/internal/...
 */
class ConfigController
{
    /**
     * Affiche la page de configuration (formulaire de sélection seul).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require __DIR__ . '/../../views/pages/config.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
