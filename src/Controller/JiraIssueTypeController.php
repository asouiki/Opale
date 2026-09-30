<?php

namespace App\Controller;

use App\Model\Config;
use App\Service\JiraService;
use App\Service\TeamConfigRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JiraIssueTypeController
 *
 * Endpoint JSON de la page /config : récupère les types de tickets d'un projet
 * Jira, enrichit la liste globale par défaut (jira_issues_types.json) et résout
 * la liste propre au projet (initialisée avec tous les types Jira au 1er accès).
 */
class JiraIssueTypeController
{
    /**
     * GET /api/internal/projects/{projectKey}/issue-types
     *
     * Effets : (1) ajoute toujours les types Jira inédits à la liste globale ;
     * (2) initialise la liste propre au projet avec tous les types Jira si elle
     * n'existe pas encore (l'utilisateur pourra ensuite en retirer).
     *
     * @return ResponseInterface JSON {
     *   available: string[],  // types existants sur le projet (Jira ∪ stockés)
     *   selected:  string[],  // types retenus pour les métriques de ce projet
     *   added:     string[]   // types nouvellement ajoutés à la liste globale
     * }
     */
    public function listByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');
            if ($projectKey === '') {
                throw new \Exception('Missing project key');
            }

            // Garde-fou : un fichier de config présent mais corrompu ne doit pas déclencher la
            // réinitialisation destructive des types (plus bas). On échoue bruyamment ; le fichier
            // reste intact et récupérable, plutôt qu'écrasé par les valeurs par défaut.
            $repo = new TeamConfigRepository();
            if ($repo->isCorrupted($projectKey)) {
                throw new \RuntimeException(
                    'Configuration du projet illisible (fichier corrompu) : '
                    . $projectKey . '_team_config.json. Restaurez ce fichier avant de continuer.'
                );
            }

            $jiraTypes = (new JiraService())->getIssueTypesByProject($projectKey);

            $config = new Config();

            // (1) La liste globale par défaut est toujours enrichie des types Jira.
            $added = $config->addIssueTypes($jiraTypes);

            $defaults = $config->getDefaultMetricIssueTypes();

            // (2) Résolution de la liste retenue (cochée). À l'initialisation (config
            // absente ou vide), seuls les types par défaut (nœud "defaultTypes") sont
            // cochés ; les inclure permet aussi des types ajoutés à la main (ex.
            // traductions) absents de la réponse Jira.
            if ($repo->hasIssueTypes($projectKey)) {
                $selected = $repo->getIssueTypes($projectKey);
            } else {
                $selected = $defaults;
                $repo->setIssueTypes($projectKey, $selected);
            }

            // Univers affichable = types Jira ∪ sélection ∪ défauts. Ordre d'affichage :
            // d'abord les types par défaut (ordre de "defaultTypes"), puis les autres
            // triés alphabétiquement, en fin de liste.
            $universe = array_values(array_unique(array_merge($jiraTypes, $selected, $defaults)));

            $defaultsFirst = array_values(array_filter(
                $defaults,
                static fn (string $type): bool => in_array($type, $universe, true)
            ));
            $others = array_values(array_filter(
                $universe,
                static fn (string $type): bool => !in_array($type, $defaults, true)
            ));
            sort($others, SORT_NATURAL | SORT_FLAG_CASE);

            $available = array_merge($defaultsFirst, $others);

            $response->getBody()->write(json_encode(
                ['available' => $available, 'selected' => $selected, 'added' => $added],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (502 + message dédié).
            throw $e;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
