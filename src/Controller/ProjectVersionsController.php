<?php

namespace App\Controller;

use App\Repository\ProjectVersionsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoint JSON de la page /config : liste des Versions (fixVersions) d'un projet Jira.
 */
class ProjectVersionsController
{
    private ProjectVersionsRepository $projectVersionsRepo;

    public function __construct()
    {
        $this->projectVersionsRepo = new ProjectVersionsRepository();
    }

    /**
     * GET /api/internal/projects/{projectKey}/versions
     *
     * Retourne en JSON la liste des Versions du projet. En cas d'échec, répond {success:false, error}.
     */
    public function versionsListByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = $args['projectKey'] ?? null;

            if (!$projectKey) {
                throw new \Exception('Missing project key');
            }

            $versions = $this->projectVersionsRepo->getProjectVersionsList($projectKey);

            $response->getBody()->write(json_encode($versions, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
