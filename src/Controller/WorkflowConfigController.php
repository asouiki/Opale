<?php

namespace App\Controller;

use App\Model\Config;
use App\Service\JiraService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * WorkflowConfigController
 *
 * Lecture et écriture de la configuration du workflow d'issues propre à un
 * projet Jira (config_files/teams/jira_workflow_issues_<projectKey>.json).
 */
class WorkflowConfigController
{
    /**
     * Les 4 catégories de statuts gérées par l'IHM de configuration.
     * L'ordre de chaque liste porte le sens métier (départ → final).
     */
    private const CATEGORY_KEYS = [
        'refinement_statuses',
        'sprint_statuses',
        'done_statuses',
        'waiting_statuses',
    ];

    /**
     * GET /api/internal/projects/{projectKey}/workflow
     *
     * Retourne la config résolue (fichier projet si présent, sinon global) et,
     * en best-effort, les statuts réels du projet pour pré-remplir/suggérer.
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');

            if (!$this->isValidProjectKey($projectKey)) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $config   = new Config();
            $workflow = $config->getProjectIssuesWorkflow($projectKey);

            // Best-effort : un échec Jira ne doit pas empêcher la lecture de la config.
            $jiraStatuses = [];
            try {
                $jiraStatuses = (new JiraService())->getStatusesByProject($projectKey);
            } catch (\Throwable $e) {
                $jiraStatuses = [];
            }

            return $this->json($response, [
                'source'       => $workflow['source'],
                'categories'   => $this->normalizeCategories($workflow['categories']),
                'jiraStatuses' => $jiraStatuses,
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/internal/projects/{projectKey}/workflow
     *
     * Persiste les 4 listes de statuts (ordre conservé) dans le fichier projet.
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');

            if (!$this->isValidProjectKey($projectKey)) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $body       = (array) ($request->getParsedBody() ?? []);
            $categories = $this->sanitizeCategories($body);

            $config = new Config();
            $file   = $config->saveIssuesWorkflow($projectKey, $categories);

            return $this->json($response, ['success' => true, 'file' => $file]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Valide la clé de projet (sécurise aussi le nom de fichier généré).
     */
    private function isValidProjectKey(string $projectKey): bool
    {
        return $projectKey !== '' && preg_match('/^[A-Za-z0-9_]+$/', $projectKey) === 1;
    }

    /**
     * Garantit la présence des 4 clés (listes vides par défaut) pour l'IHM.
     *
     * @return array<string, string[]>
     */
    private function normalizeCategories(array $categories): array
    {
        $normalized = [];
        foreach (self::CATEGORY_KEYS as $key) {
            $normalized[$key] = array_values(array_map('strval', $categories[$key] ?? []));
        }

        return $normalized;
    }

    /**
     * Nettoie les listes reçues : trim, suppression des vides, dédoublonnage,
     * tout en préservant l'ordre fourni (sens métier départ → final).
     *
     * @return array<string, string[]>
     */
    private function sanitizeCategories(array $body): array
    {
        $clean = [];
        foreach (self::CATEGORY_KEYS as $key) {
            $values = $body[$key] ?? [];
            if (!is_array($values)) {
                $values = [];
            }

            $seen = [];
            $list = [];
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '' || isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $list[]       = $value;
            }

            $clean[$key] = $list;
        }

        return $clean;
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
