# Installation d'un LLM Local (Ollama)

Ce document explique comment installer et configurer un modèle de langage local sur votre poste pour activer la synthèse IA dans OPALE.

## Sommaire

1. [Pourquoi un LLM local ?](#pourquoi-un-llm-local-)
2. [Prérequis](#prérequis)
   - [Hardware recommandé](#hardware-minimum-recommandé)
   - [Choisir un modèle selon votre GPU](#choisir-un-modèle-selon-votre-gpu)
3. [Windows](#windows)
4. [macOS](#macos)
5. [Autres modèles disponibles](#autres-modèles-disponibles)
6. [Troubleshooting](#troubleshooting)

## Pourquoi un LLM local ?

- ✅ **Souverain** : vos données ne quittent jamais votre infrastructure
- ✅ **Gratuit** : aucun abonnement ni frais par appel
- ✅ **Rapide** : une fois installé, pas de latence réseau
- ✅ **Optionnel** : l'app fonctionne parfaitement sans lui

## Prérequis

### Hardware minimum recommandé
- **RAM** : 16 Go minimum (32 Go = meilleure expérience)
- **Disque** : 12 Go d'espace libre (pour le modèle + système)
- **Processeur** : tout processeur moderne suffit (CPU ou GPU)
- **GPU** (recommandé) : NVIDIA (CUDA), AMD (ROCm), ou Apple Silicon utiliseront le GPU ; sans GPU c'est plus lent mais fonctionne

### Choisir un modèle selon votre GPU

| Votre config | GPU | Modèle recommandé | Taille | Commande |
|---|---|---|---|---|
| **Petite** | Pas de GPU, ou GPU <8 Go | Qwen2.5 3B | ~2 Go | `ollama pull qwen2.5:3b-instruct` |
| **Intermédiaire** | GPU 8–12 Go | Qwen2.5 7B | ~5 Go | `ollama pull qwen2.5:7b-instruct` |
| **Bonne config** | GPU 16+ Go | **Qwen2.5 14B** | ~9 Go | `ollama pull qwen2.5:14b` |

Le modèle **Qwen2.5 14B est fortement recommandé** pour les meilleures analyses IA. Si votre machine a des ressources limitées, commencez par le 7B.

---

## Windows

### Étape 1 : Télécharger et installer Ollama

1. Accédez à [https://ollama.com/download](https://ollama.com/download)
2. Cliquez sur **« Download for Windows »** (installeur `.exe`)
3. Exécutez l'installeur et suivez l'assistant
   - Les emplacements par défaut conviennent
   - L'installation ajoute Ollama au PATH et en tant que service Windows
4. À la fin de l'installation, **redémarrez votre machine** ou redémarrez le service Ollama

### Étape 2 : Vérifier l'installation

Ouvrez une **invite de commande PowerShell** et tapez :
```bash
ollama --version
```

Vous devriez voir la version d'Ollama affichée (ex : `ollama version 0.1.25`).

### Étape 3 : Télécharger un modèle

Toujours dans PowerShell, tapez :
```bash
ollama pull qwen2.5:14b
```

Cela télécharge le modèle Qwen2.5 14B (~9 Go). La barre de progression s'affiche. À la fin :
```
pulling 123abc... 100%
verifying sha256 digest
writing manifest
success
```

### Étape 4 : Tester le modèle

Toujours dans PowerShell :
```bash
ollama run qwen2.5:14b
```

Vous entrez dans un mini-chat. Tapez une question, par exemple :
```
>>> Hello, what's your name?
```

Le modèle devrait répondre (peut prendre quelques secondes au premier appel). Quittez avec `Ctrl+D` ou `Ctrl+C`.

### Étape 5 : Vérifier que l'API répond

Ouvrez une nouvelle PowerShell et tapez :
```bash
curl http://localhost:11434/v1/models
```

Vous devriez voir un JSON listant vos modèles :
```json
{"models": [{"name": "qwen2.5:14b", "modified_at": "..."}]}
```

### Étape 6 : Configurer OPALE

Éditez le fichier `.env` à la racine du projet :
```ini
LLM_ENABLED  = true
LLM_BASE_URL = http://localhost:11434/v1
LLM_API_KEY  = ollama
LLM_MODEL    = qwen2.5:14b
```

Redémarrez votre serveur PHP. Les cartes « Synthèse IA » doivent apparaître sur les pages Sprint et Métriques d'équipe.

---

## macOS

### Étape 1 : Télécharger et installer Ollama

1. Accédez à [https://ollama.com/download](https://ollama.com/download)
2. Cliquez sur **« Download for macOS »**
3. Ouvrez le fichier `.dmg` téléchargé
4. Glissez-déposez l'icône Ollama dans le dossier **Applications**
5. Attendez la fin de la copie, puis **éjectez le disque virtuel**

### Étape 2 : Lancer Ollama

Deux options :

**Option A : Lancer Ollama une fois (à faire à chaque démarrage)**
- Ouvrez **Applications** → **Ollama** et double-cliquez
- Ou : ouvrez **Spotlight** (`Cmd+Space`), tapez `Ollama`, et appuyez sur Entrée
- Une fenêtre de terminal s'ouvre ; Ollama tourne en arrière-plan
- Gardez cette fenêtre ouverte pendant l'utilisation d'OPALE

**Option B : Activer Ollama au démarrage automatique** (recommandé)
- Lancez Ollama une fois (Option A)
- Dans le menu **Ollama** (coin supérieur droit), cliquez sur **Preferences** → **General**
- Cochez **« Start Ollama on login »**
- À partir de maintenant, Ollama démarre automatiquement

### Étape 3 : Vérifier l'installation

Ouvrez **Terminal** (`Applications` → `Utilities` → `Terminal`, ou `Cmd+Space` → `Terminal`) et tapez :
```bash
ollama --version
```

Vous devriez voir la version d'Ollama.

### Étape 4 : Télécharger un modèle

Dans le même Terminal :
```bash
ollama pull qwen2.5:14b
```

Cela télécharge le modèle (~9 Go). La barre de progression s'affiche. À la fin :
```
pulling 123abc... 100%
verifying sha256 digest
writing manifest
success
```

### Étape 5 : Tester le modèle

Dans Terminal :
```bash
ollama run qwen2.5:14b
```

Vous entrez dans un mini-chat. Tapez une question :
```
>>> Hello, what's your name?
```

Le modèle devrait répondre. Quittez avec `Ctrl+D` ou `Ctrl+C`.

### Étape 6 : Vérifier que l'API répond

Ouvrez un **nouveau Terminal** et tapez :
```bash
curl http://localhost:11434/v1/models
```

Vous devriez voir un JSON listant vos modèles.

### Étape 7 : Configurer OPALE

Éditez le fichier `.env` à la racine du projet :
```ini
LLM_ENABLED  = true
LLM_BASE_URL = http://localhost:11434/v1
LLM_API_KEY  = ollama
LLM_MODEL    = qwen2.5:14b
```

Redémarrez votre serveur PHP. Les cartes « Synthèse IA » doivent apparaître.

---

## Autres modèles disponibles

Si vous avez une configuration différente, voici d'autres options :

### Pour petite config (pas de GPU, ou GPU <8 Go)
```bash
ollama pull qwen2.5:3b-instruct    # ~2 Go, plus rapide mais moins précis
```

### Pour config intermédiaire (GPU 8–12 Go)
```bash
ollama pull qwen2.5:7b-instruct    # ~5 Go, bon compromis qualité/vitesse
ollama pull llama3.1:8b            # ~5 Go, bonne alternative Llama
```

### Pour bonne config (GPU 16+ Go)
```bash
ollama pull qwen2.5:14b            # ~9 Go, **recommandé**, meilleure qualité
```

Après le téléchargement, mettez à jour `.env` avec le nouveau nom de modèle :
```ini
LLM_MODEL = qwen2.5:7b-instruct
```

> **Conseil** : commencez toujours avec le modèle recommandé pour votre config. Si l'app met trop longtemps à répondre, descendez d'une taille.

---

## Troubleshooting

### « Connection refused » ou erreur réseau

**Vérifier :** Ollama tourne-t-il ?

- **Windows** : Ouvrez le **Gestionnaire des tâches** → onglet **Services** → cherchez `Ollama`
  - Si absent ou arrêté : redémarrez le service
  
- **macOS** : Vérifiez qu'une fenêtre de Terminal Ollama est ouverte
  - Si absent : relancez Ollama depuis Applications

Testez avec :
```bash
curl http://localhost:11434/v1/models
```

### Le modèle est très lent

C'est normal au premier appel (warm-up). Les appels suivants sont plus rapides.

Si c'est chroniquement lent (>60s par réponse) :
- Vérifiez que votre machine a assez de RAM libre
- Envisagez un modèle plus petit (7B au lieu de 14B)

### La synthèse IA n'apparaît pas dans OPALE

1. Vérifiez que `LLM_ENABLED = true` dans `.env`
2. Vérifiez les valeurs `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL`
3. Testez manuellement :
   ```bash
   curl http://localhost:11434/v1/models
   ```
4. Vérifiez les logs du serveur PHP pour les erreurs de connexion
5. Redémarrez le serveur PHP après chaque modification du `.env`

### Arrêter Ollama

- **Windows** : Ouvrez le **Gestionnaire des tâches** → onglet **Services** → clic droit sur `Ollama` → **Stop**
- **macOS** : Fermez la fenêtre de Terminal Ollama, ou clic sur le menu **Ollama** (coin supérieur droit) → **Quit Ollama**

---

## Questions ?

Consultez la documentation officielle : [https://ollama.com/docs](https://ollama.com/docs)

Vous n'êtes pas obligé d'installer un LLM local. OPALE fonctionne parfaitement sans lui — il vous suffit de laisser `LLM_ENABLED = false` dans `.env`.
