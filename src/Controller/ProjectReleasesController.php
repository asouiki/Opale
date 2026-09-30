<?php

namespace App\Controller;

use App\Repository\ProjectReleasesRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoint JSON de la page /config : liste des Releases d'un projet Jira.
 */
class ProjectReleasesController
{
    private ProjectReleasesRepository $releasesRepo;

    public function __construct()
    {
        $this->releasesRepo = new ProjectReleasesRepository();
    }

    /**
     * GET /api/internal/projects/{projectKey}/releases
     *
     * Retourne en JSON la liste des Releases du projet. En cas d'échec, répond {success:false, error}.
     */
    public function releasesListByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = $args['projectKey'] ?? null;

            if (!$projectKey) {
                throw new \Exception('Missing project key');
            }

            $releases = $this->releasesRepo->getProjectReleasesList($projectKey);

            $response->getBody()->write(json_encode($releases, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
