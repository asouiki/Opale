#!/bin/bash
# =====================================================================
#  VSM Jira PHP - Lancement de l'application (macOS / Linux)
# ---------------------------------------------------------------------
#  Ne fait QUE demarrer le serveur. L'installation (Homebrew, PHP,
#  dependances) est geree une seule fois par install_mac.command.
# =====================================================================

echo "==================================="
echo "   Lancement appli OPALE"
echo "==================================="

# Récupère le dossier où se trouve le script (équivalent de %~dp0)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

APP_PUBLIC="$SCRIPT_DIR/public"
PHP_BIN="php"

# Garde-fou : si PHP n'est pas la, c'est que l'installation n'a pas été faite.
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "⚠️  PHP est introuvable."
    echo "    Lancez d'abord l'installation : double-cliquez sur install_mac.command"
    read -p "Appuyez sur [Entrée] pour fermer..."
    exit 1
fi

# Garde-fou dépendances : sans elles, le front controller échoue dès sa 1re ligne sur le require()
# de vendor/autoload.php, ce qui laisse un serveur démarré et une page blanche côté navigateur.
# On teste autoload.php et non le dossier vendor/ : une installation interrompue peut laisser un
# vendor/ présent mais incomplet, qui passerait un simple test de dossier.
if [ ! -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    echo "⚠️  Dépendances absentes : vendor/autoload.php est introuvable."
    echo "    Lancez d'abord l'installation : double-cliquez sur install_mac.command"
    read -p "Appuyez sur [Entrée] pour fermer..."
    exit 1
fi

# Ouvre le navigateur par défaut selon l'OS (macOS ou Linux)
if [[ "$OSTYPE" == "darwin"* ]]; then
    open "http://localhost:8080"
else
    xdg-open "http://localhost:8080" 2>/dev/null &
fi

# Lance le serveur PHP
echo "🚀 Démarrage du serveur PHP sur http://localhost:8080..."
"$PHP_BIN" -S localhost:8080 -t "$APP_PUBLIC"

# Équivalent du pause
read -p "Appuyez sur [Entrée] pour continuer..."
