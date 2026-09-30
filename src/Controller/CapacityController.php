<?php

namespace App\Controller;

use App\Model\Config;
use App\Service\DayEventsPropagator;
use App\Service\TeamConfigRepository;
use App\Support\ErrorLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CapacityController
 *
 * Sert la page /capacity et les endpoints de lecture/saisie de la grille de capacité d'un sprint
 * (présence quotidienne par membre, ligne Événements, ratio de conversion et totaux), persistée par projet
 * via \App\Service\TeamConfigRepository sous la clé "capacity".
 *
 * Page autonome : n'affecte pas les champs manDays/capacityPoints du sprint (gérés par ManDaysController).
 */
class CapacityController
{
    /** Rôles porteurs d'une capacité à faire (PO/SM comptent en présence mais pas en capa). */
    private const CAPA_ROLES = ['Dev', 'BA', 'QA'];

    /**
     * GET /capacity
     *
     * Affiche la grille de capacité. Aucune donnée n'est préparée côté serveur : le composant résout
     * lui-même son contexte (projet/board depuis le pool partagé, roster depuis /config) et charge la
     * grille via les endpoints get()/save() ci-dessous.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require __DIR__ . '/../../views/pages/capacity.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);

        return $response;
    }

    /**
     * GET /api/internal/projects/{projectKey}/sprints/{sprintId}/capacity
     *
     * Retourne la grille de capacité stockée pour le sprint (ou [] si aucune saisie).
     *
     * @param array<string, string> $args Paramètres de route (projectKey, sprintId)
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$projectKey, $sprintId] = $this->validateArgs($args);
            if ($projectKey === null) {
                return $this->json($response, ['success' => false, 'error' => $sprintId], 400);
            }

            $capacity = (new TeamConfigRepository())->getSprintCapacity($projectKey, $sprintId);

            return $this->json($response, $capacity);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/internal/projects/{projectKey}/sprints/{sprintId}/capacity
     *
     * Body urlencoded : capacity=<JSON>. La grille est normalisée avant persistance (présence bornée
     * à [0, 1], événements ≥ 0, ratio 0–100, totaux coercés en nombres). Retourne { "success": true }.
     *
     * @param array<string, string> $args Paramètres de route (projectKey, sprintId)
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$projectKey, $sprintId] = $this->validateArgs($args);
            if ($projectKey === null) {
                return $this->json($response, ['success' => false, 'error' => $sprintId], 400);
            }

            $body     = (array) ($request->getParsedBody() ?? []);
            $capacity = $this->sanitizeCapacity($body['capacity'] ?? null);

            (new TeamConfigRepository())->setSprintCapacity($projectKey, $sprintId, $capacity);

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/internal/projects/{projectKey}/sprints/{sprintId}/capacity/day-events/propagate
     *
     * Bouton « Appliquer à tous les futurs sprints » de la colonne Événements : reporte le modèle d'événements du sprint
     * {sprintId} sur les sprints cibles fournis (règles dans \App\Service\DayEventsPropagator).
     * Body urlencoded : sourceStartDate, sourceEndDate (Y-m-d), dayEvents=<JSON { Y-m-d: 0.5|1 }> et
     * sprints=<JSON [{id, startDate, endDate}]> (cibles choisies par l'IHM, le sprint source en est écarté).
     * Retourne { success, applied: [ids], skipped: [ids] } (skipped = durée différente, non modifiés).
     *
     * @param array<string, string> $args Paramètres de route (projectKey, sprintId)
     */
    public function propagateDayEvents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$projectKey, $sprintId] = $this->validateArgs($args);
        if ($projectKey === null) {
            return $this->json($response, ['success' => false, 'error' => $sprintId], 400);
        }

        $data        = (array) ($request->getParsedBody() ?? []);
        $sourceStart = substr(trim((string) ($data['sourceStartDate'] ?? '')), 0, 10);
        $sourceEnd   = substr(trim((string) ($data['sourceEndDate'] ?? '')), 0, 10);
        if (!$this->isIsoDate($sourceStart) || !$this->isIsoDate($sourceEnd)) {
            return $this->json($response, ['success' => false, 'error' => 'Dates du sprint source invalides.'], 400);
        }

        $sourceEvents = $this->sanitizeDayEvents($this->decodeJsonField($data['dayEvents'] ?? null));
        $targets      = array_values(array_filter(
            $this->sanitizeSprints($this->decodeJsonField($data['sprints'] ?? null)),
            static fn (array $sprint): bool => $sprint['id'] !== $sprintId
        ));
        if ($targets === []) {
            return $this->json($response, ['success' => false, 'error' => 'Aucun sprint futur à mettre à jour.'], 400);
        }

        try {
            $result = (new DayEventsPropagator(new TeamConfigRepository(), $this->holidays()))
                ->propagate($projectKey, $sourceEvents, $sourceStart, $sourceEnd, $targets);
        } catch (\Throwable $e) {
            ErrorLogger::critical($e, 'Propagation des événements du sprint ' . $sprintId . ' (projet ' . $projectKey . ')');

            return $this->json($response, ['success' => false, 'error' => 'Impossible d\'enregistrer les événements des sprints futurs.'], 500);
        }

        return $this->json($response, ['success' => true] + $result);
    }

    /**
     * Décode un champ POST contenant un objet/tableau JSON (null si absent ou illisible : traité comme une saisie vide).
     *
     * @return array<int|string, mixed>|null Structure brute, validée ensuite par l'appelant
     */
    private function decodeJsonField(mixed $raw): ?array
    {
        if (!is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalise la liste des sprints cibles envoyée par l'IHM : id numérique et dates réduites à Y-m-d (tolère les datetime
     * ISO de Jira, « 2026-06-29T09:00:00Z »). Les entrées incomplètes sont écartées.
     *
     * @param  array<int|string, mixed>|null                               $raw Liste brute [{id, startDate, endDate}]
     * @return list<array{id: string, startDate: string, endDate: string}>
     */
    private function sanitizeSprints(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $sprints = [];
        foreach ($raw as $sprint) {
            if (!is_array($sprint)) {
                continue;
            }
            $id    = (string) ($sprint['id'] ?? '');
            $start = substr((string) ($sprint['startDate'] ?? ''), 0, 10);
            $end   = substr((string) ($sprint['endDate'] ?? ''), 0, 10);
            if (ctype_digit($id) && $this->isIsoDate($start) && $this->isIsoDate($end)) {
                $sprints[] = ['id' => $id, 'startDate' => $start, 'endDate' => $end];
            }
        }

        return $sprints;
    }

    /**
     * Jours fériés (Y-m-d) connus du projet ; dégradation en liste vide si l'API des fériés est indisponible (les totaux
     * sont alors calculés sans distinction des fériés, comme la grille elle-même).
     *
     * @return list<string>
     */
    private function holidays(): array
    {
        $holidays = [];
        try {
            foreach ((new Config())->getNonWorkingDays() as $frenchDate) {
                $date = \DateTime::createFromFormat('d/m/Y', $frenchDate);
                if ($date !== false) {
                    $holidays[] = $date->format('Y-m-d');
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $holidays;
    }

    /**
     * Normalise la grille de capacité reçue de l'IHM (chaîne JSON du champ POST « capacity »).
     *
     * Structure retournée : { members: { <memberId>: { presence: { Y-m-d: 0|0.5|1 }, forecast: [Y-m-d], ratio } },
     * dayEvents: { Y-m-d: 0|0.5|1 }, totals: { presenceTotal, capa: { Dev, BA, QA }, capaTotal } }.
     * Les événements sont saisis par jour (partagés par l'équipe), non plus par membre. Les entrées
     * membres sans donnée exploitable sont écartées. Les totaux sont conservés même sans absence saisie
     * (grille « tout le monde présent ») : c'est setSprintCapacity qui décide de persister ou d'effacer
     * selon la capacité réelle (presenceTotal). Une charge totalement vide retourne [].
     *
     * @param  string|null $raw Valeur brute du champ POST (JSON encodé)
     * @return array{members?: array<string, array{presence?: array<string, float>, forecast?: list<string>, ratio?: float}>, dayEvents?: array<string, float>, totals?: array{presenceTotal: float, capa: array<string, float>, capaTotal: float}}
     */
    private function sanitizeCapacity(?string $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $membersRaw = $decoded['members'] ?? [];
        $members    = [];
        if (is_array($membersRaw)) {
            foreach ($membersRaw as $memberId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $clean = $this->sanitizeMemberEntry($entry);
                if ($clean !== []) {
                    $members[(string) $memberId] = $clean;
                }
            }
        }

        $result = [];
        if ($members !== []) {
            $result['members'] = $members;
        }

        $dayEvents = $this->sanitizeDayEvents($decoded['dayEvents'] ?? null);
        if ($dayEvents !== []) {
            $result['dayEvents'] = $dayEvents;
        }

        $totalsRaw = $decoded['totals'] ?? null;
        if (is_array($totalsRaw)) {
            $result['totals'] = $this->sanitizeTotals($totalsRaw);
        }

        return $result;
    }

    /**
     * Normalise la saisie d'un membre : présence (jours bornés à [0, 1], seuls les jours ≠ 1 sont
     * conservés), jours « prévisionnels » (marqueur d'affichage issu du code `P` de l'import) et ratio
     * (0–100, seul un ratio ≠ 100 est conservé). Les événements ne sont plus portés par le membre : ils
     * sont saisis par jour (voir sanitizeDayEvents()).
     *
     * @param  array<string, mixed> $entry Saisie brute d'un membre
     * @return array{presence?: array<string, float>, forecast?: list<string>, ratio?: float}
     */
    private function sanitizeMemberEntry(array $entry): array
    {
        $clean = [];

        $presenceRaw = $entry['presence'] ?? [];
        if (is_array($presenceRaw)) {
            $presence = [];
            foreach ($presenceRaw as $date => $value) {
                $day = (string) $date;
                $val = $this->clampPresence($value);
                if ($this->isIsoDate($day) && $val !== null && $val !== 1.0) {
                    $presence[$day] = $val;
                }
            }
            if ($presence !== []) {
                $clean['presence'] = $presence;
            }
        }

        $forecast = $this->sanitizeForecast($entry['forecast'] ?? null);
        if ($forecast !== []) {
            $clean['forecast'] = $forecast;
        }

        $ratio = $this->boundedRatio($entry['ratio'] ?? null);
        if ($ratio !== null && $ratio !== 100.0) {
            $clean['ratio'] = $ratio;
        }

        return $clean;
    }

    /**
     * Normalise les jours « prévisionnels » d'un membre : liste de dates ISO dédoublonnée et triée.
     * Marqueur purement visuel (la journée est déjà comptée 0 via la présence), donc aucun impact sur
     * les totaux.
     *
     * @param  mixed        $raw Bloc brut [ "Y-m-d", … ] issu de l'IHM
     * @return list<string>
     */
    private function sanitizeForecast(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $dates = [];
        foreach ($raw as $value) {
            $day = is_string($value) ? $value : '';
            if ($this->isIsoDate($day)) {
                $dates[$day] = true;
            }
        }
        $clean = array_keys($dates);
        sort($clean);

        return $clean;
    }

    /**
     * Normalise les événements par jour du sprint (planif, démo, rétro…), partagés par toute l'équipe :
     * clés au format date ISO, valeurs bornées à [0, 1] par pas de 0,5, seules les valeurs > 0 conservées.
     *
     * @param  mixed $raw Bloc brut { Y-m-d: float } issu de l'IHM
     * @return array<string, float>
     */
    private function sanitizeDayEvents(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $events = [];
        foreach ($raw as $date => $value) {
            $day = (string) $date;
            if (!$this->isIsoDate($day) || !is_numeric($value)) {
                continue;
            }
            $val = round(max(0.0, min(1.0, (float) $value)) * 2) / 2;
            if ($val > 0) {
                $events[$day] = $val;
            }
        }

        return $events;
    }

    /**
     * Coerce les totaux (informatifs, réutilisés par l'agrégation PI) en nombres sûrs.
     *
     * @param  array<string, mixed> $raw Totaux bruts
     * @return array{presenceTotal: float, capa: array<string, float>, capaTotal: float}
     */
    private function sanitizeTotals(array $raw): array
    {
        $capaRaw = is_array($raw['capa'] ?? null) ? $raw['capa'] : [];
        $capa    = [];
        foreach (self::CAPA_ROLES as $role) {
            $capa[$role] = $this->toFloat($capaRaw[$role] ?? null);
        }

        return [
            'presenceTotal' => $this->toFloat($raw['presenceTotal'] ?? null),
            'capa'          => $capa,
            'capaTotal'     => $this->toFloat($raw['capaTotal'] ?? null),
        ];
    }

    /**
     * Borne une valeur de présence à l'intervalle [0, 1] avec 2 décimales, ou null si non numérique.
     */
    private function clampPresence(mixed $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $raw)), 2);
    }

    /**
     * Borne un ratio de conversion en pourcentage à [0, 100], ou null si non numérique.
     */
    private function boundedRatio(mixed $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }

        return max(0.0, min(100.0, (float) $raw));
    }

    /**
     * Convertit une valeur numérique en float positif ou nul (0.0 si non numérique).
     */
    private function toFloat(mixed $raw): float
    {
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Valide une date au format ISO `YYYY-MM-DD` (réelle).
     */
    private function isIsoDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Valide et extrait projectKey/sprintId des paramètres de route.
     *
     * @param  array<string, string> $args Paramètres de route
     * @return array{0: string|null, 1: string} [projectKey, sprintId] si valides, sinon [null, message]
     */
    private function validateArgs(array $args): array
    {
        $projectKey = (string) ($args['projectKey'] ?? '');
        $sprintId   = (string) ($args['sprintId'] ?? '');

        if ($projectKey === '' || preg_match('/^[A-Za-z0-9_]+$/', $projectKey) !== 1) {
            return [null, 'projectKey invalide'];
        }
        if (!ctype_digit($sprintId)) {
            return [null, 'sprintId invalide'];
        }

        return [$projectKey, $sprintId];
    }

    /**
     * Écrit une réponse JSON et positionne l'en-tête / le code HTTP.
     *
     * @param array<string, mixed> $payload Charge utile à sérialiser
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
