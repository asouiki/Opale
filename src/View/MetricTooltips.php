<?php

namespace App\View;

use App\Support\JsonFile;

/**
 * MetricTooltips
 *
 * Glossaire mutualisé des infobulles de métriques. Le contenu (libellé, définition,
 * origine Scrum/Lean, cas d'usage) est centralisé dans config_files/metric_tooltips.json
 * afin de n'être édité qu'à un seul endroit, puis réutilisé sur toutes les pages où une
 * même métrique apparaît.
 *
 * Maintenu à la main (pas d'IHM). Aucune dépendance : simple lecture de fichier + rendu HTML.
 */
class MetricTooltips
{
    /** Nom du fichier JSON source, dans config_files/. */
    private const FILE = 'metric_tooltips.json';

    /**
     * Cache statique du glossaire décodé : le JSON n'est lu qu'une seule fois par requête.
     *
     * @var array<string, array{label?:string, definition?:string, origin?:string, useCase?:string}>|null
     */
    private static ?array $cache = null;

    /**
     * Rend l'icône d'aide ⓘ et sa bulle d'infobulle pour une métrique donnée.
     *
     * Dégradation silencieuse : si la clé est inconnue, retourne une chaîne vide (la
     * métrique n'affiche alors pas d'icône), sans erreur ni log. Tout le texte issu du
     * JSON est échappé (htmlspecialchars) avant insertion dans le HTML.
     *
     * @param  string $key Clé de la métrique (ex: "predictability").
     * @return string HTML de l'infobulle, ou '' si la clé est absente du glossaire.
     */
    public static function render(string $key): string
    {
        $entry = self::load()[$key] ?? null;
        if ($entry === null) {
            return '';
        }

        $label = htmlspecialchars($entry['label'] ?? $key);

        // Corps de la bulle : chaque section n'est rendue que si elle est renseignée.
        $body = '';
        if (!empty($entry['definition'])) {
            $body .= '<span class="metric-help__def">' . htmlspecialchars($entry['definition']) . '</span>';
        }
        if (!empty($entry['origin'])) {
            $body .= '<span class="metric-help__origin">📐 ' . htmlspecialchars($entry['origin']) . '</span>';
        }
        if (!empty($entry['useCase'])) {
            $body .= '<span class="metric-help__use">💡 ' . htmlspecialchars($entry['useCase']) . '</span>';
        }

        // Icône d'aide : SVG inline (cercle + « i ») rendu via currentColor, net à
        // toute taille (le glyphe Unicode ⓘ apparaissait pixelisé selon la police).
        $icon = '<svg class="metric-help__icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/>'
            . '<circle cx="8" cy="4.6" r="0.95" fill="currentColor"/>'
            . '<line x1="8" y1="7" x2="8" y2="11.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '</svg>';

        return '<span class="metric-help" tabindex="0" role="note" aria-label="Aide : ' . $label . '">'
            . $icon
            . '<span class="metric-help__bubble">'
            . '<strong class="metric-help__title">' . $label . '</strong>'
            . $body
            . '</span>'
            . '</span>';
    }

    /**
     * Charge et décode le glossaire JSON (mémorisé en cache statique pour la requête).
     *
     * @return array<string, array> Dictionnaire clé → contenu d'infobulle (vide si absent/illisible).
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = __DIR__ . '/../../config_files/' . self::FILE;

        return self::$cache = JsonFile::read($path);
    }
}
