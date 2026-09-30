<?php

namespace App\Controller;

use App\Model\Config;
use App\Service\TeamConfigRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TeamConfigController
 *
 * API JSON de la configuration d'un projet (config_files/teams/<KEY>_team_config.json),
 * consommée par la page /config. Le workflow garde son propre endpoint
 * (WorkflowConfigController) ; ici on gère board par défaut, sprints ignorés et types.
 */
class TeamConfigController
{
    /** Rôles autorisés pour un membre du roster (profils SAFe/Scrum de l'équipe). */
    private const ALLOWED_ROLES = ['Dev', 'BA', 'QA', 'PO', 'SM'];

    /**
     * GET /api/internal/projects/{projectKey}/team-config
     *
     * Retourne la config courante du projet (pour pré-remplir l'IHM).
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');
            if (!$this->isValidProjectKey($projectKey)) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $repo = new TeamConfigRepository();

            return $this->json($response, [
                'preferredBoardId'     => $repo->getPreferredBoardId($projectKey),
                'ignoredSprints'       => $repo->getIgnoredSprints($projectKey),
                'parentProjectKey'     => $repo->getParentProjectKey($projectKey),
                'releaseTeamName'      => $repo->getReleaseTeamName($projectKey),
                'members'              => $repo->getTeamMembers($projectKey),
                'lastCapacityImportAt' => $repo->getLastCapacityImportAt($projectKey),
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/internal/projects/{projectKey}/team-config
     *
     * Enregistre la config du projet. Pour l'instant : le board par défaut. Tout
     * projet configuré est ajouté à watched_projects.json.
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');
            if (!$this->isValidProjectKey($projectKey)) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $repo = new TeamConfigRepository();
            // Garde-fou : ne jamais écraser une config présente mais corrompue par une sauvegarde
            // partielle (qui repartirait d'un load() vide → perte du reste de la config).
            if ($repo->isCorrupted($projectKey)) {
                return $this->json($response, [
                    'success' => false,
                    'error'   => 'Configuration du projet illisible (fichier corrompu) : sauvegarde bloquée '
                        . 'pour éviter toute perte. Restaurez le fichier avant de modifier.',
                ], 409);
            }

            $body = (array) ($request->getParsedBody() ?? []);

            if (array_key_exists('preferredBoardId', $body)) {
                $raw     = $body['preferredBoardId'];
                $boardId = ($raw === '' || $raw === null) ? null : (int) $raw;
                $repo->setPreferredBoardId($projectKey, $boardId);
            }

            if (array_key_exists('ignoredSprints', $body)) {
                $repo->setIgnoredSprints($projectKey, $this->sanitizeIgnoredSprints($body['ignoredSprints']));
            }

            if (array_key_exists('issueTypes', $body)) {
                $repo->setIssueTypes($projectKey, $this->sanitizeStringList($body['issueTypes']));
            }

            if (array_key_exists('parentProjectKey', $body)) {
                $repo->setParentProjectKey($projectKey, trim((string) ($body['parentProjectKey'] ?? '')));
            }

            if (array_key_exists('releaseTeamName', $body)) {
                $repo->setReleaseTeamName($projectKey, trim((string) ($body['releaseTeamName'] ?? '')));
            }

            if (array_key_exists('members', $body)) {
                $repo->setTeamMembers($projectKey, $this->sanitizeMembers($body['members']));
            }

            // Tout projet configuré devient un projet suivi.
            (new Config())->addWatchedProject($projectKey);

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Normalise la liste des sprints ignorés reçue de l'IHM en objets {id, name}.
     *
     * Accepte soit un tableau déjà décodé, soit une chaîne JSON (POST form-encoded).
     * Ignore les entrées sans id. Les id/name sont convertis en chaînes.
     *
     * @param  string|list<array<string, string|int>>|null $raw Valeur brute du champ POST
     * @return array<int, array{id: string, name: string}>
     */
    private function sanitizeIgnoredSprints($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = isset($entry['id']) ? (string) $entry['id'] : '';
            if ($id === '') {
                continue;
            }
            $clean[] = [
                'id'   => $id,
                'name' => isset($entry['name']) ? (string) $entry['name'] : '',
            ];
        }

        return $clean;
    }

    /**
     * Normalise une liste de chaînes reçue de l'IHM (types de tickets).
     *
     * Accepte un tableau déjà décodé ou une chaîne JSON. Trim, retire les vides
     * et dédoublonne en préservant l'ordre.
     *
     * @param  string|list<string>|null $raw Valeur brute du champ POST (tableau ou JSON encodé)
     * @return string[]
     */
    private function sanitizeStringList($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $seen = [];
        $list = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $list[]       = $value;
        }

        return $list;
    }

    /**
     * Normalise le roster reçu de l'IHM en membres {id, name, role, entryDate, exitDate}.
     *
     * Accepte un tableau déjà décodé ou une chaîne JSON (POST form-encoded). Règles :
     * `name` requis (entrée sans nom ignorée) ; `role` restreint à ALLOWED_ROLES (défaut "Dev") ;
     * `entryDate`/`exitDate` validées au format `YYYY-MM-DD`, sinon vidées ; `id` conservé s'il est
     * fourni, sinon généré (identifiant stable indexant la grille de capacité par membre).
     *
     * @param  string|list<array<string, string>>|null $raw Valeur brute du champ POST
     * @return array<int, array{id: string, name: string, role: string, entryDate: string, exitDate: string}>
     */
    private function sanitizeMembers($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $role = (string) ($entry['role'] ?? '');
            $id   = trim((string) ($entry['id'] ?? ''));

            $clean[] = [
                'id'        => $id !== '' ? $id : 'm' . str_replace('.', '', uniqid('', true)),
                'name'      => $name,
                'role'      => in_array($role, self::ALLOWED_ROLES, true) ? $role : 'Dev',
                'entryDate' => $this->sanitizeIsoDate($entry['entryDate'] ?? ''),
                'exitDate'  => $this->sanitizeIsoDate($entry['exitDate'] ?? ''),
            ];
        }

        return $clean;
    }

    /**
     * Valide une date ISO `YYYY-MM-DD` : retourne la valeur si conforme et réelle, sinon ''.
     */
    private function sanitizeIsoDate(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '';
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : '';
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
