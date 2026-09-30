<?php

namespace App\Service;

/**
 * AbsenceMatcher
 *
 * Associe les noms lus dans un extract d'absences aux membres du roster OPALE, par correspondance
 * EXACTE sur le nom normalisé (trim, casse et accents ignorés : « Loic » ↔ « Loïc », « JEROME » ↔ « Jérôme »).
 * Choix assumé : pas de matching approximatif, pour éviter tout faux positif entre homonymes. Les noms du
 * fichier sans correspondance sont remontés tels quels afin que l'utilisateur les corrige.
 */
class AbsenceMatcher
{
    /**
     * @param  list<string>                       $fileNames Noms lus dans le fichier
     * @param  list<array{id: string, name: string}> $members Roster du projet
     * @return array{matched: array<string, string>, unmatched: list<string>} matched: nom fichier → id membre
     */
    public function match(array $fileNames, array $members): array
    {
        $rosterByNormalized = [];
        foreach ($members as $member) {
            $rosterByNormalized[$this->normalize($member['name'])] = $member['id'];
        }

        $matched   = [];
        $unmatched = [];
        foreach ($fileNames as $name) {
            $key = $this->normalize($name);
            if ($key !== '' && isset($rosterByNormalized[$key])) {
                $matched[$name] = $rosterByNormalized[$key];
            } else {
                $unmatched[] = $name;
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Normalise un nom pour comparaison : accents retirés, majuscule, espaces compactés.
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
}
