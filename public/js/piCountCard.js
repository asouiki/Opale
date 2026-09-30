/**
 * Composant Alpine générique des cartes compteur du « PI en cours » (onglet Sprints de la page
 * Métriques d'équipe) : « Releases & Versions » et « Features ».
 *
 * Charge paresseusement le count depuis l'endpoint de la ressource indiquée (cache-first côté
 * serveur : mémorisé par fenêtre, donc instantané aux affichages suivants), puis la carte pointe
 * vers l'onglet détaillé correspondant. Le rendu (icône, libellé, bouton) est porté par le template
 * appelant : seuls l'endpoint et l'onglet ciblé distinguent les deux cartes.
 *
 * @param {{projectKey:string, from:string, to:string, resource:string}} config
 *        resource : segment d'URL de l'API (« pi-releases » | « pi-features »).
 */
window.piCountCard = function (config) {
    return {
        projectKey: config.projectKey,
        from: config.from,
        to: config.to,
        // Segment d'URL de l'API : seule différence entre les deux cartes.
        resource: config.resource,
        loading: false,
        error: false,
        count: 0,
        // Passe à true une fois le count obtenu : rend load() idempotent, l'onglet Sprints pouvant
        // être réaffiché plusieurs fois. Reste false en cas d'échec, pour autoriser une nouvelle
        // tentative au prochain affichage.
        loaded: false,

        // Récupère le count (endpoint cache-first) au premier affichage de l'onglet Sprints.
        load() {
            if (this.loaded || this.loading) {
                return;
            }
            this.loading = true;
            this.error = false;
            const url = '/api/internal/projects/' + encodeURIComponent(this.projectKey)
                + '/' + this.resource + '/count?from=' + encodeURIComponent(this.from)
                + '&to=' + encodeURIComponent(this.to);
            fetch(url)
                .then((r) => r.json().then((b) => {
                    if (!r.ok || b.error) throw new Error(b.error || ('HTTP ' + r.status));
                    return b;
                }))
                .then((b) => { this.count = b.count ?? 0; this.loaded = true; })
                .catch(() => { this.error = true; })
                .finally(() => { this.loading = false; });
        }
    };
};
