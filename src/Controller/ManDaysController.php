<?php

namespace App\Controller;

use App\Service\SprintConfigRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ManDaysController
 *
 * Saisie utilisateur des réglages de capacité d'un sprint (jours/homme et
 * capacité en points), persistés par projet via \App\Service\SprintConfigRepository.
 * Sert la vélocité « SP / jours-homme » et le suivi de capacité affichés dans les
 * pages Sprint et Team Metrics.
 */
class ManDaysController
{
    /**
     * POST /api/internal/projects/{projectKey}/sprints/{sprintId}/man-days
     *
     * Body JSON : { "manDays": <number|null> }. Une valeur null ou <= 0 efface
     * la saisie pour ce sprint. Retourne { "success": true } en cas de succès.
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$projectKey, $sprintId] = $this->validateArgs($args);
            if ($projectKey === null) {
                return $this->json($response, ['success' => false, 'error' => $sprintId], 400);
            }

            $body    = (array) ($request->getParsedBody() ?? []);
            $manDays = $this->parsePositiveAmount($body['manDays'] ?? null);

            (new SprintConfigRepository())->setManDays($projectKey, $sprintId, $manDays);

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/internal/projects/{projectKey}/sprints/{sprintId}/capacity-points
     *
     * Body urlencoded : capacityPoints=<number|''>. Une valeur vide ou <= 0 efface
     * la saisie pour ce sprint. Retourne { "success": true } en cas de succès.
     */
    public function saveCapacityPoints(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$projectKey, $sprintId] = $this->validateArgs($args);
            if ($projectKey === null) {
                return $this->json($response, ['success' => false, 'error' => $sprintId], 400);
            }

            $body           = (array) ($request->getParsedBody() ?? []);
            $capacityPoints = $this->parsePositiveAmount($body['capacityPoints'] ?? null);

            (new SprintConfigRepository())->setCapacityPoints($projectKey, $sprintId, $capacityPoints);

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Valide et extrait projectKey/sprintId des paramètres de route.
     *
     * @return array{0: string|null, 1: string} [projectKey, sprintId] si valides,
     *                                           sinon [null, messageErreur]
     */
    private function validateArgs(array $args): array
    {
        $projectKey = (string) ($args['projectKey'] ?? '');
        $sprintId   = (string) ($args['sprintId'] ?? '');

        if (!$this->isValidProjectKey($projectKey)) {
            return [null, 'projectKey invalide'];
        }
        if (!ctype_digit($sprintId)) {
            return [null, 'sprintId invalide'];
        }

        return [$projectKey, $sprintId];
    }

    /**
     * Normalise la valeur reçue en float positif, ou null (effacement).
     *
     * Accepte un nombre ou une chaîne numérique. Toute valeur vide, non numérique ou <= 0 est
     * convertie en null afin d'effacer la saisie côté repository.
     */
    private function parsePositiveAmount(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * Valide la clé de projet (sécurise aussi le nom de fichier généré).
     */
    private function isValidProjectKey(string $projectKey): bool
    {
        return $projectKey !== '' && preg_match('/^[A-Za-z0-9_]+$/', $projectKey) === 1;
    }

    /**
     * Écrit une réponse JSON et positionne l'en-tête / le code HTTP.
     *
     * @throws \JsonException Si la charge utile ne peut pas être encodée en JSON
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
