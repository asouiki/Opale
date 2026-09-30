/**
 * issueTypePie.js
 *
 * Camembert de répartition des tickets d'une Feature par type (Story, Bug, Spike…), avec bascule
 * entre deux métriques : nombre de tickets ou Story Points — même principe que le camembert
 * d'atterrissage de la page Sprint.
 *
 * Code couleur métier, stable d'une Feature à l'autre : bugs (toutes natures) en rouge, enablers en
 * orange, spikes en noir, stories en vert, tâches en bleu ; les autres types prennent une couleur neutre
 * (cf. TYPE_COLORS et FALLBACK_COLORS).
 *
 * Volontairement sans le plugin chartjs-plugin-datalabels (utilisé par /sprint) : chargé par balise
 * script, ce plugin s'enregistre GLOBALEMENT et étiquetterait aussi les autres graphiques de la page
 * Métriques d'équipe. La légende native de Chart.js remplit le même rôle sans effet de bord.
 *
 * @param {HTMLCanvasElement} canvas  Canvas hôte du graphique.
 * @param {HTMLElement|null} filters  Conteneur des boutons `button[data-metric]` ('count' | 'storyPoints').
 * @param {Array<{type: string, count: number, storyPoints: number}>} rows Répartition, déjà triée.
 * @returns {Chart} L'instance Chart.js créée (à détruire par l'appelant au rechargement des données).
 */
window.issueTypePie = function (canvas, filters, rows) {
    'use strict';

    /**
     * Couleur imposée pour les natures de ticket porteuses de sens métier, reconnues par MOT-CLEF dans
     * le nom du type (insensible à la casse) : le nommage Jira varie d'un Train à l'autre (« Bug »,
     * « Bug Prod », « Sous-tâche Bug », « Enabler Story », « Feature Enabler »…).
     *
     * ⚠️ L'ORDRE COMPTE : « Enabler Story » contient aussi « story » et doit rester orange, donc
     * « enabler » est testé avant « story ».
     */
    const TYPE_COLORS = [
        { keywords: ['bug'], color: '#ef4444' },                        // rouge — bugs, toutes natures confondues
        { keywords: ['enabler'], color: '#f59e0b' },                    // orange
        { keywords: ['spike'], color: '#1f2937' },                      // noir
        { keywords: ['story'], color: '#0e9f6e' },                      // vert
        { keywords: ['task', 'tâche', 'tache'], color: '#2563eb' },     // bleu — Task, Sub-task, Sous-tâche
    ];

    /**
     * Couleurs des types non conventionnés (Risque, Test, Innovation…), attribuées dans l'ordre
     * d'apparition. Volontairement disjointes de TYPE_COLORS — ni bleu, ni vert, ni rouge, ni orange,
     * ni noir : un type banal ne doit jamais se faire passer pour une tâche, une story ou un bug.
     */
    const FALLBACK_COLORS = ['#8b5cf6', '#ec4899', '#92400e', '#78716c', '#d946ef', '#6b21a8'];

    // Couleur d'un type : conventionnée si un mot-clef correspond, sinon la suivante de la liste de repli.
    let fallbackIndex = 0;
    const colorFor = function (type) {
        const name = String(type).toLowerCase();
        const match = TYPE_COLORS.find(function (entry) {
            return entry.keywords.some(function (keyword) { return name.indexOf(keyword) !== -1; });
        });
        if (match) {
            return match.color;
        }

        return FALLBACK_COLORS[fallbackIndex++ % FALLBACK_COLORS.length];
    };

    /** Libellé + suffixe d'unité par métrique (titre et infobulle). */
    const METRICS = {
        count: { label: 'Nb de tickets', unit: ' tickets' },
        storyPoints: { label: 'Story Points', unit: ' SP' },
    };

    let metric = 'count';

    // Arrondit à 1 décimale et supprime un « .0 » superflu.
    const fmt = function (v) {
        const r = Math.round(v * 10) / 10;
        return Number.isInteger(r) ? String(r) : r.toFixed(1);
    };

    const chart = new Chart(canvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: rows.map(function (r) { return r.type; }),
            datasets: [{
                data: rows.map(function (r) { return r[metric]; }),
                backgroundColor: rows.map(function (r) { return colorFor(r.type); }),
                borderColor: '#fff',
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                title: { display: true, text: 'Répartition par ' + METRICS[metric].label },
                tooltip: {
                    callbacks: {
                        label: function (c) {
                            const total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            const pct = total > 0 ? Math.round((c.parsed / total) * 1000) / 10 : 0;
                            return c.label + ' : ' + fmt(c.parsed) + METRICS[metric].unit + ' (' + pct + ' %)';
                        },
                    },
                },
            },
        },
    });

    // Switch de métrique : seules les valeurs et le titre changent, les parts gardent leur couleur.
    if (filters) {
        filters.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-metric]');
            if (!btn) {
                return;
            }
            metric = btn.dataset.metric;

            this.querySelectorAll('.team-filter').forEach(function (b) {
                b.classList.toggle('team-filter--active', b === btn);
            });

            chart.data.datasets[0].data = rows.map(function (r) { return r[metric]; });
            chart.options.plugins.title.text = 'Répartition par ' + METRICS[metric].label;
            chart.update();
        });
    }

    return chart;
};
