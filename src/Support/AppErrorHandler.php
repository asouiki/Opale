<?php

namespace App\Support;

use App\Exception\JiraCredentialsException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Gestionnaire d'erreurs global de l'application (branché sur le ErrorMiddleware Slim).
 *
 * Transforme toute exception non capturée en réponse propre plutôt qu'en fatal PHP (page blanche
 * ou « 500 » brut) :
 *  - routes /api/  → JSON { success: false, error }
 *  - autres routes → page HTML d'erreur (vue error.phtml, cohérente avec le reste de l'IHM)
 *
 * Un cas particulier est réservé aux identifiants Jira invalides (JiraCredentialsException) :
 * message dédié et actionnable, sans jamais exposer le contenu du .env.
 */
class AppErrorHandler
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * Signature attendue par Slim\Middleware\ErrorMiddleware::setDefaultErrorHandler().
     */
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        // Exception non rattrapée = action utilisateur échouée : toujours tracée, avec son détail
        // technique (que la réponse, elle, n'expose pas).
        ErrorLogger::critical($exception);

        $isCredentials = $exception instanceof JiraCredentialsException;
        $status        = $isCredentials ? 502 : 500;

        $message = $isCredentials
            ? JiraCredentialsException::USER_MESSAGE
            : 'Une erreur inattendue est survenue lors du traitement de votre demande.';

        // Détail technique réservé au mode debug (jamais le contenu du .env).
        $details = $displayErrorDetails ? $exception->getMessage() : null;

        if ($this->wantsJson($request)) {
            return $this->renderJson($status, $message, $details);
        }

        return $this->renderHtml($status, $message, $details, $isCredentials);
    }

    /**
     * Les endpoints internes (/api/) et les clients demandant du JSON reçoivent une réponse JSON.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            return true;
        }

        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function renderJson(int $status, string $message, ?string $details): ResponseInterface
    {
        $payload = ['success' => false, 'error' => $message];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function renderHtml(int $status, string $message, ?string $details, bool $isCredentials): ResponseInterface
    {
        $error       = $message;
        $errorDetail = $details;
        $isConfigIssue = $isCredentials;

        ob_start();
        require __DIR__ . '/../../views/pages/error.phtml';
        $html = (string) ob_get_clean();

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html');
    }
}
