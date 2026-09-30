# OPALE — Outil Pour l'Amélioration Lean des Équipes

> Application web PHP légère qui transforme les données **Jira** en une lecture **factuelle, mesurable et actionnable** des sprints et des flux de valeur (**Value Stream Mapping Lean**), au service du Scrum Master et de son équipe (contexte Scrum / SAFe).
>
> *(Dépôt historique : `VSM-Jira-PHP`.)*

## 📑 Sommaire

- [🎯 Objectif du projet](#-objectif-du-projet)
- [🧠 Principes clés](#-principes-clés)
- [🏗️ Architecture](#️-architecture)
- [🧩 Fonctionnalités (pages)](#-fonctionnalités-pages)
- [📊 Métriques exposées](#-métriques-exposées)
- [🤖 Synthèse IA & export de prompt](#-synthèse-ia--export-de-prompt)
- [🖥️ Frontend](#️-frontend)
- [📙 Documentation utilisateur](#-documentation-utilisateur)
- [🔐 Configuration](#-configuration)
- [🚀 Démarrer l'application](#-démarrer-lapplication)
- [🚧 État du projet](#-état-du-projet)
- [👤 Auteur](#-auteur)
- [⚠️ Disclaimer](#️-disclaimer)

## 🎯 Objectif du projet

**OPALE** produit des **Value Stream Maps (VSM) Lean** et des **métriques d'équipe Scrum/SAFe** directement à partir des API **Jira**, considéré comme **source de vérité unique**.

L'objectif est de fournir une **vision factuelle, mesurable et exploitable** des flux de valeur (sprints, releases, features, PI) afin de :

* Visualiser les **délais réels** (Lead Time, Cycle Time, Waiting Time)
* Identifier les **goulots d'étranglement** et les temps d'attente
* Alimenter les **discussions d'amélioration continue** au niveau Équipe et Train
* Soutenir les **pratiques Agile / SAFe** (Inspect & Adapt, flow metrics) avec des indicateurs issus des **pratiques Lean**

> ℹ️ OPALE n'a **pas vocation à évaluer ou surveiller la performance des individus**. Il aide l'équipe à mieux comprendre son système de travail.

---

## 🧠 Principes clés

* **Jira = source unique de données** — aucune donnée métier n'est ressaisie dans l'outil (seuls la configuration, la capacité d'équipe et quelques ajustements de planification sont saisis).
* **Lecture seule par défaut** — la seule écriture possible vers Jira est explicite et confirmée : la mise à jour du « Reste à faire » / « Points rééstimés » depuis la page Planification (avec commentaire automatique tracé).
* **Séparation claire des responsabilités (MVC)** — Controllers / Models (domaine) / Services / Repositories / Views.
* **KISS avant tout** — code volontairement simple, lisible, testé et documenté (le projet sert aussi de support pédagogique).
* **Résilience** — timeouts et retries sur les appels Jira, écritures de config **atomiques**, filet anti-page-blanche, dégradation gracieuse (jours fériés, LLM optionnel).

---

## 🏗️ Architecture

Architecture **PHP MVC** simple et explicite. Backend PHP ^8.5 + **Slim ^4.15** ; Frontend HTML + **Alpine.js** (+ Chart.js pour les graphiques).

```
├── config_files/                       # Configuration applicative (versionnée)
│   ├── teams/<KEY>_team_config.json    # Config PAR PROJET, éditée dans l'app (/config) :
│   │                                   #   board, workflow, types, sprints ignorés, équipe (roster),
│   │                                   #   capacité par sprint, projet parent (Releases/Features)
│   ├── jira_workflow_issues.json       # Workflow global par défaut (repli si un projet n'a pas le sien)
│   ├── jira_workflow_features.json     # Workflow des Features (SAFe)
│   ├── jira_issues_types.json          # Types de tickets : defaultTypes + types détectés
│   ├── watched_projects.json           # Projets configurés / suivis
│   ├── metric_tooltips.json            # Glossaire des infobulles de métriques
│   └── llm_*.md                        # Prompts versionnés (synthèse IA locale + export)
│
├── cache/                # Snapshots / projections JSON (sprints clôturés, releases, features, jours fériés…)
├── import/               # Fichier d'exemple d'import de capacité (.xlsx) + uploads temporaires (supprimés après usage)
├── php/                  # (Version Standalone) moteur PHP portable 8.5 (NTS)
│
├── public/
│   ├── index.php         # Front controller : routing Slim + gestion d'erreurs + filet anti-fatal (timeouts)
│   ├── css/              # CSS découpé par page : global.css (commun) + une feuille par page
│   └── js/               # JavaScript minimal : composants Alpine partagés (pool de sélection Jira, préférences…)
│
├── src/
│   ├── Controller/       # Contrôleurs HTTP (Slim)
│   ├── Model/            # Domaine : Sprint, Version, Feature, ProgramIncrement, Issue, Timeline, TeamMetrics…
│   ├── Service/          # Orchestration + accès Jira/API, caches, capacité, LLM
│   ├── Repository/       # Accès données (boards, projets)
│   ├── View/             # Transformateurs de données pour les templates phtml
│   ├── Dto/ Support/ Exception/ UseCase/
│   └── routes.php
│
├── tests/                # Tests unitaires (PHPUnit)
├── views/                # Templates phtml (pages + composants)
├── .env                  # Identifiants Jira (placeholders versionnés ; secrets jamais commités)
├── composer.json
├── install_windows.bat / start_windows.bat
└── README.md
```

> Les **jours fériés** proviennent désormais de l'**API officielle** [calendrier.api.gouv.fr](https://calendrier.api.gouv.fr/jours-feries/) (service `GovApiService`, mis en cache sur disque, dégradation gracieuse si l'API est indisponible).

---

## 🧩 Fonctionnalités (pages)

| Page | Rôle |
|---|---|
| **🛠️ Analyse du Sprint** (`/sprint`) | Prédictibilité, engagement, atterrissage par statut, objectifs du sprint, évolution du périmètre, reste à faire, statistiques (SP, âge, attente), amélioration continue & innovation. |
| **📅 Capacité équipe** (`/capacity`) | Grille de présence jours ouvrés × membres (par rôle), événements, ratio de conversion, récap d'équipe, agrégation par PI et **import d'absences Excel**. Fait foi pour les jours/homme des sprints. |
| **🧮 Planification de Sprint** (`/planification`) | Reliquat + backlog des sprints à venir, capacité (points) vs volume avec marge, recalcul SP en temps réel, rééstimation post-mortem et mise à jour de Jira (commentaire tracé). |
| **📊 Métriques équipe** (`/team-metrics`) | 3 onglets : **Sprints** (vélocité, engagement, lead/cycle/waiting time, âge, nuage de points cycle time), **Releases**, **Features**. |
| **🚀 VSM par Release / Version** (`/release`) | Flux de valeur d'une Release Jira (issuelinks) ou d'une Version fixe : timeline consolidée, Lead/Cycle/Waiting, Flow Efficiency, VSM par statut. |
| **⭐ VSM par Feature** (`/feature`) | Analyse à l'échelle d'une Feature (Epic SAFe) : métriques propres + agrégat des tickets enfants, PI, équipe, jalon. |
| **⚙️ Configuration** (`/config`) | Configuration **par projet** (partagée) + préférences d'affichage **personnelles**. |
| **📙 Manuel Utilisateur** (`/docs`) | Documentation utilisateur complète, intégrée à l'app. |

---

## 📊 Métriques exposées

Toutes les durées sont calculées **à partir des dates Jira réelles**, en **jours ouvrés** (hors week-ends et fériés), sur l'état **reconstruit à la date de référence** (rejeu du changelog).

* **Lead Time** — création → résolution (files d'attente comprises)
* **Cycle Time** — 1er passage « En cours » → résolution
* **Waiting Time** — temps de mise en attente / blocage, dédupliqué par ticket (statuts d'attente configurés **ou** étiquette contenant « attente » **ou** ticket *flaggé*)
* **Flow Efficiency** — (Cycle − Waiting) / Cycle
* **Prédictibilité** (SP terminés / planifiés) & **Engagement** (tickets du périmètre initial terminés) — cible commune **80 %**
* **Vélocité** — SP terminés par sprint, normalisables par **jours/homme** (capacité réelle) et jours ouvrés
* **Âge des tickets**, **Throughput**, **Cycle Time par estimation** (nuage de points)
* **Timelines consolidées** + **VSM (Value Stream Map)** par statut

---

## 🤖 Synthèse IA & export de prompt

* **Synthèse IA locale** (optionnelle, activée par `LLM_ENABLED`) : génère une analyse en langage naturel des sprints à partir d'un **digest chiffré anonyme** (aucun ticket brut), via un modèle **local, fermé, non connecté à Internet**. À relire et valider par le Scrum Master.
* **Export de prompt** (toujours disponible, même sans LLM local) : télécharge un fichier `.txt` (prompt versionné + métriques anonymes) prêt à coller dans l'assistant IA de votre choix (Copilot, Claude, ChatGPT, Gemini…).

Ces deux fonctions sont disponibles sur les pages **Analyse du Sprint** et **Métriques équipe**.

---

## 🖥️ Frontend

Frontend volontairement **léger et sans framework lourd**.

* **Alpine.js** pour la réactivité, **Chart.js** pour les graphiques
* Communication via **API JSON** internes (`/api/internal/…`)
* Gestion défensive de l'asynchrone (timeouts, messages d'erreur, bouton « Stopper le chargement »)
* Pas de logique métier côté client ; CSS découpé par page

---

## 📙 Documentation utilisateur

Une **documentation utilisateur complète** est intégrée à l'application, via `📙 Manuel Utilisateur` dans la barre de navigation ([http://localhost:8080/docs](http://localhost:8080/docs)).

Elle couvre : démarrage rapide, configuration du projet (workflow, types, sprints, **équipe**, **projet parent**), analyse du sprint, support à la planification, **métriques équipe (4 onglets, dont Capacité et import Excel)**, VSM (Release / Feature / PI), métriques Lean, synthèse IA / export, et une FAQ. La documentation est **progressive** (SM débutant → confirmé).

---

## 🔐 Configuration

### 1. Identifiants Jira (`.env`)

Les accès Jira et paramètres sensibles sont stockés dans un fichier `.env` (placeholders inexploitables par défaut).

`.env.template` :
```
# Duplicate this file, rename it ".env" then replace these infos with yours
JIRA_BASE_URL=https://your-company.atlassian.net
JIRA_EMAIL=email@company.com
JIRA_API_TOKEN=your_api_token

# Disable SSL verification, set to true only for demo !
IS_DEMO=false

# Optional : enable the local AI synthesis feature
LLM_ENABLED=false

# Optional : Secure reboot key
REBOOT_TOKEN=
```

=> **Renseignez ces valeurs (rapprochez-vous d'un Admin Jira si besoin). Sans elles, l'application ne peut pas communiquer avec Jira.**

=> **Générer un token API Jira** : https://id.atlassian.com/manage-profile/security/api-tokens ([doc officielle](https://support.atlassian.com/atlassian-account/docs/manage-api-tokens-for-your-atlassian-account/#Create-an-API-token))

### 2. Configuration du projet — **dans l'application** (`/config`)

Tout le reste se configure **dans l'interface**, sans éditer de JSON à la main. La configuration est **par projet Jira** et **partagée** (côté serveur) entre les utilisateurs de l'outil pour ce projet :

* **Board par défaut** — présélectionné sur les pages Sprint / Planification / Métriques.
* **Workflow** — classez les statuts Jira dans **4 catégories** (🧠 Affinage · ⚙️ Sprint · ✅ Terminés · ⌛ En attente/Bloqué). L'**ordre** porte le sens (statut de départ en haut, final en bas). Un bouton **« ⤵️ Importer la configuration depuis Jira »** remplit ces catégories à partir des statuts réels du projet. Un statut non classé apparaît en *Autre*.
* **Types de tickets** — cochez les types inclus dans les métriques (les décochés sont exclus).
* **Sprints à ignorer** — excluez les sprints fictifs / IP des calculs, graphiques et de l'historique.
* **Équipe (roster)** — membres `{nom, rôle Dev/BA/QA/PO/SM, dates entrée/sortie}`, source de l'onglet **Capacité**.
* **Projet parent (Releases & Features)** + **nom d'équipe** — pour les contextes SAFe où Releases/Features vivent dans un projet distinct.

> Les **préférences d'affichage des métriques** (page Sprint / Métriques) sont, elles, **personnelles** (stockées dans le navigateur).

---

## 🚀 Démarrer l'application

> 📥 **Première installation sur une machine vierge ?** Suivez le guide pas à pas
> [README_INSTALL_APP.md](README_INSTALL_APP.md) : un installateur (`install_windows.bat`) télécharge PHP,
> installe les dépendances et vous guide entièrement en français.

Après avoir configuré le projet (cf. point précédent), il suffit de double-cliquer sur **`start_windows.bat`**.

Celui-ci démarre automatiquement le serveur PHP embarqué et ouvre l'appli dans votre navigateur ([http://localhost:8080/](http://localhost:8080/)).

***Si vous n'avez pas le serveur PHP embarqué, téléchargez PHP 8.5 et placez le dossier `php` dans le dossier de l'application, puis vérifiez le chemin de `php.exe` dans `start_windows.bat`.***

### ⚡ Performances : OPcache

Sans cache d'opcode, le serveur intégré **recompile tout le code source à chaque requête** — appels AJAX
compris. Mesuré sur ce projet : **~26 ms par requête sans OPcache contre ~3 ms avec** (×9), avant même le
moindre traitement métier. C'est donc un réglage à ne pas perdre de vue.

**Bonne nouvelle : en PHP 8.5, OPcache est intégré au core et `opcache.enable` vaut déjà `1` par défaut.** Une
installation fraîche en bénéficie sans rien configurer. `install_windows.bat` écrit malgré tout un bloc de
réglages en fin de `php\php.ini` — comme **filet**, pour qu'un `php.ini` dérivé de `php.ini-production`, un
défaut modifié par une version future ou un fichier retouché à la main ne dégradent pas l'appli en silence.
`start_windows.bat` avertit au démarrage si OPcache s'avère inactif.

Dans ce bloc, trois directives seulement s'écartent réellement des défauts :

- `opcache.enable_cli=1` — sans effet sur le serveur, mais accélère `composer` / `phpunit` ;
- **`memory_limit=256M`** — `php.ini-development` impose 128 Mo, insuffisant pour les caches JSON volumineux
  (le seul décodage de `cache/feature_metrics.json` culmine à 38 Mo) ;
- **`max_execution_time=120`** — au défaut de 30 s, une fenêtre large de l'onglet Features (environ deux appels
  Jira par Feature, **en série**) voyait PHP tuer la requête en cours de route. Le cache n'étant écrit qu'en fin
  de calcul, tout le travail accompli était perdu et le rechargement repartait de zéro.

> ⚠️ Le serveur intégré est **mono-processus** : pendant une requête longue, toutes les autres sont mises en
> file d'attente. `max_execution_time` est un filet de sécurité, pas une permission de laisser filer les temps
> de réponse.

> ⛔ **Ne pas ajouter `zend_extension=opcache`** (ni `ext/php_opcache.dll`) : OPcache étant compilé
> statiquement dans `php.exe`, PHP tenterait de charger un second exemplaire depuis un fichier inexistant et
> émettrait un `Failed loading Zend extension 'opcache'` à chaque appel.

> ⚠️ La directive qui gouverne le serveur est **`opcache.enable`**, pas `opcache.enable_cli` : `php -S` tourne
> sous le SAPI **`cli-server`**, hors du périmètre de `enable_cli` (qui ne vise que le SAPI `cli`, donc les
> scripts en ligne de commande).

**Vérifier qu'OPcache tourne réellement :**

```bash
php -r "var_dump(opcache_get_status()['opcache_enabled']);"   # doit afficher bool(true)
php -m | findstr /i opcache                                    # doit lister "Zend OPcache"
```

> 🛠️ **Poste de développement** — si vous lancez l'appli avec un PHP **hors** du dossier `php\` (typiquement
> `C:\php`), ce bloc n'est pas appliqué : reportez-le manuellement dans votre `php.ini`.

---

## 🚧 État du projet

Projet **en cours de développement et de maintien**.

### ✅ Fonctionnalités implémentées

* ✅ **Analyse du Sprint** — prédictibilité, engagement, atterrissage, objectifs, périmètre, statistiques
* ✅ **Support à la planification** — capacité vs volume, rééstimation, mise à jour Jira tracée
* ✅ **Métriques équipe** — vélocité, engagement, lead/cycle/waiting time, âge, nuage de points
* ✅ **Capacité d'équipe** — grille de présence par membre + **import d'absences depuis Excel**
* ✅ **VSM par Release / Version** et **VSM par Feature** — flux de valeur
* ✅ **Métriques du PI** — vue programme *(beta)*
* ✅ **Configuration projet in-app** — board, workflow (importable de Jira), types, sprints, équipe, projet parent
* ✅ **Synthèse IA locale** *(optionnelle)* et **export de prompt** pour analyse IA externe
* ✅ **Documentation utilisateur intégrée**

### 🔄 Axes d'évolution

* Amélioration des performances (parallélisation des appels Jira, caching avancé)
* Évolutions UI/UX
* Support de nouvelles métriques
* et bien d'autres idées utiles à un Scrum Master et à son équipe 😉

---

## 👤 Auteur

[Hugues-Arnaud Lamot](https://www.linkedin.com/in/hugues-arnaud-lamot/) — *Scrum Master, et ancien Lead Dev PHP*

---

## ⚠️ Disclaimer

Ce projet n'est **pas un produit officiel Atlassian** et n'a aucune affiliation avec Jira.

Ce projet est conçu et **développé de ma propre initiative, sur mon temps libre**, au service de mes équipes en tant que Scrum Master.

Il n'est **pas lié à mon client actuel, ni à mon employeur. Il ne bénéficie d'aucun budget, et n'est pas soumis à une feuille de route engagée**.
