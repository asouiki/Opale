<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * CsrfGuard
 *
 * Refuse les requêtes d'écriture (POST/PUT/PATCH/DELETE) déclenchées depuis un autre site.
 *
 * L'application n'a ni session ni cookie d'authentification : il n'y a donc pas de jeton de session à
 * rejouer. Le risque résiduel est qu'une page externe, ouverte par un utilisateur déjà sur le réseau
 * interne, poste vers l'app à son insu (mise à jour d'un ticket Jira, écrasement d'une configuration
 * d'équipe, redémarrage du serveur). Une vérification d'origine suffit à le couvrir : pas de jetons
 * synchronisés à générer, stocker et injecter dans chaque formulaire (anti-KISS ici).
 *
 * Deux signaux, du plus fiable au plus ancien :
 *  1. `Sec-Fetch-Site` — envoyé par tous les navigateurs récents, non falsifiable par le JavaScript
 *     de la page attaquante ; la valeur `cross-site` désigne exactement le cas à refuser.
 *  2. `Origin` — repli lorsque `Sec-Fetch-Site` est absent : on compare son hôte à celui de la requête.
 *
 * ⚠️ En l'absence des DEUX en-têtes, la requête est ACCEPTÉE : ce sont les clients non-navigateur
 * (script, curl, appel serveur à serveur), qui ne constituent pas un vecteur CSRF — un navigateur,
 * lui, envoie toujours `Origin` sur une écriture cross-origin. Bloquer ce cas casserait l'outillage
 * sans rien apporter en sécurité.
 */
final class CsrfGuard implements MiddlewareInterface
{
    /** Méthodes à effet de bord : les seules à protéger (une lecture n'altère rien). */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), self::PROTECTED_METHODS, true) || !$this->isCrossSite($request)) {
            return $handler->handle($request);
        }

        // Rare par nature : on le trace pour pouvoir diagnostiquer un blocage légitime imprévu.
        ErrorLogger::critical(
            new RuntimeException('CsrfGuard : écriture cross-site refusée.'),
            sprintf(
                'Méthode : %s | Chemin : %s | Sec-Fetch-Site : %s | Origin : %s',
                $request->getMethod(),
                $request->getUri()->getPath(),
                $request->getHeaderLine('Sec-Fetch-Site') ?: '(absent)',
                $request->getHeaderLine('Origin') ?: '(absent)'
            )
        );

        return $this->deny($request);
    }

    /**
     * Indique si l'écriture provient d'un autre site. Ne renvoie true que sur un signal POSITIF de
     * cross-site : dans le doute, on laisse passer (voir le PHPDoc de classe).
     */
    private function isCrossSite(ServerRequestInterface $request): bool
    {
        $fetchSite = $request->getHeaderLine('Sec-Fetch-Site');
        if ($fetchSite !== '') {
            return $fetchSite === 'cross-site';
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return false;
        }

        return parse_url($origin, PHP_URL_HOST) !== $request->getUri()->getHost();
    }

    /**
     * Réponse 403, au format attendu par l'appelant (JSON pour les endpoints /api/, texte sinon).
     */
    private function deny(ServerRequestInterface $request): ResponseInterface
    {
        $message  = 'Requête refusée : écriture déclenchée depuis un autre site.';
        $isApi    = str_starts_with($request->getUri()->getPath(), '/api/');
        $response = $this->responseFactory->createResponse(403);

        if ($isApi) {
            $response->getBody()->write(json_encode(
                ['success' => false, 'error' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($message);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
