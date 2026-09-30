<?php

namespace App\Service;

/**
 * AbsenceImportParser
 *
 * Lit un extract Excel RH (matrice calendaire : bandeaux de mois en en-tête, une ligne « Nom Prénom » +
 * numéros de jour, une personne par ligne, codes d'absence en cellules) et le convertit en dates ISO
 * d'absence par personne, plus la plage de dates couverte par le fichier (voir \App\Service\ParsedImport).
 *
 * Règles métier :
 * - Cellule vide = présent (non émis). Cellule contenant `1/2A` / `1/2` / `0.5` = demi-journée (0.5).
 *   Toute autre cellule non vide (A, P, TP, F…) = absence journée entière (0).
 * - Cellule valant exactement `P` = absence **prévisionnelle** : comptée 0 comme une absence classique, mais
 *   marquée (`forecast`) pour être affichée « prév. » au lieu de « 0 » dans la grille de capacité.
 * - L'année n'est pas portée par le fichier : base = année courante fournie, incrémentée à chaque rupture
 *   décroissante de mois (ex. décembre → janvier). Une année annotée dans le bandeau (`JUILLET 2026`) prime.
 *
 * Le parsing XML (pur PHP, sans dépendance) est isolé dans readXlsxGrid() ; l'interprétation métier de la
 * grille est publique (interpret()) pour être testée sans fichier.
 */
class AbsenceImportParser
{
    /** Mois FR normalisés (majuscule sans accent) → numéro de mois. */
    private const MONTHS = [
        'JANVIER'   => 1,
        'FEVRIER'   => 2,
        'MARS'      => 3,
        'AVRIL'     => 4,
        'MAI'       => 5,
        'JUIN'      => 6,
        'JUILLET'   => 7,
        'AOUT'      => 8,
        'SEPTEMBRE' => 9,
        'OCTOBRE'   => 10,
        'NOVEMBRE'  => 11,
        'DECEMBRE'  => 12,
    ];

    /** Contenus (normalisés) d'une cellule valant une demi-journée d'absence. */
    private const HALF_DAY_CODES = ['1/2TP', '1/2A', '1/2', '0.5', '0,5'];

    /** Contenu (normalisé) d'une cellule marquant une absence prévisionnelle (non encore validée). */
    private const FORECAST_CODE = 'P';

    /** Début (normalisé) de la colonne A de l'encart de légende, qui clôt la liste des personnes. */
    private const LEGEND_MARKER = 'LEGENDE';

    /**
     * Parse un fichier .xlsx et retourne les absences par nom + la plage couverte.
     *
     * Lit **toutes les feuilles visibles** (les feuilles masquées `hidden`/`veryHidden` sont ignorées : le vieux
     * semestre archivé ne doit pas polluer l'import) et fusionne leurs absences. L'année de base d'une feuille est
     * déduite en priorité du **nom de fichier** (« 2026 EQUIPES REPAIRES » → 2026), plus fiable que les titres
     * d'onglet parfois mal nommés, sinon du **titre d'onglet**, sinon `$currentYear` ; le rollover Déc→Jan et
     * l'éventuelle annotation par mois restent gérés par interpret().
     *
     * @param string      $path             Chemin du fichier .xlsx à lire
     * @param int         $currentYear      Année de référence par défaut (base avant rollover Déc→Jan)
     * @param string|null $originalFilename Nom d'origine de l'upload (pour en déduire l'année), ou null
     *
     * @throws \RuntimeException Si le fichier est illisible ou qu'aucune feuille exploitable n'est trouvée
     */
    public function parse(string $path, int $currentYear, ?string $originalFilename = null): ParsedImport
    {
        $fileYear = $this->extractYear((string) $originalFilename);

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Fichier .xlsx illisible (archive invalide).');
        }

        $parsed       = [];
        $sheetsRead   = [];
        $sheetsHidden = [];
        try {
            $sharedStrings = $this->readSharedStrings($zip);
            foreach ($this->allSheets($zip) as $sheet) {
                if ($sheet['hidden']) {
                    $sheetsHidden[] = $sheet['name'];
                    continue; // onglet masqué (vieux semestre archivé…) → ignoré ; les LIGNES masquées, elles, sont lues
                }
                $sheetXml = $zip->getFromName($sheet['target']);
                if ($sheetXml === false) {
                    continue;
                }
                $grid     = $this->readSheetGrid($sheetXml, $sharedStrings);
                $baseYear = $fileYear ?? $this->extractYear($sheet['name']) ?? $currentYear;

                try {
                    $parsed[]     = $this->interpret($grid, $baseYear);
                    $sheetsRead[] = $sheet['name'];
                } catch (\RuntimeException) {
                    continue; // feuille visible sans structure d'absences (légende, synthèse…) → ignorée
                }
            }
        } finally {
            $zip->close();
        }

        if ($parsed === []) {
            throw new \RuntimeException('Aucune feuille exploitable dans le fichier .xlsx.');
        }

        return $this->mergeParsed($parsed, $sheetsRead, $sheetsHidden);
    }

    /**
     * Fusionne les feuilles interprétées : union des noms (ordre préservé), concaténation des absences par
     * personne, union triée des dates couvertes ; joint les onglets lus/masqués (pour le récap).
     *
     * @param non-empty-list<ParsedImport> $parsed
     * @param list<string>                 $sheetsRead
     * @param list<string>                 $sheetsHidden
     */
    private function mergeParsed(array $parsed, array $sheetsRead, array $sheetsHidden): ParsedImport
    {
        $names          = [];
        $absencesByName = [];
        $covered        = [];
        foreach ($parsed as $one) {
            foreach ($one->names as $name) {
                $names[$name] = true;
            }
            foreach ($one->absencesByName as $name => $entries) {
                $absencesByName[$name] = array_merge($absencesByName[$name] ?? [], $entries);
            }
            foreach ($one->coveredDates as $date) {
                $covered[$date] = true;
            }
        }
        $coveredDates = array_keys($covered);
        sort($coveredDates);

        return new ParsedImport(array_keys($names), $absencesByName, $coveredDates, $sheetsRead, $sheetsHidden);
    }

    /**
     * Première année `20xx` trouvée dans une chaîne (nom de fichier ou d'onglet), ou null.
     */
    private function extractYear(string $value): ?int
    {
        return preg_match('/(20\d{2})/', $value, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Interprète la grille (indices 1-based ligne → colonne → valeur cellule) en dates d'absence.
     *
     * Détection dynamique : la ligne d'en-tête est celle dont la colonne A ≈ « Nom Prénom » ; la ligne des
     * mois est celle, au-dessus, portant des libellés de mois reconnus. Les personnes sont les lignes sous
     * l'en-tête (colonne A non vide), jusqu'à l'éventuel encart dont la colonne A commence par « Légende » : cet encart et
     * tout ce qui le suit sont ignorés. Une date n'est émise que pour une colonne portant à la fois un mois (report en avant
     * depuis le bandeau fusionné) et un numéro de jour.
     *
     * @param array<int, array<int, string>> $grid        Grille brute (ligne → colonne → texte)
     * @param int                            $currentYear Année de référence (base avant rollover)
     *
     * @throws \RuntimeException Si la structure attendue n'est pas trouvée
     */
    public function interpret(array $grid, int $currentYear): ParsedImport
    {
        $headerRow = $this->findHeaderRow($grid);
        $monthRow  = $this->findMonthRow($grid, $headerRow);

        $columnDates = $this->buildColumnDates($grid[$monthRow], $grid[$headerRow], $currentYear);

        $coveredDates = array_values(array_unique(array_values($columnDates)));
        sort($coveredDates);

        $names          = [];
        $absencesByName = [];
        foreach ($grid as $rowIndex => $cells) {
            if ($rowIndex <= $headerRow) {
                continue;
            }
            $name = trim($cells[1] ?? '');
            if ($name === '') {
                continue;
            }
            // Encart « Légende » : fin de la liste des personnes, les lignes suivantes décrivent les codes d'absence.
            if (str_starts_with($this->normalize($name), self::LEGEND_MARKER)) {
                break;
            }
            $names[] = $name;

            $entries = [];
            foreach ($columnDates as $col => $date) {
                $raw = trim($cells[$col] ?? '');
                if ($raw === '') {
                    continue;
                }
                $entries[] = [
                    'date'     => $date,
                    'value'    => $this->cellValue($raw),
                    'forecast' => $this->isForecast($raw),
                ];
            }
            if ($entries !== []) {
                $absencesByName[$name] = $entries;
            }
        }

        return new ParsedImport($names, $absencesByName, $coveredDates);
    }

    /**
     * Trouve la ligne d'en-tête (colonne A ≈ « Nom Prénom »).
     *
     * @param array<int, array<int, string>> $grid
     *
     * @throws \RuntimeException Si aucune ligne d'en-tête n'est reconnue
     */
    private function findHeaderRow(array $grid): int
    {
        foreach ($grid as $rowIndex => $cells) {
            if (preg_match('/NOM.*PRENOM/', $this->normalize($cells[1] ?? '')) === 1) {
                return $rowIndex;
            }
        }

        throw new \RuntimeException('Structure non reconnue : ligne d\'en-tête « Nom Prénom » introuvable.');
    }

    /**
     * Trouve la ligne des mois : la ligne au-dessus de l'en-tête portant le plus de libellés de mois.
     *
     * @param array<int, array<int, string>> $grid
     *
     * @throws \RuntimeException Si aucune ligne de mois n'est reconnue
     */
    private function findMonthRow(array $grid, int $headerRow): int
    {
        $best      = null;
        $bestCount = 0;
        foreach ($grid as $rowIndex => $cells) {
            if ($rowIndex >= $headerRow) {
                continue;
            }
            $count = 0;
            foreach ($cells as $value) {
                if ($this->monthNumber($value) !== null) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $best      = $rowIndex;
                $bestCount = $count;
            }
        }

        if ($best === null) {
            throw new \RuntimeException('Structure non reconnue : ligne des mois introuvable.');
        }

        return $best;
    }

    /**
     * Construit la map colonne → date ISO, en croisant les bandeaux de mois (report en avant + rollover
     * d'année) et les numéros de jour de la ligne d'en-tête.
     *
     * @param array<int, string> $monthCells  Ligne des mois (colonne → libellé)
     * @param array<int, string> $headerCells Ligne d'en-tête (colonne → numéro de jour)
     * @param int                $currentYear Année de référence (base avant rollover)
     *
     * @return array<int, string> colonne → date `Y-m-d`
     */
    private function buildColumnDates(array $monthCells, array $headerCells, int $currentYear): array
    {
        // Deux lignes vides (aucune colonne) : aucune date à construire.
        $columns   = array_merge(array_keys($monthCells), array_keys($headerCells));
        $maxColumn = $columns === [] ? 0 : max($columns);

        $currentMonth = null;
        $prevMonth    = null;
        $year         = $currentYear;
        $columnDates  = [];

        for ($col = 1; $col <= $maxColumn; $col++) {
            $month = $this->monthNumber($monthCells[$col] ?? '');
            if ($month !== null) {
                $explicitYear = $this->explicitYear($monthCells[$col] ?? '');
                if ($explicitYear !== null) {
                    $year = $explicitYear;
                } elseif ($prevMonth !== null && $month < $prevMonth) {
                    $year++;
                }
                $currentMonth = $month;
                $prevMonth    = $month;
            }

            if ($currentMonth === null) {
                continue;
            }

            $day = trim($headerCells[$col] ?? '');
            if (!ctype_digit($day)) {
                continue;
            }
            $dayInt = (int) $day;
            if (!checkdate($currentMonth, $dayInt, $year)) {
                continue;
            }

            $columnDates[$col] = sprintf('%04d-%02d-%02d', $year, $currentMonth, $dayInt);
        }

        return $columnDates;
    }

    /**
     * Valeur de présence d'une cellule d'absence non vide : 0.5 pour une demi-journée, 0.0 sinon.
     */
    private function cellValue(string $raw): float
    {
        return in_array($this->normalizeCode($raw), self::HALF_DAY_CODES, true) ? 0.5 : 0.0;
    }

    /**
     * La cellule marque-t-elle une absence prévisionnelle (code `P`) ? Elle reste comptée comme une absence
     * journée entière (0) : seul son affichage diffère dans la grille de capacité (« prév. » au lieu de « 0 »).
     */
    private function isForecast(string $raw): bool
    {
        return $this->normalizeCode($raw) === self::FORECAST_CODE;
    }

    /** Contenu d'une cellule normalisé pour comparaison aux codes d'absence : majuscules, sans espace. */
    private function normalizeCode(string $raw): string
    {
        return strtoupper(str_replace(' ', '', $raw));
    }

    /**
     * Numéro de mois d'un libellé (partie alphabétique normalisée), ou null si non reconnu.
     */
    private function monthNumber(string $value): ?int
    {
        $label = $this->normalize($value);
        if (preg_match('/[A-Z]+/', $label, $m) !== 1) {
            return null;
        }

        return self::MONTHS[$m[0]] ?? null;
    }

    /**
     * Année à 4 chiffres annotée dans un libellé de mois (ex. « JUILLET 2026 »), ou null.
     */
    private function explicitYear(string $value): ?int
    {
        if (preg_match('/\b(\d{4})\b/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Normalise une chaîne pour comparaison : accents retirés, majuscule, espaces compactés.
     */
    private function normalize(string $value): string
    {
        $map = [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Õ' => 'O',
            'Û' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Ú' => 'U',
            'Ç' => 'C',
        ];
        $upper = mb_strtoupper(trim($value), 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', strtr($upper, $map)) ?? '');
    }

    /**
     * Énumère les feuilles du classeur (nom + chemin interne + masquée ?), via workbook.xml + workbook.xml.rels.
     * Le flag `hidden` couvre `hidden` **et** `veryHidden` : un onglet masqué (vieux semestre archivé…) ne doit
     * pas être importé, mais est remonté au récap. Repli sur `sheet1.xml` si le classeur ne peut être analysé.
     *
     * @return non-empty-list<array{name: string, target: string, hidden: bool}>
     */
    private function allSheets(\ZipArchive $zip): array
    {
        $fallback = [['name' => '', 'target' => 'xl/worksheets/sheet1.xml', 'hidden' => false]];
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return $fallback;
        }
        if (preg_match_all('/<sheet\b[^>]*>/', $workbook, $tags) === false || $tags[0] === []) {
            return $fallback;
        }

        $sheets = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\br:id="([^"]+)"/', $tag, $mr) !== 1) {
                continue;
            }
            $pattern = '/<Relationship\b[^>]*\bId="' . preg_quote($mr[1], '/') . '"[^>]*\bTarget="([^"]+)"/';
            if (preg_match($pattern, $rels, $mt) !== 1) {
                continue;
            }
            $state    = preg_match('/\bstate="([^"]*)"/', $tag, $ms) === 1 ? strtolower($ms[1]) : '';
            $name     = preg_match('/\bname="([^"]*)"/', $tag, $mn) === 1 ? $mn[1] : '';
            $target   = ltrim($mt[1], '/');
            $sheets[] = [
                'name'   => $name,
                'target' => str_starts_with($target, 'xl/') ? $target : 'xl/' . $target,
                'hidden' => $state === 'hidden' || $state === 'veryhidden',
            ];
        }

        return $sheets === [] ? $fallback : $sheets;
    }

    /**
     * Lit la table des chaînes partagées (sharedStrings.xml) : concatène le texte de chaque <si>
     * (runs de texte enrichi inclus). Absente = table vide (fichier sans chaîne partagée).
     *
     * @return list<string>
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $reader = new \XMLReader();
        $reader->XML($xmlContent);

        $strings = [];
        $current = '';
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($this->isTextNode($reader)) {
                $current .= $reader->value;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = $current;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * Reconstruit la grille d'une feuille : parcourt les cellules <c>, résout leur valeur (chaîne partagée
     * `t="s"`, chaîne inline `t="inlineStr"`, chaîne de formule `t="str"`, ou numérique), et indexe par
     * (ligne, colonne). Les cellules vides sont omises.
     *
     * @param list<string> $sharedStrings
     *
     * @return array<int, array<int, string>>
     */
    private function readSheetGrid(string $sheetXml, array $sharedStrings): array
    {
        $reader = new \XMLReader();
        $reader->XML($sheetXml);

        $grid    = [];
        $ref     = '';
        $type    = '';
        $value   = '';
        $capture = false;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                $ref     = (string) $reader->getAttribute('r');
                $type    = (string) $reader->getAttribute('t');
                $value   = '';
                $capture = false;
                if ($reader->isEmptyElement) {
                    continue;
                }
            } elseif ($reader->nodeType === \XMLReader::ELEMENT
                && ($reader->localName === 'v' || $reader->localName === 't')) {
                $capture = true;
            } elseif ($capture && $this->isTextNode($reader)) {
                $value .= $reader->value;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT
                && ($reader->localName === 'v' || $reader->localName === 't')) {
                $capture = false;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c') {
                $this->storeCell($grid, $ref, $type, $value, $sharedStrings);
            }
        }
        $reader->close();

        return $grid;
    }

    /**
     * Place la valeur résolue d'une cellule dans la grille (rien si vide ou référence invalide).
     *
     * @param array<int, array<int, string>> $grid
     * @param list<string>                   $sharedStrings
     */
    private function storeCell(array &$grid, string $ref, string $type, string $value, array $sharedStrings): void
    {
        if ($ref === '') {
            return;
        }
        if ($type === 's') {
            $value = ($value === '') ? '' : ($sharedStrings[(int) $value] ?? '');
        }
        if ($value === '') {
            return;
        }

        [$col, $row] = $this->parseRef($ref);
        if ($col === 0 || $row === 0) {
            return;
        }
        $grid[$row][$col] = $value;
    }

    /**
     * Convertit une référence A1 en [colonne, ligne] (1-based) ; [0, 0] si invalide.
     *
     * @return array{0: int, 1: int}
     */
    private function parseRef(string $ref): array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1) {
            return [0, 0];
        }
        $col     = 0;
        $letters = $m[1];
        for ($i = 0; $i < strlen($letters); $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }

        return [$col, (int) $m[2]];
    }

    /**
     * Un nœud XMLReader porteur de texte (texte, CDATA ou espace significatif).
     */
    private function isTextNode(\XMLReader $reader): bool
    {
        return $reader->nodeType === \XMLReader::TEXT
            || $reader->nodeType === \XMLReader::CDATA
            || $reader->nodeType === \XMLReader::SIGNIFICANT_WHITESPACE;
    }
}
