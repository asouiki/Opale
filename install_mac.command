#!/bin/bash
# =====================================================================
#  VSM Jira PHP - Installateur macOS (lanceur)
# ---------------------------------------------------------------------
#  Fichier .command : double-cliquable depuis le Finder (s'ouvre dans
#  Terminal). Toute la logique est dans install/install_mac.sh ; ce
#  lanceur ne fait que deux choses : garder la fenetre lisible en cas
#  d'echec, et enchainer sur start_mac.sh quand l'installation reussit.
#  Cet enchainement se fait par exec, donc CETTE fenetre devient celle
#  du serveur : le message d'attente final ne sert plus qu'a l'echec.
#
#  Si le double-clic echoue avec "autorisation refusee", rendez les
#  scripts executables une fois :  chmod +x install_mac.command install/install_mac.sh
# =====================================================================

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$DIR/install/install_mac.sh"

if [ ! -f "$SCRIPT" ]; then
    echo "ERREUR : fichier introuvable :"
    echo "  $SCRIPT"
    echo "Le dossier 'install' doit se trouver a cote de ce fichier."
    read -r -p "Appuyez sur [Entree] pour fermer..."
    exit 1
fi

bash "$SCRIPT"
STATUS=$?

echo
if [ "$STATUS" -ne 0 ]; then
    echo "Installation en echec. Consultez install/install.log"
    read -r -p "Appuyez sur [Entree] pour fermer cette fenetre..."
    exit "$STATUS"
fi

# Installation reussie : on enchaine sur le lancement, DANS CETTE FENETRE via exec.
# Pas de nouvelle fenetre ici (contrairement a Windows) : ouvrir un .sh depuis le Finder depend de
# l'association de fichiers de l'utilisateur, qui peut l'ouvrir dans un editeur au lieu de l'executer.
# exec remplace ce processus : start_mac.sh porte deja son propre message d'attente en fin de script.
# Le lancement est inconditionnel, y compris sur un .env fraichement cree depuis le template :
# l'application affiche alors sa page d'erreur dediee, qui renvoie explicitement vers le .env.
echo "Lancement de l application..."
exec bash "$DIR/start_mac.sh"
