# 📥 Installer VSM Jira PHP sur votre ordinateur

Ce guide vous accompagne **pas à pas** pour faire fonctionner l'application sur votre
poste, **même si vous n'êtes pas développeur**. Aucune connaissance technique n'est
nécessaire : un programme d'installation (`install_windows.bat` sur Windows,
`install_mac.command` sur Mac) fait tout le travail à votre place et vous guide à chaque
étape.

> 🕐 Comptez environ **5 minutes**, dont l'essentiel en téléchargement automatique.

- [🪟 Windows (méthode automatique)](#-windows-méthode-automatique)
- [🍎 Mac (méthode automatique)](#-mac-méthode-automatique)
- [🆘 En cas de souci](#-en-cas-de-souci)

---

## 🖥️ Windows (méthode automatique)

### Ce dont vous avez besoin au départ

- Un ordinateur **Windows 10 ou 11**.
- Une connexion **Internet**.
- Le **dossier du projet** sur votre machine. Deux possibilités :
  - vous avez reçu un fichier **`.zip`** : décompressez-le où vous voulez
    (clic droit → *Extraire tout…*), par exemple dans `Documents` ;
  - ou vous avez récupéré le projet via Git : le dossier est déjà là.
- Vos **accès Jira** (voir l'étape 3). Si vous ne les avez pas, rapprochez-vous d'un **administrateur Jira** de votre entreprise.

---

### Étape 1 — Lancer l'installation

1. Ouvrez le dossier du projet dans l'explorateur Windows.
2. Repérez le fichier **`install_windows.bat`**.
3. **Double-cliquez** dessus.

Une fenêtre noire (l'invite de commande) s'ouvre. **C'est normal.** Elle affiche la
progression et **vous parle en français**. Laissez-la travailler.

> 📁 Vous verrez aussi un dossier **`install\`** à côté. Il contient la « mécanique » de
> l'installateur et son journal. **Vous n'avez rien à y faire** : lancez toujours
> `install_windows.bat`, jamais un fichier situé dans `install\`.

> ⚠️ Si Windows affiche un avertissement bleu *« Windows a protégé votre ordinateur »*,
> cliquez sur **« Informations complémentaires »** puis **« Exécuter quand même »**.
> Ce message apparaît simplement parce que le fichier vient d'Internet ; il n'y a aucun
> risque, ce script fait partie du projet.

Le programme réalise automatiquement, dans l'ordre :

| Étape | Ce qu'il fait | Pourquoi |
|-------|---------------|----------|
| 1/5 | Télécharge le moteur **PHP** dans le dossier `php\` | C'est le « moteur » qui fait tourner l'application |
| 2/5 | Configure ce moteur | Active ce qu'il faut pour parler à Jira et lire les fichiers Excel |
| 3/5 | Installe les **composants** de l'application (dossier `vendor\`) | Les briques logicielles nécessaires |
| 4/5 | Crée votre fichier de **configuration** `.env` | C'est là que vous mettrez vos accès Jira |
| 5/5 | Prépare les **dossiers de travail** | Pour que l'appli enregistre ses données |

À la fin, la fenêtre affiche **« Installation terminée »**. 🎉

---

### Étape 2 — Renseigner vos accès Jira

Pendant l'installation, à l'**étape 4/5**, le programme vous propose :

> *Ouvrir .env dans le Bloc-notes maintenant ? [O]ui / [N]on*

Tapez **`O`** puis **Entrée** : le fichier `.env` s'ouvre dans le Bloc-notes.
(Si vous avez répondu Non, vous pourrez l'ouvrir plus tard : le fichier `.env` est à la
racine du dossier du projet.)

Vous y voyez ces lignes — **remplacez les valeurs par les vôtres** :

```
JIRA_BASE_URL=https://votre-entreprise.atlassian.net
JIRA_EMAIL=votre.email@entreprise.com
JIRA_API_TOKEN=votre_jeton_api
```

- **`JIRA_BASE_URL`** : l'adresse de votre Jira (celle que vous voyez dans votre
  navigateur, du type `https://xxxxx.atlassian.net`).
- **`JIRA_EMAIL`** : votre adresse e-mail de connexion à Jira.
- **`JIRA_API_TOKEN`** : un **jeton personnel** (une sorte de mot de passe pour les
  applications). Voir juste en dessous comment l'obtenir.

Puis **Fichier → Enregistrer** (ou `Ctrl + S`) et fermez le Bloc-notes.

> ❗ Sans ces trois informations, l'application **ne peut pas** récupérer vos données
> Jira. Elles sont indispensables.

#### 🔑 Comment obtenir votre jeton API Jira

1. Ouvrez cette page (connectez-vous avec votre compte Jira si besoin) :
   **https://id.atlassian.com/manage-profile/security/api-tokens**
2. Cliquez sur **« Create API token »**, donnez-lui un nom (ex. *VSM*), validez.
3. **Copiez** le jeton affiché et collez-le à la place de `votre_jeton_api` dans `.env`.

> 🔒 Ce jeton est confidentiel, comme un mot de passe. Le fichier `.env` reste **sur
> votre ordinateur uniquement** et n'est jamais partagé.

---

### Étape 3 — Lancer l'application

**Vous n'avez rien à faire** : à la fin de l'installation, l'application démarre
toute seule dans une **nouvelle console**, et votre navigateur s'ouvre sur
**http://localhost:8080/** 🚀

Voilà, l'application tourne ! Pour l'utiliser à nouveau plus tard, **double-cliquez
sur `start_windows.bat`** — l'installation, elle, ne se fait **qu'une seule fois**.

> Pour **redémarrer** l'application : double-cliquer sur `reboot.bat`.

> Pour **arrêter** l'application : fermez simplement la console ouverte par
> `start_windows.bat`.

---

### Étape 4 — Adapter l'application à votre équipe

L'application fonctionne dès maintenant. Mais pour que les métriques collent
**exactement** à votre façon de travailler, vous pouvez configurer votre projet directement dans l'application Web : **http://localhost:8080/config**

Les réglages sont expliqués en détail directement **dans l'application**, via le lien
**📙 Manuel Utilisateur** en haut de page. Vous pouvez donc y revenir plus tard, une
fois l'appli lancée.

---

## 🍎 Mac (méthode automatique)

### Ce dont vous avez besoin au départ

- Un **Mac** récent (macOS 12 ou plus).
- Une connexion **Internet**.
- Le **dossier du projet** sur votre machine (décompressé depuis un `.zip`, ou récupéré
  via Git).
- Vos **accès Jira** (voir l'étape 2). Si vous ne les avez pas, rapprochez-vous d'un
  **administrateur Jira** de votre entreprise.

> ℹ️ Sur Mac, l'installation s'appuie sur **Homebrew** (un gestionnaire d'outils très
> répandu) pour installer PHP. Si Homebrew n'est pas déjà présent, l'installateur vous
> le met en place automatiquement. PHP est alors installé au niveau du système (et non
> dans le dossier du projet, contrairement à la version Windows).

---

### Étape 1 — Lancer l'installation

1. Ouvrez le dossier du projet dans le Finder.
2. **Double-cliquez** sur le fichier **`install_mac.command`**.
3. Une fenêtre **Terminal** s'ouvre et affiche la progression, **en français**.
   Laissez-la travailler.

> ⚠️ **Au premier lancement, macOS peut refuser d'ouvrir le fichier** (« développeur non
> identifié »). Dans ce cas : **clic droit** sur `install_mac.command` → **« Ouvrir »** →
> confirmez **« Ouvrir »**. Vous n'aurez à le faire qu'une fois.
>
> Si le Terminal indique *« autorisation refusée »*, ouvrez l'app **Terminal**, tapez
> `chmod +x ` (avec l'espace final), **glissez-déposez** `install_mac.command` dans la
> fenêtre, puis **Entrée** — et recommencez le double-clic.

> 📁 Un dossier **`install/`** est présent à côté : c'est la « mécanique » de
> l'installateur et son journal. **Vous n'avez rien à y faire** : lancez toujours
> `install_mac.command`, jamais un fichier situé dans `install/`.

Le programme réalise automatiquement, dans l'ordre :

| Étape | Ce qu'il fait | Pourquoi |
|-------|---------------|----------|
| 1/5 | Vérifie / installe **Homebrew** | L'outil qui permet d'installer PHP proprement |
| 2/5 | Vérifie / installe **PHP** | Le « moteur » qui fait tourner l'application |
| 3/5 | Installe les **composants** de l'application (dossier `vendor/`) | Les briques logicielles nécessaires |
| 4/5 | Crée votre fichier de **configuration** `.env` | C'est là que vous mettrez vos accès Jira |
| 5/5 | Prépare les **dossiers de travail** | Pour que l'appli enregistre ses données |

À la fin, la fenêtre affiche **« Installation terminée »**. 🎉

---

### Étape 2 — Renseigner vos accès Jira

À l'**étape 4/5**, l'installateur vous propose :

> *Ouvrir .env maintenant ? [o/N]*

Tapez **`o`** puis **Entrée** : le fichier `.env` s'ouvre dans **TextEdit**.
(Si vous répondez non, le fichier `.env` reste disponible à la racine du dossier du
projet, vous pourrez l'éditer plus tard.)

La marche à suivre (valeurs à renseigner, obtention du **jeton API Jira**) est
**identique à la version Windows** : reportez-vous à l'[étape 2 de la section
Windows](#étape-2--renseigner-vos-accès-jira) ci-dessus. Pensez à **enregistrer**
(`Cmd + S`) puis à fermer TextEdit.

> ❗ Sans ces trois informations (adresse, e-mail, jeton), l'application ne peut pas
> récupérer vos données Jira.

---

### Étape 3 — Lancer l'application

**Vous n'avez rien à faire** : à la fin de l'installation, l'application démarre
toute seule **dans la fenêtre Terminal déjà ouverte**, et votre navigateur s'ouvre
sur **http://localhost:8080/** 🚀

Pour l'utiliser à nouveau plus tard, **double-cliquez sur `start_mac.sh`** (ou, dans
le Terminal, tapez `./start_mac.sh`) — l'installation ne se fait **qu'une seule fois**.

> Pour **arrêter** l'application : fermez la fenêtre Terminal, ou appuyez sur
> `Ctrl + C` dans celle-ci.

Pour **adapter l'application à votre équipe**, c'est identique à Windows :
rendez-vous sur **http://localhost:8080/config** une fois l'appli lancée.

---

## 🆘 En cas de souci

> 📄 **Le réflexe n°1 : le fichier `install/install.log`.**
> À chaque exécution, l'installateur enregistre **tout** ce qui s'affiche à l'écran dans
> ce fichier, situé dans le dossier `install/` (aussi bien sous Windows que sous Mac).
> Il contient en prime le **détail technique** des erreurs, que la fenêtre ne montre pas.
> Même si la fenêtre se ferme, ce fichier reste. C'est lui qu'il faut transmettre pour
> obtenir de l'aide.

| Symptôme | Que faire |
|----------|-----------|
| La fenêtre se ferme trop vite pour lire l'erreur | Ouvrez `install/install.log` : tout y est écrit. |
| Message d'erreur au **téléchargement / installation de PHP** | C'est presque toujours le réseau de l'entreprise (VPN, proxy) qui bloque. Vérifiez votre connexion et relancez. Sous Windows, le script affiche la marche à suivre pour installer PHP à la main ; sous Mac, il s'appuie sur Homebrew. |
| L'application ouvre le navigateur mais affiche **❌ HTTP 500** ou **Connexion à Jira impossible** | Rouvrez le fichier `.env` et vérifiez vos trois valeurs (domaine Jira, e-mail de votre compte Jira, jeton API Jira). Une faute de frappe suffit à bloquer. |
| **« Le port 8080 est déjà utilisé »** | Une autre application (ou une fenêtre déjà ouverte de l'appli) occupe ce port. Fermez la fenêtre noire précédente, puis relancez. |
| Rien ne marche | Contactez la personne qui vous a fourni le projet en lui décrivant le message affiché dans la fenêtre noire. |

> 💡 Astuce : si vous êtes vraiment bloqué, vous pouvez **supprimer** les dossiers
> `php\` (Windows) et `vendor\`, puis relancer l'installateur
> (`install_windows.bat` ou `install_mac.command`) : il repart de zéro proprement.

---

## 🤖 (Optionnel) Assistant IA local

L'application peut, en option, s'appuyer sur une **IA installée localement** sur votre
poste pour enrichir certaines analyses. Cette fonctionnalité est **désactivée par
défaut** et totalement facultative.

> 📄 Procédure détaillée (Windows et MacOS) : voir **[README_INSTALL_LOCAL_LLM.md](README_INSTALL_LOCAL_LLM.md)**.
