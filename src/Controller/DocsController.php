<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DocsController
 *
 * Affiche la page /docs : manuel utilisateur de l'application OPALE.
 */
class DocsController
{
    /**
     * Affiche le manuel utilisateur.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require __DIR__ . '/../../views/pages/docs.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
