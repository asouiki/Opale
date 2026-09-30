<?php

namespace App\Service;

/**
 * ParsedImport
 *
 * Résultat immuable du parsing d'un fichier d'absences (\App\Service\AbsenceImportParser).
 *
 * - `names` : tous les noms lus dans le fichier (une ligne = une personne), y compris celles sans aucune
 *   absence. Sert à l'association au roster et à distinguer « absente du fichier » de « présente sans absence ».
 * - `absencesByName` : pour chaque nom ayant ≥ 1 absence, la liste de ses cellules d'absence (date ISO
 *   `Y-m-d` + valeur de présence associée : `0.0` journée entière, `0.5` demi-journée) ; `forecast` signale
 *   une absence *prévisionnelle* (code `P`), comptée 0 mais affichée « prév. » dans la grille de capacité.
 * - `coveredDates` : toutes les dates ISO présentes en colonnes du fichier (jours d'absence ou non,
 *   week-ends inclus). Sert à borner l'autorité de l'import : hors de cette plage, la saisie existante
 *   n'est pas touchée (cas d'un sprint à cheval sur la fin du fichier).
 * - `sheetsRead` : noms des onglets **visibles** effectivement lus ; `sheetsHidden` : noms des onglets
 *   **masqués** ignorés (remontés au récap d'import pour transparence).
 */
final class ParsedImport
{
    /**
     * @param list<string>                                                              $names
     * @param array<string, list<array{date: string, value: float, forecast: bool}>> $absencesByName
     * @param list<string>                                                              $coveredDates
     * @param list<string>                                                              $sheetsRead
     * @param list<string>                                                              $sheetsHidden
     */
    public function __construct(
        public readonly array $names,
        public readonly array $absencesByName,
        public readonly array $coveredDates,
        public readonly array $sheetsRead = [],
        public readonly array $sheetsHidden = [],
    ) {
    }
}
