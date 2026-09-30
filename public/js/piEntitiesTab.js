/**
 * Composant Alpine générique des onglets « Releases » et « Features » de la page Métriques d'équipe.
 *
 * Charge (paresseusement) les entités du PI en cours depuis l'endpoint indiqué par config.resource
 * (« pi-releases » ou « pi-features »), affiche les moyennes/médianes d'en-tête et le tableau détaillé.
 * La sélection par ligne recalcule les cartes en live (côté client, réplique de la logique serveur).
 * « Recalculer la sélection » resynchronise avec Jira les seules lignes cochées (refreshKeys[]), les
 * autres restant servies du cache. « Étendre à aujourd'hui » repousse la borne de fin à la date du jour
 * et relance le fetch. Le rendu (badge de type, colonnes, libellés) est porté par le template appelant :
 * seul l'endpoint distingue les deux onglets.
 *
 * Une ligne dont le serveur fournit un `breakdown` (Features uniquement) peut être dépliée pour afficher
 * son détail : total des Story Points, bugs et camembert des types (cf. /js/issueTypePie.js). Les lignes
 * sans `breakdown` (Releases/Versions) n'exposent aucun chevron — c'est le template qui en décide.
 *
 * La période [from, to] est initialisée par les sprints analysés, mais reste modifiable au calendrier
 * (cf. applyDates) : l'onglet est donc utilisable sans aucune analyse de sprint, le projet étant alors
 * relu de la sélection persistée par le sélecteur de la page (cf. resolveProjectKey).
 *
 * @param {{projectKey:string, from:string, to:string, resource:string, firstSprintName?:string, lastSprintName?:string}} config
 */
window.piEntitiesTab = function (config) {
    'use strict';

    /** Préfixe localStorage de la dernière période appliquée, une entrée par onglet (cf. resource). */
    const PERIOD_KEY = 'opale.piPeriod.';

    /**
     * Nombre de Features calculées par requête lors du chargement par lots.
     *
     * Une fin de PI concentre des dizaines de Features clôturées la même semaine ; calculées en une
     * seule requête à cache froid, elles dépassent max_execution_time et TOUT le travail est perdu.
     * Découpées, chaque requête reste courte et le cache serveur conserve les lots déjà aboutis : une
     * relance reprend là où on en était au lieu de repartir de zéro.
     */
    const BATCH_SIZE = 10;

    // Date du jour (locale) au format Y-m-d, comparable aux bornes from/to.
    const todayYmd = function () {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    };

    // Dernière période EXPLICITEMENT appliquée par l'utilisateur sur cet onglet. Seul un choix
    // délibéré est mémorisé : une période simplement héritée des sprints ne l'est pas, sinon elle
    // primerait plus tard sur les bornes d'une nouvelle sélection de sprints.
    const storedPeriod = (function () {
        try {
            return JSON.parse(localStorage.getItem(PERIOD_KEY + config.resource) || 'null');
        } catch (e) {
            return null;
        }
    })();

    return {
        projectKey: config.projectKey,
        // Pré-remplissage, par ordre de priorité : dernière période appliquée → bornes des sprints
        // analysés → rien pour le début, et aujourd'hui pour la fin (repère utile pour saisir la suite).
        from: (storedPeriod && storedPeriod.from) || config.from || '',
        to: (storedPeriod && storedPeriod.to) || config.to || todayYmd(),
        // Segment d'URL de l'API (« pi-releases » | « pi-features ») : seule différence entre onglets.
        resource: config.resource,
        firstSprintName: config.firstSprintName,
        lastSprintName: config.lastSprintName,
        loading: false,
        // Avancement du chargement par lots : 0 tant qu'il n'a pas lieu (fenêtre courte, ou Releases).
        batchTotal: 0,
        batchDone: 0,
        error: '',
        // Message neutre (non fautif) : chargement interrompu par l'utilisateur.
        notice: '',
        loaded: false,
        // Contrôleur du fetch en cours, pour pouvoir l'interrompre (cf. stopLoading).
        controller: null,
        // Nombre d'entités attendu (endpoint count cache-first) : alimente le message du loader.
        expectedCount: null,
        // Fenêtre repoussée à aujourd'hui via extendToToday() : bascule le libellé de fin sur « aujourd'hui ».
        extended: false,
        stats: {},
        items: [],
        // Sélection des items inclus dans les cumuls, indexée par identifiant de ligne.
        // Tous cochés par défaut ; décocher recalcule les cartes en live (cf. recalc()).
        selected: {},
        // Lignes dépliées (détail par entité), indexées par identifiant de ligne.
        expanded: {},
        // Camemberts construits, indexés par identifiant de ligne : un par ligne dépliée au moins une fois.
        charts: {},

        // Tri du tableau : par défaut, clôture décroissante (les plus récentes en haut).
        sortKey: 'closeDate',
        sortDir: 'desc',

        // Colonnes triables : nature de chaque clé (date chronologique vs texte) pour
        // choisir le comparateur et la direction par défaut au changement de colonne.
        sortableCols: {
            key: 'text', name: 'text', status: 'text', startDate: 'date', closeDate: 'date',
        },

        // Premier affichage de l'onglet : charge une seule fois (évite un appel Jira coûteux
        // tant que l'utilisateur reste sur l'onglet Sprints). Sans période exploitable (aucun sprint
        // analysé et aucune date saisie), on n'appelle rien : l'IHM invite à renseigner les dates.
        loadOnce() {
            if (this.loaded || !this.canLoad) return;
            this.load([]);
        },

        // Vrai quand une période complète ET un projet sont connus : condition de tout appel serveur.
        get canLoad() {
            return this.from !== '' && this.to !== '' && this.resolveProjectKey() !== '';
        },

        // Clé de projet : fournie par le serveur après une analyse de sprints, sinon relue de la
        // sélection persistée par le sélecteur de la page (même arbitrage local/pool que lui), ce qui
        // permet d'utiliser l'onglet sur une simple période, sans analyse préalable.
        resolveProjectKey() {
            if (this.projectKey === '' && window.jiraStore) {
                const winner = window.jiraStore.mostRecent(
                    window.jiraStore.read('jira_team_metrics_selector'),
                    window.jiraStore.read('jira_pool_team')
                );
                this.projectKey = winner?.project?.key ?? '';
            }

            return this.projectKey;
        },

        // Applique la période saisie au calendrier et relance le fetch. Les bornes inversées sont
        // refusées ici plutôt que de laisser le serveur répondre une liste vide, incompréhensible.
        //
        // Les deux bornes ne sont pas symétriques : sans date de DÉBUT, la période n'a pas de sens et
        // la soumission est refusée (le bouton est d'ailleurs désactivé) ; sans date de FIN, l'intention
        // « depuis telle date » est claire, on borne donc à aujourd'hui plutôt que de bloquer.
        applyDates() {
            if (this.from === '') {
                this.error = '❌ Renseignez une date de début.';
                return;
            }
            if (this.to === '') {
                this.to = this.todayYmd();
            }
            // Bornes alignées sur les attributs `min`/`max` des champs : elles restent contournables au
            // clavier, et Jira accepterait sans broncher une fenêtre absurde (an 1900, ou dans le futur).
            if (this.from < '2000-01-01') {
                this.error = '❌ La date de début ne peut pas précéder l\'année 2000.';
                return;
            }
            if (this.to > this.todayYmd()) {
                this.error = '❌ La date de fin ne peut pas être dans le futur.';
                return;
            }
            if (this.from > this.to) {
                this.error = '❌ La date de début doit précéder la date de fin.';
                return;
            }
            if (this.resolveProjectKey() === '') {
                this.error = '❌ Sélectionnez d\'abord un projet dans l\'onglet Sprints.';
                return;
            }

            // La période ne vient plus des sprints : les libellés de sprint n'ont plus lieu d'être.
            this.firstSprintName = '';
            this.lastSprintName = '';
            this.extended = false;
            this.rememberPeriod();
            this.load([]);
        },

        // Mémorise la période choisie pour la prochaine venue sur l'onglet. Simple confort : un
        // localStorage indisponible (navigation privée, stockage bloqué) ne doit rien casser.
        rememberPeriod() {
            try {
                localStorage.setItem(PERIOD_KEY + this.resource, JSON.stringify({ from: this.from, to: this.to }));
            } catch (e) {
                /* sans persistance, on retombe simplement sur les bornes des sprints */
            }
        },

        // Interrompt le fetch en cours. On abandonne la requête plutôt que d'appeler window.stop(),
        // qui couperait aussi les autres chargements de la page (cf. sélecteur de sprints).
        stopLoading() {
            if (this.controller) {
                this.controller.abort();
            }
            this.loading = false;
        },

        // Resynchronise avec Jira les SEULES lignes cochées, terminées ou non. Cibler évite le coût
        // d'un rechargement complet, et c'est le seul moyen de rafraîchir une entité terminée, dont
        // le cache serveur est permanent alors que son détail reste éditable dans Jira.
        refreshSelection() {
            const keys = this.items.filter((r) => this.isSelected(r)).map((r) => r.key);
            if (keys.length === 0) return;
            this.load(keys);
        },

        // Charge les données. `refreshKeys` = identifiants à recalculer côté serveur ([] = cache servi).
        load(refreshKeys) {
            if (!this.canLoad) return;

            this.loading = true;
            this.error = '';
            this.notice = '';
            this.resetDetails();            // les canvas des lignes dépliées vont être recréés
            this.controller = new AbortController();

            // Chargement par lots, réservé au chargement complet : un « Recalculer la sélection » porte
            // déjà sur les seules lignes cochées, donc sur peu d'entités.
            if (refreshKeys.length === 0) {
                this.loadInBatches();
                return;
            }

            this.fetchExpectedCount();      // loader explicite : « Chargement de N … » (best-effort)
            const url = '/api/internal/projects/' + encodeURIComponent(this.projectKey)
                + '/' + this.resource + '?from=' + encodeURIComponent(this.from)
                + '&to=' + encodeURIComponent(this.to)
                + refreshKeys.map((k) => '&refreshKeys[]=' + encodeURIComponent(k)).join('');
            fetch(url, { signal: this.controller.signal })
                .then((r) => r.text().then((text) => {
                    let body;
                    try {
                        body = JSON.parse(text);
                    } catch (e) {
                        // Réponse non-JSON : typiquement une erreur PHP émise en HTML (délai serveur
                        // dépassé sur une longue liste à cache froid). Message clair plutôt que le
                        // cryptique « Unexpected token '<' ».
                        throw new Error('Le serveur a mis trop de temps à répondre ou a renvoyé une '
                            + 'erreur. Réessayez, ou cliquez sur « Recalculer ».');
                    }
                    if (!r.ok || body.error) throw new Error(body.error || ('HTTP ' + r.status));
                    return body;
                }))
                .then((body) => {
                    this.items = body.items || [];
                    this.initSelection();       // tout coché par défaut
                    this.recalc();              // cumuls calculés côté client (cf. recalc)
                    this.loaded = true;
                })
                .catch((e) => {
                    // Interruption volontaire : ce n'est pas une erreur, on le dit sans dramatiser.
                    if (e.name === 'AbortError') {
                        this.notice = '✋ Chargement interrompu. Réduisez la période, puis relancez.';
                        return;
                    }
                    this.error = '❌ ' + e.message;
                })
                .finally(() => { this.loading = false; });
        },

        /**
         * Charge l'onglet en plusieurs requêtes courtes, au lieu d'une seule qui dépasserait le délai
         * serveur à cache froid.
         *
         * Une fin de PI concentre des dizaines d'entités à calculer ; traitées d'un bloc, elles
         * dépassaient max_execution_time et tout le travail était perdu.
         *
         * Trois temps : on demande d'abord la liste ORDONNÉE des clés (une recherche Jira, sans aucun
         * calcul de métrique), ce qui donne un avancement exact dès le départ ; on réchauffe ensuite le
         * cache serveur lot par lot ; on termine par la requête complète habituelle, désormais
         * instantanée puisque tout est en cache, qui fournit les agrégats d'en-tête définitifs.
         *
         * Les lots portent des clés EXPLICITES et non un décalage : la fenêtre est triée sur `updated`,
         * qui change pendant le chargement — un découpage par offset produirait doublons et oublis.
         */
        loadInBatches() {
            const base = '/api/internal/projects/' + encodeURIComponent(this.projectKey)
                + '/' + this.resource + '?from=' + encodeURIComponent(this.from)
                + '&to=' + encodeURIComponent(this.to);

            this.batchTotal = 0;
            this.batchDone = 0;

            this.fetchJson(base.replace('?', '/keys?'))
                .then((body) => {
                    const keys = body.keys || [];

                    // Sous un lot, le découpage n'apporte rien : requête unique, comme avant.
                    if (keys.length <= BATCH_SIZE) {
                        return null;
                    }

                    this.batchTotal = keys.length;

                    // Séquentiel et non parallèle : le serveur PHP intégré est mono-processus, des
                    // requêtes concurrentes se mettraient en file sans rien accélérer, tout en rendant
                    // l'avancement illisible.
                    let chain = Promise.resolve();
                    for (let i = 0; i < keys.length; i += BATCH_SIZE) {
                        const batch = keys.slice(i, i + BATCH_SIZE);
                        chain = chain
                            .then(() => this.fetchJson(base + batch.map((k) => '&keys[]=' + encodeURIComponent(k)).join('')))
                            .then(() => { this.batchDone += batch.length; });
                    }

                    return chain;
                })
                // Requête finale : agrégats d'en-tête et ordre définitifs, servis du cache réchauffé.
                .then(() => this.fetchJson(base))
                .then((body) => {
                    this.items = body.items || [];
                    this.initSelection();
                    this.recalc();
                    this.loaded = true;
                })
                .catch((e) => {
                    if (e.name === 'AbortError') {
                        this.notice = '✋ Chargement interrompu. Les lots déjà chargés sont conservés :'
                            + ' relancez pour reprendre où vous en étiez.';
                        return;
                    }
                    this.error = '❌ ' + e.message
                        + ' Les lots déjà chargés sont conservés : relancez pour reprendre.';
                })
                .finally(() => {
                    this.loading = false;
                    this.batchTotal = 0;
                });
        },

        /**
         * Exécute une requête JSON de l'onglet, avec le même diagnostic d'erreur que le chargement
         * simple : une réponse non-JSON signale presque toujours une erreur PHP rendue en HTML.
         */
        fetchJson(url) {
            return fetch(url, { signal: this.controller.signal })
                .then((r) => r.text().then((text) => {
                    let body;
                    try {
                        body = JSON.parse(text);
                    } catch (e) {
                        throw new Error('Le serveur a mis trop de temps à répondre ou a renvoyé une erreur.');
                    }
                    if (!r.ok || body.error) throw new Error(body.error || ('HTTP ' + r.status));
                    return body;
                }));
        },

        // Récupère le nombre d'entités attendu via l'endpoint count (cache-first, quasi instantané)
        // pour afficher « Chargement de N … ». Purement indicatif : un échec est silencieux et ne
        // perturbe pas le chargement principal du tableau.
        fetchExpectedCount() {
            this.expectedCount = null;
            const url = '/api/internal/projects/' + encodeURIComponent(this.projectKey)
                + '/' + this.resource + '/count?from=' + encodeURIComponent(this.from)
                + '&to=' + encodeURIComponent(this.to)
                + '&cacheOnly=1';   // count seulement s'il est déjà en cache : jamais de build ici

            fetch(url)
                .then((r) => (r.ok ? r.json() : null))
                .then((body) => {
                    if (body && typeof body.count === 'number') this.expectedCount = body.count;
                })
                .catch(() => {});
        },

        // ===== Extension de la fenêtre jusqu'à aujourd'hui =====

        // Date du jour (locale) au format Y-m-d, comparable aux bornes from/to.
        todayYmd() {
            return todayYmd();
        },

        // Vrai tant que la borne de fin est antérieure à aujourd'hui (bouton d'extension pertinent).
        get canExtendToToday() {
            return this.to !== '' && this.to < this.todayYmd();
        },

        // Repousse la borne de fin à aujourd'hui et recharge (nouvelle fenêtre = nouvelle clé de cache,
        // recalcul serveur au besoin sans refresh forcé).
        extendToToday() {
            this.to = this.todayYmd();
            this.extended = true;
            this.rememberPeriod();
            this.load([]);
        },

        // ===== Sélection des items inclus dans les cumuls =====

        // Identifiant stable d'une ligne (sert de clé de sélection).
        rowId(row) {
            return row.type + ':' + row.key;
        },

        // (Ré)initialise la sélection avec tous les items cochés.
        initSelection() {
            const sel = {};
            this.items.forEach((row) => { sel[this.rowId(row)] = true; });
            this.selected = sel;
        },

        // Un item est inclus tant qu'il n'a pas été explicitement décoché.
        isSelected(row) {
            return this.selected[this.rowId(row)] !== false;
        },

        // Bascule un item et recalcule les cartes en live.
        toggle(row) {
            this.selected[this.rowId(row)] = !this.isSelected(row);
            this.recalc();
        },

        // Vrai si tous les items sont cochés (état de la case d'en-tête).
        get allSelected() {
            return this.items.length > 0 && this.items.every((r) => this.isSelected(r));
        },
        // Vrai si une partie seulement est cochée (état indéterminé de l'en-tête).
        get someSelected() {
            return this.items.some((r) => this.isSelected(r)) && !this.allSelected;
        },

        // Case d'en-tête : si tout est coché on décoche tout, sinon on coche tout.
        toggleAll() {
            const target = !this.allSelected;
            this.items.forEach((r) => { this.selected[this.rowId(r)] = target; });
            this.recalc();
        },

        // ===== Détail dépliable d'une ligne (onglet Features) =====

        // Une ligne n'est dépliée qu'après un clic explicite sur son chevron.
        isExpanded(row) {
            return this.expanded[this.rowId(row)] === true;
        },

        // Déplie/replie une ligne. Le camembert n'est construit qu'au premier dépliage, une fois la
        // ligne de détail rendue par Alpine (son canvas n'existe pas avant).
        toggleExpand(row) {
            const id = this.rowId(row);
            this.expanded[id] = !this.isExpanded(row);

            if (this.expanded[id]) {
                this.$nextTick(() => { this.renderPie(row); });
            }
        },

        // Construit le camembert de répartition par type de la ligne (une seule fois par ligne).
        renderPie(row) {
            const id = this.rowId(row);
            const types = (row.breakdown && row.breakdown.byType) || [];
            if (this.charts[id] || !window.issueTypePie || types.length === 0) {
                return;
            }

            const canvas = document.querySelector('[data-pie="' + id + '"]');
            if (!canvas) {
                return;
            }

            this.charts[id] = window.issueTypePie(canvas, document.querySelector('[data-pie-filters="' + id + '"]'), types);
        },

        // Étiquettes « bug » d'une ligne, mises à plat pour l'affichage (l'ordre serveur est conservé).
        bugLabels(row) {
            const byLabel = (row.breakdown && row.breakdown.bugsByLabel) || {};

            return Object.keys(byLabel).map((label) => ({ label: label, count: byLabel[label] }));
        },

        // Referme tout et détruit les camemberts : au rechargement, Alpine recrée les canvas, les
        // instances Chart.js pointant sur les anciens doivent être libérées.
        resetDetails() {
            Object.keys(this.charts).forEach((id) => { this.charts[id].destroy(); });
            this.charts = {};
            this.expanded = {};
        },

        // Recalcule les agrégats d'en-tête à partir des seuls items cochés.
        // Réplique la logique serveur (collect + avgMedian + fenêtre) pour une MAJ instantanée.
        recalc() {
            const lead = [], cycle = [], waiting = [], waitingTotals = [];
            const realDays = [], realPct = [], effDays = [], effPct = [];
            let nbCreated = 0, nbDone = 0, nbDoneOutOfWindow = 0, total = 0;

            this.items.filter((r) => this.isSelected(r)).forEach((r) => {
                if (this.inWindow(r.startDate)) nbCreated++;
                if (r.isDone) {
                    nbDone++;
                    if (!this.inWindow(r.closeDate)) nbDoneOutOfWindow++;
                }
                this.push(lead, r.leadTime);
                this.push(cycle, r.cycleTime);
                this.push(waiting, r.waitingAvg);
                this.push(waitingTotals, r.waitingTotal);
                this.push(realDays, r.realisationDays);
                this.push(realPct, r.realisationPct);
                this.push(effDays, r.efficaciteDays);
                this.push(effPct, r.efficacitePct);
                total++;
            });

            this.stats = {
                nbCreated, nbDone, nbDoneOutOfWindow, total,
                lead: this.avgMedian(lead),
                cycle: this.avgMedian(cycle),
                waiting: Object.assign(this.avgMedian(waiting), {
                    maxTotal: waitingTotals.length ? Math.max(...waitingTotals) : null,
                }),
                realisation: { avgDays: this.avgMedian(realDays).avg, avgPct: this.avgMedian(realPct).avg },
                efficacite: { avgDays: this.avgMedian(effDays).avg, avgPct: this.avgMedian(effPct).avg },
            };
        },

        // Ajoute une valeur exploitable (non nulle) au bucket d'agrégation.
        push(bucket, v) {
            if (v !== null && v !== undefined) bucket.push(v);
        },

        // Date Y-m-d dans la fenêtre [from, to] (comparaison lexicographique = chronologique).
        inWindow(ymd) {
            return ymd != null && ymd >= this.from && ymd <= this.to;
        },

        // Moyenne et médiane d'un tableau (0 si vide), arrondies à 2 décimales — comme le backend.
        avgMedian(values) {
            if (values.length === 0) return { avg: 0, median: 0 };
            const s = [...values].sort((a, b) => a - b);
            const n = s.length, mid = Math.floor(n / 2);
            const median = (n % 2 === 0) ? (s[mid - 1] + s[mid]) / 2 : s[mid];
            const round2 = (x) => Math.round(x * 100) / 100;
            return { avg: round2(s.reduce((a, b) => a + b, 0) / n), median: round2(median) };
        },

        // Change la colonne de tri : inverse la direction si déjà active, sinon applique la
        // direction par défaut (dates : décroissant ; texte : croissant).
        sortBy(key) {
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortKey = key;
                this.sortDir = this.sortableCols[key] === 'date' ? 'desc' : 'asc';
            }
        },

        // Flèche affichée dans l'en-tête de la colonne active (vide pour les autres).
        sortIndicator(key) {
            if (this.sortKey !== key) return '';
            return this.sortDir === 'asc' ? ' ▲' : ' ▼';
        },

        // Copie triée de items selon la colonne/direction courantes. Les valeurs vides
        // (non démarré / non clôturé / sans nom) sont toujours reléguées en bas.
        sortedItems() {
            const key = this.sortKey;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const isDate = this.sortableCols[key] === 'date';

            return [...this.items].sort((a, b) => {
                const va = a[key], vb = b[key];
                const ea = (va === null || va === undefined || va === '');
                const eb = (vb === null || vb === undefined || vb === '');
                if (ea && eb) return 0;
                if (ea) return 1;   // valeurs vides toujours en bas
                if (eb) return -1;
                // Dates au format Y-m-d : comparaison lexicographique = chronologique.
                const cmp = isDate
                    ? (va < vb ? -1 : (va > vb ? 1 : 0))
                    : String(va).localeCompare(String(vb), 'fr', { sensitivity: 'base' });
                return cmp * dir;
            });
        },

        // Formatage : « X j » ou tiret si nul/indéfini.
        fmtDays(v) {
            return (v === null || v === undefined) ? '—' : (Math.round(v * 100) / 100) + ' j';
        },
        // Part d'un sous-ensemble dans son total, en suffixe : « (12.5 % du total) ». Chaîne vide si le
        // total est nul ou absent — un pourcentage d'un ensemble vide n'aurait aucun sens à afficher.
        pctOfTotal(part, total) {
            return total ? ' (' + this.fmtPct(part / total * 100) + ' du total)' : '';
        },
        // Formatage : nombre brut arrondi au centième (Story Points), tiret si nul/indéfini.
        fmtNum(v) {
            return (v === null || v === undefined) ? '—' : String(Math.round(v * 100) / 100);
        },
        // Formatage : « X % » ou tiret si nul/indéfini.
        fmtPct(v) {
            return (v === null || v === undefined) ? '—' : (Math.round(v * 10) / 10) + ' %';
        },
        // Date déjà au format Y-m-d côté serveur ; tiret si absente.
        fmtDate(v) {
            return v ? v : '—';
        }
    };
};
