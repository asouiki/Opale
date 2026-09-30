#!/bin/bash
# =====================================================================
#  VSM Jira PHP - Installateur macOS (standalone)
# ---------------------------------------------------------------------
#  Lance par ../install_mac.command (double-clic dans le Finder). Peut
#  aussi se lancer directement : bash install/install_mac.sh
#
#  Contrairement a Windows (PHP portable en dossier), macOS ne propose
#  pas de binaire PHP portable officiel : on suit l'approche eprouvee de
#  Homebrew (brew install php). PHP est donc installe au niveau systeme.
#
#  Tout l'affichage (y compris la sortie de brew et de composer) est
#  capture dans install/install.log via un pipe vers "tee".
# =====================================================================

set -u

# ---- Chemins ---------------------------------------------------------
# Ce script vit dans install/ : la racine du projet est le dossier parent.
INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$INSTALL_DIR/.." && pwd)"
LOG_FILE="$INSTALL_DIR/install.log"

# Composer doit s'executer depuis la racine : c'est la qu'est composer.json.
cd "$ROOT_DIR"

# Extensions PHP requises (Jira via curl/openssl, import Excel via zip/xml).
REQUIRED_EXTENSIONS="curl openssl mbstring zip xmlreader dom"

# ---- Helpers d'affichage --------------------------------------------
step() { echo; echo "[$1/5] $2"; }
ok()   { echo "   - $1"; }
info() { echo "   - $1"; }

# Interrompt l'installation avec une cause explicite. Le message final
# renvoie systematiquement vers le journal, point d'entree du diagnostic.
fail() {
    echo
    echo "---------------------------------------------------------"
    echo "  INSTALLATION INTERROMPUE"
    echo "  Cause : $1"
    echo
    echo "  Causes frequentes :"
    echo "    - pas de connexion Internet, ou proxy / VPN d'entreprise"
    echo "    - refus des droits lors de l'installation de Homebrew"
    echo
    echo "  Transmettez le fichier install/install.log pour diagnostic."
    echo "---------------------------------------------------------"
    exit 1
}

# =====================================================================
#  Corps de l'installation. Tout ce qu'il ecrit passe par tee (voir
#  l'appel en bas), donc atterrit a la fois a l'ecran et dans le journal.
# =====================================================================
main() {
    echo "==================================================="
    echo "  Installation VSM Jira PHP (macOS)"
    echo "==================================================="
    echo "  Un compte-rendu detaille est ecrit dans install/install.log"

    # -----------------------------------------------------------------
    # 1/5 - Homebrew
    # -----------------------------------------------------------------
    step 1 "Verification de Homebrew"
    if command -v brew >/dev/null 2>&1; then
        ok "Homebrew deja present"
    else
        info "Homebrew absent. Installation..."
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" \
            || fail "Installation de Homebrew impossible."

        # Rend 'brew' utilisable immediatement dans ce shell.
        if [ -x "/opt/homebrew/bin/brew" ]; then
            eval "$(/opt/homebrew/bin/brew shellenv)"
        elif [ -x "/usr/local/bin/brew" ]; then
            eval "$(/usr/local/bin/brew shellenv)"
        fi
        command -v brew >/dev/null 2>&1 || fail "Homebrew installe mais introuvable dans le PATH."
        ok "Homebrew installe"
    fi

    # -----------------------------------------------------------------
    # 2/5 - PHP (via Homebrew)
    # -----------------------------------------------------------------
    step 2 "Verification de PHP"
    if command -v php >/dev/null 2>&1; then
        ok "PHP deja present (version $(php -r 'echo PHP_VERSION;' 2>/dev/null))"
    else
        info "PHP absent. Installation via Homebrew..."
        brew install php || fail "Installation de PHP via Homebrew impossible."
        ok "PHP installe (version $(php -r 'echo PHP_VERSION;' 2>/dev/null))"
    fi

    # Homebrew active deja les extensions courantes : on verifie plutot
    # que de reconfigurer un php.ini (contrairement au PHP portable Windows).
    info "Verification des extensions requises..."
    local missing=""
    for ext in $REQUIRED_EXTENSIONS; do
        php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null || missing="$missing $ext"
    done
    [ -n "$missing" ] && fail "Extensions PHP non chargees :$missing"
    ok "Extensions verifiees comme chargees"

    # -----------------------------------------------------------------
    # 3/5 - Composer + dependances
    # -----------------------------------------------------------------
    step 3 "Installation des dependances (Composer)"
    if command -v composer >/dev/null 2>&1; then
        info "Composer detecte."
        composer install --no-interaction --optimize-autoloader \
            || fail "Composer a echoue. Voir le detail ci-dessus."
    else
        info "Composer absent : utilisation de composer.phar local."
        local phar="$INSTALL_DIR/composer.phar"
        if [ ! -f "$phar" ]; then
            curl -fsSL "https://getcomposer.org/download/latest-stable/composer.phar" -o "$phar" \
                || fail "Telechargement de composer.phar impossible."
        fi
        php "$phar" install --no-interaction --optimize-autoloader \
            || fail "Composer a echoue. Voir le detail ci-dessus."
    fi
    ok "Dependances installees dans ./vendor/"

    # -----------------------------------------------------------------
    # 4/5 - Fichier .env
    # -----------------------------------------------------------------
    step 4 "Configuration (.env)"
    if [ -f "$ROOT_DIR/.env" ]; then
        ok ".env deja present, conserve tel quel"
    elif [ -f "$ROOT_DIR/.env.template" ]; then
        cp "$ROOT_DIR/.env.template" "$ROOT_DIR/.env"
        ok ".env cree a partir de .env.template"
        echo
        echo "   #####################################################"
        echo "   #  A FAIRE : ouvrez .env et renseignez vos acces    #"
        echo "   #  Jira : JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN #"
        echo "   #  Sans cela l'application ne peut pas interroger   #"
        echo "   #  Jira.                                            #"
        echo "   #####################################################"
        echo
        # Prompt et lecture via /dev/tty : le pipe vers tee ne doit ni
        # retarder l'affichage de la question, ni avaler la reponse.
        # On ne propose l'ouverture que si un terminal est reellement
        # disponible (sinon : lancement non interactif, on se contente
        # d'inviter l'utilisateur a editer .env lui-meme).
        if { : > /dev/tty; } 2>/dev/null; then
            printf "    Ouvrir .env maintenant ? [o/N] " > /dev/tty
            local answer=""
            read -r answer < /dev/tty
            case "$answer" in
                [oOyY]*) open -e "$ROOT_DIR/.env" ;;
            esac
        else
            info "Ouvrez .env dans un editeur pour y renseigner vos acces Jira."
        fi
    else
        info "ATTENTION : .env.template introuvable, .env non cree."
    fi

    # -----------------------------------------------------------------
    # 5/5 - Dossiers runtime
    # -----------------------------------------------------------------
    step 5 "Preparation des dossiers runtime"
    mkdir -p "$ROOT_DIR/cache"
    ok "Dossier cache/ pret"

    # -----------------------------------------------------------------
    echo
    echo "==================================================="
    echo "  Installation terminee."
    echo "---------------------------------------------------"
    echo "  1. L'application demarre automatiquement (http://localhost:8080/)"
    echo "     Pour la relancer plus tard : ./start_mac.sh"
    echo "  2. Verifiez vos acces Jira dans le fichier .env"
    echo "     (sans eux, l'application affiche une page d'erreur qui vous y renvoie)"
    echo "  3. Compte-rendu complet : install/install.log"
    echo "==================================================="
}

# =====================================================================
#  Execution : tout passe par tee (console + journal). pipefail +
#  PIPESTATUS permettent de recuperer le vrai code de sortie de main,
#  et non celui de tee (toujours 0).
# =====================================================================
set -o pipefail
main 2>&1 | tee "$LOG_FILE"
exit "${PIPESTATUS[0]}"
