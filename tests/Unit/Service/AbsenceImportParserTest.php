<?php

namespace Tests\Unit\Service;

use App\Service\AbsenceImportParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests du parseur d'extract d'absences (.xlsx).
 *
 * Deux niveaux : l'interprétation métier de la grille (interpret(), sans fichier — rollover d'année,
 * demi-journées, colonnes vides, détection de structure) et un test d'intégration sur le vrai fichier
 * d'exemple (fixture) pour valider le parsing XML/zip réel (bandeaux fusionnés, chaînes partagées).
 */
class AbsenceImportParserTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/absences_exemple.xlsx';

    private function parser(): AbsenceImportParser
    {
        return new AbsenceImportParser();
    }

    /**
     * Grille minimale : ligne des mois, ligne d'en-tête (« Nom Prénom » + jours), une personne.
     *
     * @param array<int, string> $monthCells  colonne → libellé de mois
     * @param array<int, string> $headerCells colonne → numéro de jour
     * @param array<int, string> $personCells colonne → code cellule (col 1 = nom)
     *
     * @return array<int, array<int, string>>
     */
    private function grid(array $monthCells, array $headerCells, array $personCells): array
    {
        return [1 => $monthCells, 2 => [1 => 'Nom Prénom'] + $headerCells, 3 => $personCells];
    }

    #[Test]
    public function test_interpret_mappeMoisEtJourEnDateIso(): void
    {
        $grid = $this->grid(
            [2 => 'JUILLET'],
            [2 => '1', 3 => '2'],
            [1 => 'Alice', 2 => 'A', 3 => ''],
        );

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame(['Alice'], $result->names);
        $this->assertSame(
            [['date' => '2026-07-01', 'value' => 0.0, 'forecast' => false]],
            $result->absencesByName['Alice']
        );
        $this->assertSame(['2026-07-01', '2026-07-02'], $result->coveredDates);
    }

    #[Test]
    public function test_interpret_encartLegende_ignoreLegendeEtLignesSuivantes(): void
    {
        // Ligne vide entre les personnes et la légende, puis codes de légende (qui porteraient sinon des « absences »).
        $grid = $this->grid(
            [2 => 'JUILLET'],
            [2 => '1'],
            [1 => 'Alice', 2 => 'A'],
        ) + [
            5 => [1 => 'Légende :'],
            6 => [1 => 'Absence 1 j', 2 => 'A'],
            7 => [1 => 'Formation', 2 => 'F'],
        ];

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame(['Alice'], $result->names);
        $this->assertSame(['Alice'], array_keys($result->absencesByName));
    }

    #[Test]
    public function test_interpret_personneSansAbsenceListeeDansNamesMaisPasDansAbsences(): void
    {
        // Bob n'a aucune cellule non vide : il apparaît dans names (pour la resynchro) mais pas dans absencesByName.
        $grid = $this->grid(
            [2 => 'JUILLET'],
            [2 => '1'],
            [1 => 'Bob'],
        );

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame(['Bob'], $result->names);
        $this->assertArrayNotHasKey('Bob', $result->absencesByName);
    }

    #[Test]
    public function test_interpret_demiJourneePour1sur2A(): void
    {
        $grid = $this->grid(
            [2 => 'JUILLET'],
            [2 => '1', 3 => '2', 4 => '3', 5 => '4', 6 => '5'],
            [1 => 'Bob', 2 => '1/2A', 3 => 'TP', 4 => '0.5', 5 => '0,5', 6 => '1/2'],
        );

        $result = $this->parser()->interpret($grid, 2026);

        // Toutes les notations de demi-journée valent 0.5 ; « TP » (non vide) vaut 0.
        $this->assertSame([
            ['date' => '2026-07-01', 'value' => 0.5, 'forecast' => false],
            ['date' => '2026-07-02', 'value' => 0.0, 'forecast' => false],
            ['date' => '2026-07-03', 'value' => 0.5, 'forecast' => false],
            ['date' => '2026-07-04', 'value' => 0.5, 'forecast' => false],
            ['date' => '2026-07-05', 'value' => 0.5, 'forecast' => false],
        ], $result->absencesByName['Bob']);
    }

    #[Test]
    public function test_interpret_codeP_marqueUneAbsencePrevisionnelleComptee0(): void
    {
        // « P » (prévisionnel) vaut 0 comme une absence classique, mais est marqué pour l'affichage « prév. ».
        // « TP » / « 1/2TP » contiennent un P sans être prévisionnels : seule l'égalité stricte compte.
        $grid = $this->grid(
            [2 => 'JUILLET'],
            [2 => '1', 3 => '2', 4 => '3', 5 => '4'],
            [1 => 'Eve', 2 => 'P', 3 => ' p ', 4 => 'TP', 5 => '1/2TP'],
        );

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame([
            ['date' => '2026-07-01', 'value' => 0.0, 'forecast' => true],
            ['date' => '2026-07-02', 'value' => 0.0, 'forecast' => true],
            ['date' => '2026-07-03', 'value' => 0.0, 'forecast' => false],
            ['date' => '2026-07-04', 'value' => 0.5, 'forecast' => false],
        ], $result->absencesByName['Eve']);
    }

    #[Test]
    public function test_interpret_rolloverAnneeDecembreVersJanvier(): void
    {
        // DECEMBRE (col 2) reporté jusqu'à JANVIER (col 5) ; le mois décroissant incrémente l'année.
        $grid = $this->grid(
            [2 => 'DECEMBRE', 5 => 'JANVIER'],
            [2 => '30', 3 => '31', 5 => '1', 6 => '2'],
            [1 => 'Carl', 2 => 'A', 5 => 'A'],
        );

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame([
            ['date' => '2026-12-30', 'value' => 0.0, 'forecast' => false],
            ['date' => '2027-01-01', 'value' => 0.0, 'forecast' => false],
        ], $result->absencesByName['Carl']);
        $this->assertSame(['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'], $result->coveredDates);
    }

    #[Test]
    public function test_interpret_anneeAnnoteePrimeSurAnneeCourante(): void
    {
        $grid = $this->grid(
            [2 => 'JUILLET 2030'],
            [2 => '1'],
            [1 => 'Dan', 2 => 'A'],
        );

        $result = $this->parser()->interpret($grid, 2026);

        $this->assertSame(
            [['date' => '2030-07-01', 'value' => 0.0, 'forecast' => false]],
            $result->absencesByName['Dan']
        );
    }

    #[Test]
    public function test_interpret_ligneEnteteIntrouvable_leveException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->parser()->interpret([1 => [1 => 'Autre chose', 2 => 'JUILLET']], 2026);
    }

    #[Test]
    public function test_parse_fichierReel_extraitAbsencesEtPlageCouverte(): void
    {
        $result = $this->parser()->parse(self::FIXTURE, 2026);

        // 11 personnes, plage juillet → décembre (week-ends inclus dans la couverture).
        $this->assertCount(11, $result->names);
        $this->assertContains('Personne 1', $result->names);
        $this->assertCount(11, $result->absencesByName);
        $this->assertSame('2026-07-01', $result->coveredDates[0]);
        $this->assertSame('2026-12-31', $result->coveredDates[array_key_last($result->coveredDates)]);
        $this->assertContains('2026-07-04', $result->coveredDates); // samedi conservé dans la couverture

        // Personne 1 : 1ʳᵉ absence lundi 13/07 (colonne « A »), journée entière.
        $this->assertContains(
            ['date' => '2026-07-13', 'value' => 0.0, 'forecast' => false],
            $result->absencesByName['Personne 1']
        );

        // Personne 11 : la seule demi-journée (« 1/2A ») du fichier.
        $this->assertContains(
            ['date' => '2026-09-28', 'value' => 0.5, 'forecast' => false],
            $result->absencesByName['Personne 11']
        );
    }

    #[Test]
    public function test_parse_ignoreOngletMasque_maisLitLignesMasquees_etRemonteLesOnglets(): void
    {
        // Onglet masqué (vieux semestre) : ignoré. Onglet visible : lu, y compris ses LIGNES masquées (filtre Excel).
        $hidden = [
            'name'   => 'Old 2024',
            'hidden' => true,
            'data'   => '<row r="1">' . $this->textCell('B1', 'JUILLET') . '</row>'
                . '<row r="2">' . $this->textCell('A2', 'Nom Prénom') . $this->numCell('B2', 1) . '</row>'
                . '<row r="3">' . $this->textCell('A3', 'GhostFromHidden') . $this->textCell('B3', 'A') . '</row>',
        ];
        $visible = [
            'name'   => 'Team 2026',
            'hidden' => false,
            'data'   => '<row r="1">' . $this->textCell('B1', 'JUILLET') . '</row>'
                . '<row r="2">' . $this->textCell('A2', 'Nom Prénom') . $this->numCell('B2', 1) . '</row>'
                . '<row r="3">' . $this->textCell('A3', 'VisibleGuy') . $this->textCell('B3', 'A') . '</row>'
                . '<row r="4" hidden="1">' . $this->textCell('A4', 'FilteredGuy') . $this->textCell('B4', 'A') . '</row>',
        ];

        // currentYear 2099 volontairement faux : l'année doit venir du titre d'onglet « Team 2026 ».
        $result = $this->parser()->parse($this->makeXlsx([$hidden, $visible]), 2099);

        $this->assertSame(['VisibleGuy', 'FilteredGuy'], $result->names); // ligne masquée conservée
        $this->assertArrayHasKey('FilteredGuy', $result->absencesByName);
        $this->assertSame('2026-07-01', $result->coveredDates[0]);        // année du titre d'onglet
        $this->assertSame(['Team 2026'], $result->sheetsRead);
        $this->assertSame(['Old 2024'], $result->sheetsHidden);
    }

    #[Test]
    public function test_parse_anneeDuNomDeFichierPrioritaireSurOngletEtDefaut(): void
    {
        $sheet = [
            'name'   => 'Feuille 2026',
            'hidden' => false,
            'data'   => '<row r="1">' . $this->textCell('B1', 'JUILLET') . '</row>'
                . '<row r="2">' . $this->textCell('A2', 'Nom Prénom') . $this->numCell('B2', 1) . '</row>'
                . '<row r="3">' . $this->textCell('A3', 'Bob') . $this->textCell('B3', 'A') . '</row>',
        ];

        // Nom de fichier « …2030 » : prime sur le titre d'onglet (2026) et sur l'année courante (2020).
        $result = $this->parser()->parse($this->makeXlsx([$sheet]), 2020, 'export absences 2030.xlsx');

        $this->assertSame(
            [['date' => '2030-07-01', 'value' => 0.0, 'forecast' => false]],
            $result->absencesByName['Bob']
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @var list<string> Fichiers .xlsx temporaires générés par les tests, nettoyés en tearDown. */
    private array $tempFiles = [];

    /** Cellule texte (chaîne inline). */
    private function textCell(string $ref, string $value): string
    {
        return '<c r="' . $ref . '" t="inlineStr"><is><t>'
            . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . '</t></is></c>';
    }

    /** Cellule numérique (numéro de jour). */
    private function numCell(string $ref, int $value): string
    {
        return '<c r="' . $ref . '"><v>' . $value . '</v></c>';
    }

    /**
     * Construit un .xlsx minimal en mémoire (feuilles à chaînes inline) et renvoie son chemin temporaire.
     *
     * @param non-empty-list<array{name: string, hidden: bool, data: string}> $sheets  data = contenu de <sheetData>
     */
    private function makeXlsx(array $sheets): string
    {
        $ns       = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $types    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $wbSheets = '';
        $wbRels   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $files    = [];
        foreach ($sheets as $i => $s) {
            $n     = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $state    = $s['hidden'] ? ' state="hidden"' : '';
            $wbSheets .= '<sheet name="' . htmlspecialchars($s['name'], ENT_QUOTES | ENT_XML1)
                . '" sheetId="' . $n . '"' . $state . ' r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $n . '.xml"/>';
            $files['xl/worksheets/sheet' . $n . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="' . $ns . '"><sheetData>' . $s['data'] . '</sheetData></worksheet>';
        }
        $files['[Content_Types].xml']         = $types . '</Types>';
        $files['xl/_rels/workbook.xml.rels']  = $wbRels . '</Relationships>';
        $files['_rels/.rels']                 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
            . 'Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml']             = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . $ns . '" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>';

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest_');
        if ($path === false) {
            self::fail('tempnam a échoué');
        }
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            self::fail('Création du .xlsx de test impossible');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
