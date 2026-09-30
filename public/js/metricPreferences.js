/**
 * metricPreferences.js
 *
 * Gestion des « métriques préférées » des pages Sprint et Métriques d'équipe.
 *
 * Objectif : réduire la surcharge d'informations. L'utilisateur choisit, depuis la page
 * Configuration, les métriques/sections affichées par défaut.
 * Les autres sont masquées en « Vue simplifiée » et révélées en « Vue complète », via le toggle
 * placé en haut de page (composant views/common/metricViewToggle.phtml).
 *
 * Sélection ET mode de vue sont personnels (par navigateur) : tous deux sont persistés dans le
 * localStorage, jamais côté serveur. Le mode survit donc au rechargement et à une nouvelle analyse.
 *
 * Modèle DOM attendu sur les pages :
 *   - data-metric-toggle="<id>"   → élément masquable (carte .metric ou section .card). Plusieurs
 *                                   éléments peuvent porter le MÊME id pour être liés : ils s'affichent
 *                                   et se masquent ensemble, sans entrée supplémentaire au catalogue
 *                                   (ex. la carte « Temps de mise en attente » et son détail
 *                                   « Causes d'attente », qui n'a pas de sens sans elle).
 *   - data-metric-collapse        → conteneur masqué automatiquement si aucun de ses
 *                                   descendants data-metric-toggle n'est visible.
 *   - [data-metric-view-page="<page>"] → conteneur du toggle, dont les boutons portent
 *                                   data-metric-view="simple|full" (câblés par apply()).
 */
(function () {
    'use strict';

    /** Préfixe des clés localStorage de la SÉLECTION de métriques (une clé par page). */
    const STORAGE_PREFIX = 'opale.metricPrefs.';

    /** Préfixe des clés localStorage du MODE DE VUE (une clé par page). */
    const VIEW_STORAGE_PREFIX = 'opale.metricView.';

    /** Modes de vue possibles. « simple » applique la sélection, « full » affiche tout. */
    const VIEW_SIMPLE = 'simple';
    const VIEW_FULL   = 'full';

    /** Classe CSS appliquée aux éléments masqués. */
    const HIDDEN_CLASS = 'metric-hidden';

    /**
     * Renommages d'identifiants de métriques (ancien id → nouvel id). Appliqués à
     * la lecture des préférences pour migrer en douceur les sélections déjà
     * persistées, sans que l'utilisateur ait à reconfigurer.
     */
    const RENAMED_IDS = {
        'sprint.manDays': 'sprint.capacity',
        'team.avgDoneTickets': 'team.sprintLanding',
        'team.avgDoneStoryPoints': 'team.sprintLanding',
    };

    /**
     * Catalogue des éléments masquables par page.
     * Chaque entrée : { id, label, group, defaultHide? }. L'`id` doit correspondre à un attribut
     * data-metric-toggle présent dans le template de la page.
     * Le `group` ne sert qu'à structurer l'affichage dans la page Configuration.
     * `defaultHide: true` retire la métrique de la sélection par défaut d'un primo-accédant (vue
     * simplifiée) : elle reste décochée tant qu'aucune préférence n'a été enregistrée.
     */
    const CATALOG = {
        sprint: [
            { id: 'sprint.totalStoryPoints', label: '✂️ Total Story Points', group: 'Cartes — Statistiques' },
            { id: 'sprint.unestimated', label: '🚫 Tickets non estimés', group: 'Cartes — Statistiques' },
            { id: 'sprint.spRange', label: '📊 Distribution des Story Points (fourchette, médiane, moyenne)', group: 'Cartes — Statistiques', defaultHide: true },
            { id: 'sprint.capacity', label: '👥 Capacité (jours/homme + points)', group: 'Cartes — Statistiques' },
            { id: 'sprint.velocity', label: '⚡ Vélocité (SP / jours-homme)', group: 'Cartes — Statistiques' },
            { id: 'sprint.ticketAge', label: '👤 Âge des tickets', group: 'Cartes — Statistiques', defaultHide: true },
            { id: 'sprint.waitingTime', label: '🛑 Temps de mise en attente', group: 'Cartes — Statistiques' },
            { id: 'sprint.scopeChange', label: '📈 Évolution du périmètre', group: 'Cartes — Statistiques' },
            { id: 'sprint.predictability', label: '📈 Prédictibilité du sprint', group: 'Sections & tableaux' },
            { id: 'sprint.landing', label: '🛬 Atterrissage du sprint', group: 'Sections & tableaux' },
            { id: 'sprint.objectives', label: '🎯 État des tickets « objectif »', group: 'Sections & tableaux' },
            { id: 'sprint.newPrio', label: '🚨 Tickets prioritaires ajoutés (nouvelle_prio)', group: 'Sections & tableaux', defaultHide: true },
            { id: 'sprint.added', label: '➕ Tickets ajoutés en cours de sprint', group: 'Sections & tableaux' },
            { id: 'sprint.removed', label: '➖ Tickets retirés en cours de sprint', group: 'Sections & tableaux' },
            { id: 'sprint.continuousImprovement', label: '💡 Amélioration continue et Innovation', group: 'Sections & tableaux' },
            { id: 'sprint.features', label: '🧩 Features traitées', group: 'Sections & tableaux' },
            { id: 'sprint.doneTickets', label: '✅ Tickets terminés', group: 'Sections & tableaux' },
            { id: 'sprint.reliquat', label: '📦 Tickets non terminés', group: 'Sections & tableaux' },
        ],
        team_metrics: [
            { id: 'team.sprintLanding', label: '🛬 Atterrissage moyen / sprint (tickets, SP, delta capa)', group: 'Cartes — Moyennes' },
            { id: 'team.velocity', label: '⚡ Vélocité (points terminés / jour)', group: 'Cartes — Moyennes' },
            { id: 'team.avgBusinessDays', label: '📆 Durée moyenne', group: 'Cartes — Moyennes', defaultHide: true },
            { id: 'team.avgPredictability', label: '🎯 Prédictibilité moyenne', group: 'Cartes — Moyennes' },
            { id: 'team.avgEngagement', label: '🤝 Engagement moyen', group: 'Cartes — Moyennes' },
            { id: 'team.avgAddedTickets', label: '➕ Tickets ajoutés / sprint', group: 'Cartes — Moyennes' },
            { id: 'team.avgScopeGrowth', label: '⛑️ Changement de périmètre moyen', group: 'Cartes — Moyennes' },
            { id: 'team.avgReliquat', label: '📦 Reliquat moyen / sprint', group: 'Cartes — Moyennes' },
            { id: 'team.cycleTime', label: '⏱️ Cycle time', group: 'Cartes — Moyennes', defaultHide: true },
            { id: 'team.leadTime', label: '🏗️ Lead time', group: 'Cartes — Moyennes', defaultHide: true },
            { id: 'team.waitingTime', label: '⌛ Waiting time', group: 'Cartes — Moyennes', defaultHide: true },
            { id: 'team.predictabilityChart', label: '📈 Évolution de la prédictibilité', group: 'Graphiques & tableaux' },
            { id: 'team.engagementChart', label: '🤝 Évolution de l\'engagement', group: 'Graphiques & tableaux' },
            { id: 'team.velocityChart', label: '📈 Évolution de la vélocité', group: 'Graphiques & tableaux' },
            { id: 'team.sprintDetail', label: '🗂️ Détail par sprint', group: 'Graphiques & tableaux' },
            { id: 'team.cycleTimeBySp', label: '🎯 Cycle time par estimation', group: 'Graphiques & tableaux', defaultHide: true },
        ],
    };

    /**
     * Sélection par défaut d'une page pour un primo-accédant (aucune préférence enregistrée) :
     * toutes les métriques sauf celles marquées `defaultHide` (vue simplifiée).
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @returns {string[]} Liste des id affichés par défaut.
     */
    function getDefaultPrefs(page) {
        return CATALOG[page]
            .filter(function (item) { return !item.defaultHide; })
            .map(function (item) { return item.id; });
    }

    /**
     * Lit la sélection persistée d'une page.
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @returns {string[]|null} Liste des id préférés, ou null si jamais configurée.
     */
    function getPrefs(page) {
        const raw = window.localStorage.getItem(STORAGE_PREFIX + page);
        if (raw === null) {
            return null;
        }
        try {
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return null;
            }
            return migrateIds(page, parsed);
        } catch (e) {
            return null;
        }
    }

    /**
     * Applique RENAMED_IDS à une liste d'id préférés. Si au moins un id a été
     * renommé, la sélection migrée est réécrite (migration unique et silencieuse).
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @param {string[]} ids Liste des id préférés lue du localStorage.
     * @returns {string[]} Liste avec les id renommés (dédoublonnée).
     */
    function migrateIds(page, ids) {
        let changed = false;
        const migrated = [];
        ids.forEach(function (id) {
            const next = RENAMED_IDS[id] || id;
            if (next !== id) {
                changed = true;
            }
            if (!migrated.includes(next)) {
                migrated.push(next);
            }
        });
        if (changed) {
            setPrefs(page, migrated);
        }
        return migrated;
    }

    /**
     * Persiste la sélection d'une page.
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @param {string[]} ids Liste des id préférés (affichés par défaut).
     * @returns {void}
     */
    function setPrefs(page, ids) {
        window.localStorage.setItem(STORAGE_PREFIX + page, JSON.stringify(ids));
    }

    /**
     * Masque les éléments non préférés, replie les conteneurs vidés et masque les
     * éléments encore visibles selon la sélection courante.
     *
     * @param {Set<string>} preferred Ensemble des id à afficher.
     * @returns {number} Nombre d'éléments masqués (cartes/sections).
     */
    function hideNonPreferred(preferred) {
        let hiddenCount = 0;

        // Masque chaque élément masquable absent de la sélection.
        document.querySelectorAll('[data-metric-toggle]').forEach(function (el) {
            if (!preferred.has(el.dataset.metricToggle)) {
                el.classList.add(HIDDEN_CLASS);
                hiddenCount++;
            } else {
                el.classList.remove(HIDDEN_CLASS);
            }
        });

        // Replie tout conteneur dont plus aucun descendant masquable n'est visible
        // (ex. groupe de cartes vidé, carte « Statistiques » sans contenu visible).
        document.querySelectorAll('[data-metric-collapse]').forEach(function (group) {
            const toggles = group.querySelectorAll('[data-metric-toggle]');
            const anyVisible = Array.prototype.some.call(toggles, function (el) {
                return !el.classList.contains(HIDDEN_CLASS);
            });
            group.classList.toggle(HIDDEN_CLASS, !anyVisible);
        });

        return hiddenCount;
    }

    /**
     * Révèle tous les éléments masqués (mode « Vue complète »).
     *
     * @returns {void}
     */
    function revealAll() {
        document.querySelectorAll('.' + HIDDEN_CLASS).forEach(function (el) {
            el.classList.remove(HIDDEN_CLASS);
        });
    }

    /**
     * Lit le mode de vue persisté d'une page. Toute valeur inconnue (ou absente) retombe sur
     * « simple », qui est le mode par défaut du primo-accédant.
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @returns {string} VIEW_SIMPLE ou VIEW_FULL.
     */
    function getViewMode(page) {
        return window.localStorage.getItem(VIEW_STORAGE_PREFIX + page) === VIEW_FULL
            ? VIEW_FULL
            : VIEW_SIMPLE;
    }

    /**
     * Persiste le mode de vue d'une page.
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @param {string} mode VIEW_SIMPLE ou VIEW_FULL.
     * @returns {void}
     */
    function setViewMode(page, mode) {
        window.localStorage.setItem(VIEW_STORAGE_PREFIX + page, mode === VIEW_FULL ? VIEW_FULL : VIEW_SIMPLE);
    }

    /**
     * Câble le toggle « Vue simplifiée / Vue complète » de la page : marque le bouton actif et
     * branche le changement de mode (persistance + réapplication immédiate).
     *
     * Le toggle reste toujours affiché : c'est un réglage persistant de la page, au même titre qu'une
     * préférence. Le masquer quand la sélection ne cache rien le ferait disparaître tant qu'aucune analyse
     * n'est affichée (les éléments masquables n'existent que dans le bloc de résultats), ce qui le rendrait
     * imprévisible.
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @param {string} mode Mode de vue courant.
     * @returns {void}
     */
    function syncViewToggle(page, mode) {
        const container = document.querySelector('[data-metric-view-page="' + page + '"]');
        if (!container) {
            return;
        }

        container.querySelectorAll('[data-metric-view]').forEach(function (button) {
            const buttonMode = button.dataset.metricView;
            button.classList.toggle('metric-view-toggle__btn--active', buttonMode === mode);
            button.setAttribute('aria-pressed', buttonMode === mode ? 'true' : 'false');
            // Un seul écouteur par bouton : apply() est rappelé à chaque bascule.
            if (button.dataset.metricViewBound !== '1') {
                button.dataset.metricViewBound = '1';
                button.addEventListener('click', function () {
                    setViewMode(page, buttonMode);
                    apply(page);
                });
            }
        });
    }

    /**
     * Applique le mode de vue courant à la page, puis met le toggle à jour.
     * En « Vue complète » rien n'est masqué ; en « Vue simplifiée » on applique la sélection
     * enregistrée, ou à défaut celle du primo-accédant (tout sauf les métriques `defaultHide`).
     *
     * @param {string} page Identifiant de page ('sprint' | 'team_metrics').
     * @returns {void}
     */
    function apply(page) {
        const prefs = getPrefs(page);
        const preferred = new Set(prefs === null ? getDefaultPrefs(page) : prefs);
        const mode = getViewMode(page);

        if (mode === VIEW_FULL) {
            revealAll();
        } else {
            hideNonPreferred(preferred);
        }

        syncViewToggle(page, mode);
    }

    // API publique consommée par les pages (Sprint/Métriques) et la page Configuration.
    window.MetricPrefs = {
        catalog: CATALOG,
        getPrefs: getPrefs,
        getDefaultPrefs: getDefaultPrefs,
        setPrefs: setPrefs,
        getViewMode: getViewMode,
        setViewMode: setViewMode,
        apply: apply,
    };
})();
