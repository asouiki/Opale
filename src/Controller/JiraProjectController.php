<?php

namespace App\Controller;

use App\Repository\JiraProjectRepository;
use App\Service\JiraService;
use App\UseCase\SearchJiraProjectsUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoint JSON d'autocomplétion : recherche de projets Jira par mot-clé (formulaires de sélection).
 */
class JiraProjectController
{
    /**
     * GET /api/internal/projects/search?q=... — retourne en JSON les projets Jira correspondant à la
     * recherche. En cas d'échec, répond {success:false, error}.
     */
    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $query = $request->getQueryParams()['q'] ?? '';

            $jiraService = new JiraService();
            $repository  = new JiraProjectRepository($jiraService);
            $useCase     = new SearchJiraProjectsUseCase($repository);

            $projects = $useCase->execute($query);

            $response->getBody()->write(json_encode($projects, JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
