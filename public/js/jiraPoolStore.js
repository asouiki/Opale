/**
 * Stockage localStorage partagé pour la persistance des sélections de projet
 * Jira entre les pages d'un même « pool ».
 *
 *   - pool « team »  (clé jira_pool_team)  : Release, Sprint, Planification
 *   - pool « train » (clé jira_pool_train) : Feature, PI
 *
 * Chaque page conserve aussi sa propre clé locale (sélection spécifique à la
 * page). Toute entrée stockée porte un champ `updatedAt` (timestamp ms) : au
 * chargement d'une page, on retient la sélection LA PLUS RÉCENTE entre la clé
 * locale de la page et la clé partagée du pool (cf. mostRecent()).
 *
 * Helper volontairement minimal (KISS) : pas de dépendance, exposé en global
 * `window.jiraStore`, consommé par les composants Alpine de sélection.
 */
(function () {
    'use strict';

    window.jiraStore = {
        /**
         * Lit une entrée localStorage et la désérialise.
         * En cas de JSON corrompu, purge la clé et renvoie null.
         *
         * @param {string} key Clé localStorage.
         * @returns {Object|null} L'objet stocké, ou null si absent/corrompu.
         */
        read(key) {
            try {
                return JSON.parse(localStorage.getItem(key) || 'null');
            } catch (e) {
                localStorage.removeItem(key);
                return null;
            }
        },

        /**
         * Sérialise et écrit une entrée, en y ajoutant l'horodatage courant
         * (`updatedAt`). C'est cet horodatage qui permet d'arbitrer, plus tard,
         * entre sélection locale et sélection partagée.
         *
         * @param {string} key  Clé localStorage.
         * @param {Object} data Données à persister (sans `updatedAt`).
         * @returns {void}
         */
        write(key, data) {
            try {
                localStorage.setItem(key, JSON.stringify(Object.assign({}, data, { updatedAt: Date.now() })));
            } catch (e) {
                console.warn('localStorage indisponible', e);
            }
        },

        /**
         * Supprime une entrée.
         *
         * @param {string} key Clé localStorage.
         * @returns {void}
         */
        remove(key) {
            localStorage.removeItem(key);
        },

        /**
         * Retourne, entre deux entrées, la plus récente selon `updatedAt`.
         * Tolère les valeurs nulles. En cas d'égalité (ou d'absence
         * d'horodatage), privilégie la sélection locale de la page.
         *
         * @param {Object|null} local Entrée locale à la page.
         * @param {Object|null} pool  Entrée partagée du pool.
         * @returns {Object|null} L'entrée la plus récente, ou null si les deux sont nulles.
         */
        mostRecent(local, pool) {
            if (!local) return pool;
            if (!pool) return local;
            return (pool.updatedAt || 0) > (local.updatedAt || 0) ? pool : local;
        },
    };
})();
