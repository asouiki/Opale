<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ProgramIncrementController
 *
 * Gère la page /pi : sélection projet + numéro de PI → cumul des Lead/Cycle/Waiting
 * Times des Features rattachées à ce PI.
 */
class ProgramIncrementController
{
    /**
     * Affiche la page vide (formulaire seul).
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        $view  = null;
        $error = null;
        require __DIR__ . '/../../views/pages/pi.phtml';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Traite la soumission : projet + PI → instancie ProgramIncrement → render.
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // getParsedBody() peut renvoyer un objet (corps JSON) ou null : on normalise en tableau.
        $data        = (array) ($request->getParsedBody() ?? []);
        $projectKey  = trim((string) ($data['projectKey'] ?? ''));
        $piNumber    = trim((string) ($data['piNumber'] ?? ''));

        $view  = null;
        $error = null;

        try {
            if ($projectKey === '') {
                throw new \Exception('Le paramètre projectKey est requis.');
            }
            if ($piNumber === '') {
                throw new \Exception('Le numéro de PI est requis.');
            }

            $pi   = new \App\Model\ProgramIncrement($projectKey, $piNumber);
            $view = new \App\View\ProgramIncrementView($pi);
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ob_start();
        require __DIR__ . '/../../views/pages/pi.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
