<?php

namespace App\Controller;

use App\Repository\ProjectFeaturesRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoint JSON de la page /config : liste des Features d'un projet Jira.
 */
class ProjectFeaturesController
{
    private ProjectFeaturesRepository $featuresRepo;

    public function __construct()
    {
        $this->featuresRepo = new ProjectFeaturesRepository();
    }

    /**
     * GET /api/internal/projects/{projectKey}/features
     *
     * Retourne en JSON la liste des Features du projet. En cas d'échec, répond {success:false, error}.
     */
    public function featuresListByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = $args['projectKey'] ?? null;

            if (!$projectKey) {
                throw new \Exception('Missing project key');
            }

            $features = $this->featuresRepo->getProjectFeaturesList($projectKey);

            $response->getBody()->write(json_encode($features, JSON_THROW_ON_ERROR));
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
