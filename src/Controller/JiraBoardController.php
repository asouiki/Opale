<?php

namespace App\Controller;

use App\Repository\BoardRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JiraBoardController
 *
 * Endpoints API JSON consommés par le formulaire de sélection du sprint :
 * - liste des boards d'un projet
 * - liste des sprints d'un board
 */
class JiraBoardController
{
    private BoardRepository $boardRepo;

    public function __construct()
    {
        $this->boardRepo = new BoardRepository();
    }

    /**
     * Vrai si la requête demande explicitement à contourner le cache (CTA « rafraîchir la liste »).
     *
     * Toute valeur autre que « 0 » / « false » / vide vaut oui : ce drapeau n'est produit que par nos
     * propres sélecteurs, mieux vaut un rafraîchissement de trop qu'une liste périmée servie en silence.
     */
    private static function wantsRefresh(ServerRequestInterface $request): bool
    {
        $value = $request->getQueryParams()['refresh'] ?? null;

        return is_string($value) && !in_array(strtolower($value), ['', '0', 'false'], true);
    }

    /**
     * GET /api/internal/projects/{projectKey}/boards
     *
     * Retourne en JSON la liste des boards du projet. En cas d'échec, répond {success:false, error}.
     */
    public function boardsListByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = $args['projectKey'] ?? null;

            if (!$projectKey) {
                throw new \Exception('Missing project key');
            }

            $boards = $this->boardRepo->getProjectBoards($projectKey, self::wantsRefresh($request));

            $response->getBody()->write(json_encode($boards, JSON_THROW_ON_ERROR));
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

    /**
     * GET /api/internal/boards/{boardId}/sprints
     *
     * Retourne en JSON les sprints du board ; la clé de projet optionnelle (query) permet de marquer
     * les sprints ignorés. En cas d'échec, répond {success:false, error}.
     */
    public function sprintsListByBoard(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $boardId = $args['boardId'] ?? null;

            if (!$boardId) {
                throw new \Exception('Missing board id');
            }

            // Clé de projet optionnelle : marque les sprints ignorés (grisage IHM).
            $projectKey = $request->getQueryParams()['projectKey'] ?? null;

            $sprints = $this->boardRepo->getBoardSprints($boardId, $projectKey, self::wantsRefresh($request));

            $response->getBody()->write(json_encode($sprints, JSON_THROW_ON_ERROR));
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
