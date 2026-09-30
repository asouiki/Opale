<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HomeController
 *
 * Gère la racine « / » : page d'accueil qui présente OPALE et oriente vers les
 * fonctionnalités. Les liens profonds historiques « /?projectKey=…&boardId=…&sprintId= »
 * (partage externe vers un sprint précis) sont redirigés vers « /sprint », qui sait les
 * traiter côté client (cf. selectSprint.phtml::applyDeepLink()).
 */
class HomeController
{
    /**
     * Affiche la page d'accueil, ou redirige un lien profond vers « /sprint ».
     *
     * Un lien profond complet (projectKey + boardId + sprintId présents) provient d'un
     * partage externe vers un sprint précis : on le réoriente vers « /sprint » (302) en
     * conservant la query string. Cela préserve les liens déjà diffusés et garantit que
     * l'onboarding ne s'affiche jamais sur ce type d'accès. Sinon, rendu de l'accueil.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        if (!empty($params['projectKey']) && !empty($params['boardId']) && !empty($params['sprintId'])) {
            return $response
                ->withHeader('Location', '/sprint?' . $request->getUri()->getQuery())
                ->withStatus(302);
        }

        ob_start();
        require __DIR__ . '/../../views/pages/home.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
