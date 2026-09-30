<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RebootController
 *
 * Redémarre le serveur PHP intégré (php -S) sans accès shell au serveur. Nécessaire pour
 * prendre en compte un changement de configuration non rechargeable à chaud : activation
 * d'une extension (ex. Zip), montée de version PHP, modification de php.ini.
 *
 * Le process php.exe ne peut pas se tuer puis se relancer lui-même : il délègue à un script
 * batch (reboot.bat) qui tue php.exe puis rejoue le .bat de démarrage. Ce script est lancé via
 * Start-Process (PowerShell), hors de l'arborescence de php.exe, pour ne PAS hériter du socket
 * d'écoute du serveur : sinon le port resterait occupé après le kill et le nouveau serveur ne
 * pourrait pas le reprendre.
 *
 * L'action est un POST (reboot()) ; un GET n'affiche qu'une page de confirmation (index()).
 * Cela évite qu'un prefetch/preload de la barre d'adresse du navigateur (qui n'émet que des GET)
 * ne déclenche un redémarrage pendant la saisie de « reboot » dans l'URL. Un token optionnel
 * (env REBOOT_TOKEN) protège en plus l'accès.
 */
class RebootController
{
    /**
     * Affiche la page de confirmation (GET). Aucun effet de bord : le redémarrage n'a lieu que
     * sur POST (bouton). Le token éventuel (?token=…) est reporté dans l'action du formulaire
     * afin d'être transmis au POST.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isAuthorized($request)) {
            return $this->protectedResponse($response);
        }

        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $action = $token !== '' ? '/reboot?token=' . rawurlencode($token) : '/reboot';
        $actionAttr = htmlspecialchars($action, ENT_QUOTES);

        $html = <<<HTML
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Redémarrer le serveur</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; min-height: 100vh; margin: 0;
               align-items: center; justify-content: center; background: #0f172a; color: #e2e8f0; }
        .box { text-align: center; }
        .box h1 { font-size: 1.25rem; font-weight: 600; }
        .box p { color: #94a3b8; }
        .box button { margin-top: 1rem; padding: .6rem 1.4rem; font-size: 1rem; cursor: pointer;
                      color: #fff; background: #dc2626; border: 0; border-radius: 6px; }
        .box button:hover { background: #b91c1c; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Redémarrer le serveur OPALE ?</h1>
        <p>Le serveur sera coupé quelques secondes, le temps de recharger sa configuration.</p>
        <form method="post" action="$actionAttr">
            <button type="submit">Redémarrer maintenant</button>
        </form>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Déclenche le redémarrage (POST), puis renvoie une page d'attente qui recharge l'accueil
     * dès que le serveur répond de nouveau.
     *
     * Si REBOOT_TOKEN est défini et que le token fourni (?token=…) ne correspond pas, renvoie un
     * 404 pour masquer l'existence de l'endpoint. Le redémarrage est confié à reboot.bat, lancé
     * via Start-Process afin de ne pas hériter du socket d'écoute de php.exe.
     */
    public function reboot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isAuthorized($request)) {
            return $this->protectedResponse($response);
        }

        $batch = dirname(__DIR__, 2) . '\\reboot.bat';
        // Lancement via Start-Process : le nouveau process n'hérite PAS des handles de php.exe
        // (bInheritHandles=FALSE), notamment le socket d'écoute du serveur. Sans cela, reboot.bat
        // garderait le port 8080 ouvert (LISTENING) après le kill et le nouveau serveur ne pourrait
        // pas le reprendre.
        $launch = 'powershell -NoProfile -Command "Start-Process cmd -ArgumentList \'/c\',\'' . $batch . '\'"';
        $process = popen($launch, 'r');
        if ($process !== false) {
            pclose($process);
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Redémarrage du serveur…</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; min-height: 100vh; margin: 0;
               align-items: center; justify-content: center; background: #0f172a; color: #e2e8f0; }
        .box { text-align: center; }
        .box h1 { font-size: 1.25rem; font-weight: 600; }
        .box p { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Redémarrage du serveur en cours…</h1>
        <p>La page d'accueil se rechargera automatiquement dès que le serveur sera de nouveau disponible.</p>
        <p id="hint" style="display:none">Le serveur met plus de temps que prévu. <a href="/">Réessayer maintenant</a>.</p>
    </div>
    <script>
        // Le serveur redémarre : on interroge « / » chaque seconde et on redirige dès qu'il répond.
        // Plus fiable qu'un délai fixe, la durée du reboot (kill + rebind du port) étant variable.
        (function () {
            var attempts = 0;
            function probe() {
                attempts++;
                fetch('/', { method: 'HEAD', cache: 'no-store' })
                    .then(function (r) { if (r.ok) { window.location.href = '/'; } else { retry(); } })
                    .catch(retry);
            }
            function retry() {
                if (attempts > 60) { document.getElementById('hint').style.display = 'block'; return; }
                setTimeout(probe, 1000);
            }
            // Laisse le temps au process courant d'être tué avant la première sonde.
            setTimeout(probe, 2000);
        })();
    </script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Indique si la requête est autorisée : vrai si aucun REBOOT_TOKEN n'est configuré (garde
     * désactivée), ou si le token fourni (?token=…) correspond. Contrôlé sur le GET comme sur le
     * POST pour un comportement cohérent (sans token valide, une page « accès protégé » est
     * affichée). Comparaison via hash_equals pour éviter les attaques temporelles.
     */
    private function isAuthorized(ServerRequestInterface $request): bool
    {
        $expected = (string) ($_ENV['REBOOT_TOKEN'] ?? '');
        $provided = (string) ($request->getQueryParams()['token'] ?? '');

        return $expected === '' || hash_equals($expected, $provided);
    }

    /**
     * Renvoie une page indiquant simplement que l'accès est protégé par un token, sans détailler
     * la configuration (par sécurité). Affichée à la place de la confirmation ou de l'action
     * lorsque le token fourni est absent ou invalide.
     */
    private function protectedResponse(ResponseInterface $response): ResponseInterface
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Accès protégé</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; min-height: 100vh; margin: 0;
               align-items: center; justify-content: center; background: #0f172a; color: #e2e8f0; }
        .box { text-align: center; max-width: 30rem; padding: 0 1.5rem; }
        .box .lock { font-size: 2rem; }
        .box h1 { font-size: 1.25rem; font-weight: 600; }
        .box p { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="box">
        <div class="lock">🔒</div>
        <h1>Accès protégé</h1>
        <p>Cette page est protégée par un token. Un token valide est nécessaire pour y accéder.</p>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response;
    }
}
