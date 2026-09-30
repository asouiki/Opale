<?php

namespace App\Controller;

use App\Model\Config;
use App\Service\AbsenceImportParser;
use App\Service\AbsenceMatcher;
use App\Service\CapacityCalculator;
use App\Service\ParsedImport;
use App\Service\TeamConfigRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * CapacityImportController
 *
 * Import d'un extract Excel d'absences (\App\Service\AbsenceImportParser) dans la grille de capacité, pour
 * tous les sprints couverts en une fois. Alimente le bouton « Importer » de l'onglet Capacité.
 *
 * Chaîne : parse le fichier → associe les noms au roster (\App\Service\AbsenceMatcher, exact normalisé) →
 * range chaque date d'absence dans le sprint qui la contient → réécrit la présence des membres du fichier
 * bornée à la plage couverte (le fichier fait foi : hors plage la saisie existante est préservée) → recalcule
 * les totaux (\App\Service\CapacityCalculator) → persiste. Retourne un récap (matchés, non matchés, jours
 * importés, cellules ignorées) pour l'IHM.
 */
class CapacityImportController
{
    /** Taille maximale acceptée pour le fichier importé (5 Mo). */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** Fichier d'exemple téléchargeable depuis la modale d'import (dans import/). */
    private const EXAMPLE_FILENAME = 'Exemple_import_conges.xlsx';

    private ?string $configDir;

    public function __construct(?string $configDir = null)
    {
        $this->configDir = $configDir;
    }

    /**
     * POST /api/internal/projects/{projectKey}/capacity/import
     *
     * Corps multipart : `file` (.xlsx) + `sprints` (JSON `[{id,startDate,endDate}]`). Écrit la présence des
     * membres reconnus sur les jours ouvrés couverts par le fichier, recalcule les totaux, et retourne un récap.
     *
     * @param array<string, string> $args Paramètres de route (projectKey)
     */
    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $projectKey = (string) ($args['projectKey'] ?? '');
            if (preg_match('/^[A-Za-z0-9_]+$/', $projectKey) !== 1) {
                return $this->json($response, ['success' => false, 'error' => 'projectKey invalide'], 400);
            }

            $file = $this->uploadedFile($request);
            if ($file === null) {
                return $this->json($response, ['success' => false, 'error' => 'Fichier manquant ou invalide.'], 400);
            }
            if (strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION)) !== 'xlsx') {
                return $this->json($response, ['success' => false, 'error' => 'Seuls les fichiers .xlsx sont acceptés.'], 400);
            }
            if (($file->getSize() ?? 0) > self::MAX_FILE_SIZE) {
                return $this->json($response, ['success' => false, 'error' => 'Fichier trop volumineux (max 5 Mo).'], 400);
            }

            $sprints = $this->extractSprints($request);
            if ($sprints === []) {
                return $this->json($response, ['success' => false, 'error' => 'Aucun sprint daté fourni.'], 400);
            }

            $parsed = $this->parseUpload($file);

            return $this->json($response, $this->apply($projectKey, $parsed, $sprints));
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/internal/absence-import/example
     *
     * Streame le fichier d'exemple (import/) en pièce jointe pour aider l'utilisateur à formater son extract.
     */
    public function example(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = $this->importDir() . '/' . self::EXAMPLE_FILENAME;
        if (!is_file($path)) {
            return $this->json($response, ['success' => false, 'error' => 'Fichier d\'exemple introuvable.'], 404);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $this->json($response, ['success' => false, 'error' => 'Fichier d\'exemple illisible.'], 500);
        }

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::EXAMPLE_FILENAME . '"')
            ->withBody(new \Slim\Psr7\Stream($stream));
    }

    /**
     * Fusionne la présence importée d'un membre pour un sprint, bornée aux jours ouvrés éditables couverts.
     * Le fichier fait foi sur ces jours : une date absente devient 0/0.5, une date non listée (présent) efface
     * un éventuel override existant. Les dates hors de cette liste ne sont pas touchées.
     *
     * @param  array<string, float> $existing             Présence stockée du membre (date → valeur)
     * @param  array<string, float> $fileAbsences         Absences du fichier pour ce membre (date → 0|0.5)
     * @param  list<string>         $editableCoveredDates Jours ouvrés éditables ∈ couverture, pour ce sprint/membre
     * @return array{presence: array<string, float>, applied: list<string>}
     */
    public function mergePresence(array $existing, array $fileAbsences, array $editableCoveredDates): array
    {
        $presence = $existing;
        $applied  = [];
        foreach ($editableCoveredDates as $date) {
            if (array_key_exists($date, $fileAbsences)) {
                $presence[$date] = $fileAbsences[$date];
                $applied[]       = $date;
            } else {
                unset($presence[$date]);
            }
        }

        return ['presence' => $presence, 'applied' => $applied];
    }

    /**
     * Réécrit les jours marqués « prévisionnels » (code `P`) d'un membre, bornés aux jours ouvrés éditables
     * couverts. Même règle que la présence : le fichier fait foi sur ces jours (un jour qui n'y est plus
     * prévisionnel perd son marqueur) ; les jours hors de cette liste sont préservés.
     *
     * @param  array<mixed>        $existing             Jours déjà marqués prévisionnels, tels que stockés (JSON brut)
     * @param  array<string, true> $fileForecast         Jours prévisionnels du fichier pour ce membre
     * @param  list<string>        $editableCoveredDates Jours ouvrés éditables ∈ couverture, pour ce sprint/membre
     * @return list<string>        Jours prévisionnels fusionnés, triés
     */
    private function mergeForecast(array $existing, array $fileForecast, array $editableCoveredDates): array
    {
        // Le stockage vient d'un JSON éditable à la main : on ne retient que les dates réellement exploitables.
        $flags = [];
        foreach ($existing as $date) {
            if (is_string($date)) {
                $flags[$date] = true;
            }
        }
        foreach ($editableCoveredDates as $date) {
            if (isset($fileForecast[$date])) {
                $flags[$date] = true;
            } else {
                unset($flags[$date]);
            }
        }
        $dates = array_keys($flags);
        sort($dates);

        return $dates;
    }

    /**
     * Cœur de l'import : associe les noms, réécrit la présence sprint par sprint (bornée à la plage couverte),
     * recalcule les totaux et persiste. Retourne le récap destiné à l'IHM.
     *
     * @param  list<array{id: string, startDate: string, endDate: string}> $sprints
     * @return array{success: bool, sprintsTouched: list<string>, sheetsRead: list<string>, sheetsHidden: list<string>, matchedMembers: list<string>, unmatchedNames: list<string>, notInFile: list<string>, daysImported: int, cellsIgnored: int, warnings: list<string>, notices: list<string>}
     */
    private function apply(string $projectKey, ParsedImport $parsed, array $sprints): array
    {
        $repo   = new TeamConfigRepository($this->configDir);
        $data   = $repo->load($projectKey);
        $roster = $this->roster($data);

        $matchResult        = (new AbsenceMatcher())->match($parsed->names, $roster);
        $absencesByMemberId = $this->absencesByMemberId($parsed, $matchResult['matched']);
        $forecastByMemberId = $this->forecastByMemberId($parsed, $matchResult['matched']);
        $memberById         = [];
        foreach ($roster as $member) {
            $memberById[$member['id']] = $member;
        }

        // Ressources déclarées dans OPALE mais absentes du fichier (non mises à jour par l'import).
        $matchedIds = array_values($matchResult['matched']);
        $notInFile  = [];
        foreach ($roster as $member) {
            if (!in_array($member['id'], $matchedIds, true)) {
                $notInFile[] = $member['name'];
            }
        }

        $holidays   = $this->holidays();
        $holidaySet = array_flip($holidays);
        $coveredSet = array_flip($parsed->coveredDates);

        // Grilles de capacité logées dans le nœud fusionné sprints.<id>.capacity : on les extrait en une
        // map de travail sprintId → grille, puis on les réintègre après recalcul (cf. plus bas).
        $sprintsMap = is_array($data['sprints'] ?? null) ? $data['sprints'] : [];
        $capacities = [];
        foreach ($sprintsMap as $sid => $entry) {
            if (is_array($entry) && is_array($entry['capacity'] ?? null)) {
                $capacities[(string) $sid] = $entry['capacity'];
            }
        }
        $calculator     = new CapacityCalculator();
        $applied        = [];
        $sprintsTouched = [];

        foreach ($sprints as $sprint) {
            $covered = array_values(array_filter(
                $this->sprintWeekdays($sprint['startDate'], $sprint['endDate']),
                static fn (string $d): bool => isset($coveredSet[$d])
            ));
            if ($covered === []) {
                continue; // sprint hors de la plage couverte par le fichier → non touché
            }

            $sid     = $sprint['id'];
            $cap     = is_array($capacities[$sid] ?? null) ? $capacities[$sid] : [];
            $members = is_array($cap['members'] ?? null) ? $cap['members'] : [];

            foreach ($matchResult['matched'] as $memberId) {
                $member   = $memberById[$memberId];
                $editable = array_values(array_filter(
                    $covered,
                    fn (string $d): bool => !isset($holidaySet[$d]) && $this->inTenure($member, $d)
                ));
                if ($editable === []) {
                    continue;
                }

                $existing = is_array($members[$memberId]['presence'] ?? null) ? $members[$memberId]['presence'] : [];
                $merge    = $this->mergePresence($existing, $absencesByMemberId[$memberId] ?? [], $editable);
                foreach ($merge['applied'] as $date) {
                    $applied[$memberId][$date] = true;
                }

                $wasForecast = is_array($members[$memberId]['forecast'] ?? null) ? $members[$memberId]['forecast'] : [];
                $forecast    = $this->mergeForecast($wasForecast, $forecastByMemberId[$memberId] ?? [], $editable);

                $members[$memberId]['presence'] = $merge['presence'];
                $members[$memberId]['forecast'] = $forecast;
                if ($merge['presence'] === []) {
                    unset($members[$memberId]['presence']);
                }
                if ($forecast === []) {
                    unset($members[$memberId]['forecast']);
                }
                if (($members[$memberId] ?? []) === []) {
                    unset($members[$memberId]);
                }
            }

            $sprintsTouched[] = $sid;

            // Le fichier fait foi : on (re)calcule TOUJOURS les totaux du sprint couvert, même sans
            // aucune absence (grille « tout le monde présent » = capacité pleine), pour rester cohérent
            // avec la saisie manuelle (setSprintCapacity conserve une grille dès presenceTotal > 0).
            if ($members === []) {
                unset($cap['members']);
            } else {
                $cap['members'] = $members;
            }
            $cap['totals'] = $calculator->computeTotals($roster, $cap, $sprint['startDate'], $sprint['endDate'], $holidays);

            // On ne retire la grille que si aucune capacité exploitable (personne présent : hors tenure…).
            if ($cap['totals']['presenceTotal'] <= 0.0) {
                unset($capacities[$sid]);
            } else {
                $capacities[$sid] = $cap;
            }
        }

        // Réintègre les grilles recalculées dans le nœud fusionné, et nettoie les sprints dont la grille a
        // été retirée (sous-nœud capacity + entrée sprint devenue vide).
        foreach ($sprintsTouched as $sid) {
            $sid = (string) $sid;
            if (isset($capacities[$sid])) {
                $sprintsMap[$sid]['capacity'] = $capacities[$sid];
            } else {
                unset($sprintsMap[$sid]['capacity']);
                if (($sprintsMap[$sid] ?? []) === []) {
                    unset($sprintsMap[$sid]);
                }
            }
        }

        $importedAt                   = (new \DateTimeImmutable())->format(DATE_ATOM);
        $data['sprints']              = $sprintsMap;
        $data['lastCapacityImportAt'] = $importedAt;
        $repo->save($projectKey, $data);

        return $this->buildReport($matchResult, $notInFile, $absencesByMemberId, $applied, $sprintsTouched, $parsed, $importedAt);
    }

    /**
     * Construit le récap de l'import (comptages, onglets lus/masqués, ressources non mises à jour, avertissements).
     *
     * @param  array{matched: array<string, string>, unmatched: list<string>} $matchResult
     * @param  list<string>                                                   $notInFile          Membres OPALE absents du fichier
     * @param  array<string, array<string, float>>                            $absencesByMemberId
     * @param  array<string, array<string, bool>>                             $applied
     * @param  list<string>                                                   $sprintsTouched
     * @param  string                                                         $importedAt         Date/heure de l'import (ISO 8601)
     * @return array{success: bool, sprintsTouched: list<string>, sheetsRead: list<string>, sheetsHidden: list<string>, matchedMembers: list<string>, unmatchedNames: list<string>, notInFile: list<string>, daysImported: int, cellsIgnored: int, warnings: list<string>, notices: list<string>, lastImportAt: string}
     */
    private function buildReport(array $matchResult, array $notInFile, array $absencesByMemberId, array $applied, array $sprintsTouched, ParsedImport $parsed, string $importedAt): array
    {
        $daysImported = 0;
        foreach ($applied as $dates) {
            $daysImported += count($dates);
        }
        $totalCells = 0;
        foreach ($absencesByMemberId as $dates) {
            $totalCells += count($dates);
        }
        $cellsIgnored = max(0, $totalCells - $daysImported);

        $warnings = [];
        if ($matchResult['unmatched'] !== []) {
            $warnings[] = count($matchResult['unmatched']) . ' nom(s) du fichier non reconnu(s) dans OPALE (ignorés) : '
                . implode(', ', $matchResult['unmatched']);
        }
        if ($cellsIgnored > 0) {
            $warnings[] = $cellsIgnored . ' absence(s) hors sprint / week-end / férié ignorée(s).';
        }

        // Notices (informatives, non bloquantes).
        $notices = [];
        if ($parsed->sheetsRead !== []) {
            $notices[] = 'Onglet(s) importé(s) : ' . implode(', ', $parsed->sheetsRead) . '.';
        }
        if ($parsed->sheetsHidden !== []) {
            $notices[] = count($parsed->sheetsHidden) . ' onglet(s) masqué(s) ignoré(s) : '
                . implode(', ', $parsed->sheetsHidden) . '.';
        }
        if ($notInFile !== []) {
            $notices[] = count($notInFile) . ' ressource(s) dans OPALE sont absente(s) du fichier (non mise(s) à jour) : '
                . implode(', ', $notInFile);
        }

        return [
            'success'        => true,
            'sprintsTouched' => array_values(array_unique($sprintsTouched)),
            'sheetsRead'     => $parsed->sheetsRead,
            'sheetsHidden'   => $parsed->sheetsHidden,
            'matchedMembers' => array_keys($matchResult['matched']),
            'unmatchedNames' => $matchResult['unmatched'],
            'notInFile'      => $notInFile,
            'daysImported'   => $daysImported,
            'cellsIgnored'   => $cellsIgnored,
            'warnings'       => $warnings,
            'notices'        => $notices,
            'lastImportAt'   => $importedAt,
        ];
    }

    /**
     * Indexe les absences du fichier par id de membre (via les noms matchés). Dernière valeur par date gagne.
     *
     * @param  array<string, string> $matched Nom fichier → id membre
     * @return array<string, array<string, float>> id membre → (date → valeur)
     */
    private function absencesByMemberId(ParsedImport $parsed, array $matched): array
    {
        $result = [];
        foreach ($matched as $fileName => $memberId) {
            foreach ($parsed->absencesByName[$fileName] ?? [] as $cell) {
                $result[$memberId][$cell['date']] = $cell['value'];
            }
        }

        return $result;
    }

    /**
     * Indexe par id de membre les seules cellules d'absence marquées « prévisionnelles » (code `P`).
     *
     * @param  array<string, string> $matched Nom fichier → id membre
     * @return array<string, array<string, true>> id membre → (date → true)
     */
    private function forecastByMemberId(ParsedImport $parsed, array $matched): array
    {
        $result = [];
        foreach ($matched as $fileName => $memberId) {
            foreach ($parsed->absencesByName[$fileName] ?? [] as $cell) {
                if ($cell['forecast']) {
                    $result[$memberId][$cell['date']] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Roster typé du projet (id/name/role/entryDate/exitDate en chaînes), issu de la config chargée.
     *
     * @param  array<string, mixed> $data Config projet chargée
     * @return list<array{id: string, name: string, role: string, entryDate: string, exitDate: string}>
     */
    private function roster(array $data): array
    {
        $roster  = [];
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];
        foreach ($members as $member) {
            if (!is_array($member) || !isset($member['id'])) {
                continue;
            }
            $roster[] = [
                'id'        => (string) $member['id'],
                'name'      => (string) ($member['name'] ?? ''),
                'role'      => (string) ($member['role'] ?? ''),
                'entryDate' => (string) ($member['entryDate'] ?? ''),
                'exitDate'  => (string) ($member['exitDate'] ?? ''),
            ];
        }

        return $roster;
    }

    /**
     * Jours fériés (Y-m-d) via GovApiService/Config ; dégradation gracieuse en liste vide si l'API échoue.
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
     * Jours ouvrés (lundi→vendredi) de la fenêtre d'un sprint.
     *
     * @return list<string> Dates `Y-m-d`
     */
    private function sprintWeekdays(string $startDate, string $endDate): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($start === false || $end === false) {
            return [];
        }

        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') < 6) {
                $days[] = $d->format('Y-m-d');
            }
        }

        return $days;
    }

    /**
     * Le membre est-il présent le jour `date` ? Entrée et sortie incluses : présent sur [entryDate, exitDate].
     * Cas sortie temporaire puis retour (exitDate < entryDate, les deux renseignées) : présent partout sauf
     * strictement entre les deux dates (les bornes elles-mêmes restent des jours de présence).
     *
     * @param array{entryDate: string, exitDate: string} $member
     */
    private function inTenure(array $member, string $date): bool
    {
        if ($member['entryDate'] !== '' && $member['exitDate'] !== '' && $member['exitDate'] < $member['entryDate']) {
            return $date <= $member['exitDate'] || $date >= $member['entryDate'];
        }
        if ($member['entryDate'] !== '' && $date < $member['entryDate']) {
            return false;
        }
        if ($member['exitDate'] !== '' && $date > $member['exitDate']) {
            return false;
        }

        return true;
    }

    /**
     * Récupère le fichier uploadé « file » s'il est présent et sans erreur, sinon null.
     */
    private function uploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        return $file;
    }

    /**
     * Extrait et valide la liste des sprints datés du corps de la requête (champ JSON « sprints »).
     *
     * @return list<array{id: string, startDate: string, endDate: string}>
     */
    private function extractSprints(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $raw  = is_array($body) ? ($body['sprints'] ?? null) : null;
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sprints = [];
        foreach ($decoded as $sprint) {
            if (!is_array($sprint)) {
                continue;
            }
            // Tolérant aux datetime ISO de Jira (« 2026-06-29T09:00:00Z ») : on ne retient que la date.
            $id    = (string) ($sprint['id'] ?? '');
            $start = substr((string) ($sprint['startDate'] ?? ''), 0, 10);
            $end   = substr((string) ($sprint['endDate'] ?? ''), 0, 10);
            if ($id !== '' && $this->isIsoDate($start) && $this->isIsoDate($end)) {
                $sprints[] = ['id' => $id, 'startDate' => $start, 'endDate' => $end];
            }
        }

        return $sprints;
    }

    /**
     * Place le fichier uploadé temporairement dans le dossier import/, le parse, puis le supprime
     * systématiquement (le fichier brut — potentiellement des données RH — n'est jamais conservé).
     *
     * @throws \RuntimeException Si le fichier ne peut être traité
     */
    private function parseUpload(UploadedFileInterface $file): ParsedImport
    {
        $dir = $this->importDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier d\'import indisponible.');
        }
        $tmp = tempnam($dir, 'upload_');
        if ($tmp === false) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire pour l\'import.');
        }

        try {
            $file->moveTo($tmp);

            // Le nom d'origine (« 2026 EQUIPES REPAIRES ») sert à déduire l'année ; le chemin temp ne le porte pas.
            return (new AbsenceImportParser())->parse($tmp, (int) date('Y'), $file->getClientFilename());
        } finally {
            @unlink($tmp);
        }
    }

    /** Dossier import/ à la racine du projet (fichier d'exemple + emplacement temporaire des uploads). */
    private function importDir(): string
    {
        return \dirname(__DIR__, 2) . '/import';
    }

    /**
     * Valide une date au format ISO `YYYY-MM-DD` (réelle).
     */
    private function isIsoDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Écrit une réponse JSON et positionne l'en-tête / le code HTTP.
     *
     * @param array<string, mixed> $payload Charge utile à sérialiser
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
