<?php

namespace App\Controller;

use App\Model\Version;
use App\Service\ReleaseMetricsCacheService;
use App\View\VersionView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page d'analyse d'une Release / Version Jira : affichage, deep-link GET et traitement du formulaire.
 */
class VersionController
{
    /**
     * Affiche la page Version et lance l'analyse directement si un identifiant est fourni en GET.
     *
     * Supporte le deep-link /release?releaseOrVersionId=ABC-123 ; sans paramètre, la page est vierge.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $versionId  = trim($params['releaseOrVersionId'] ?? '');
        $projectKey = trim($params['projectKey'] ?? '');

        $view   = null;
        $error  = null;
        $notice   = null;
        $cachedAt = null;
        // Alimente le champ texte (valeur saisie) et pré-sélectionne le projet de la Release dans le
        // sélecteur (deep-link « Analyse » depuis /team-metrics), pour un formulaire cohérent.
        $data   = ['releaseOrVersionId' => $versionId, 'projectKey' => $projectKey];

        // Deep-link GET : lance directement l'analyse si un identifiant est fourni en query,
        // sinon la page reste vierge.
        if ($versionId !== '') {
            [$view, $error, $notice, $cachedAt] = $this->analyze($versionId, $projectKey);
        }

        ob_start();
        require __DIR__ . '/../../views/pages/version.phtml';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Analyse une Release ou Version et distingue erreur métier / erreur technique.
     *
     * Logique partagée par index() (deep-link GET) et process() (formulaire POST).
     *
     * @param  string $versionId  ID numérique fixVersion, Key Release (ABC-123) ou nom exact
     * @param  string $projectHint Clé du projet porteur, pour résoudre une Key hors watched_projects
     * @return array{0: ?VersionView, 1: ?string, 2: ?string, 3: ?int}
     *         [view, erreur technique, notice métier, horodatage de mise en cache si servi du cache]
     */
    private function analyze(string $versionId, string $projectHint = ''): array
    {
        $view     = null;
        $error    = null;
        $notice   = null;
        $cachedAt = null;

        $cache       = new ReleaseMetricsCacheService();
        $refresh     = !empty($_POST['refresh']);
        [$type, $id] = $this->cacheIdentity($versionId);

        try {
            // Cache partagé avec l'onglet Releases de /team-metrics : réutilisé quand la saisie est
            // directement une Key Release ou un ID fixVersion, et hors « rafraîchir ».
            $version = (!$refresh && $type !== null) ? $cache->get($type, $id) : null;

            // Renseigné UNIQUEMENT sur un service depuis le cache : c'est ce qui déclenche le bandeau.
            // Une entité servie du cache implique $type non nul (cf. condition ci-dessus).
            if ($version !== null) {
                $cachedAt = $cache->getCachedAt((string) $type, $id);
            }

            if ($version === null) {
                $version = new Version($versionId, $projectHint);
                // Mémorise la projection sous l'identité résolue (gère aussi les saisies par nom).
                $cache->put(
                    $version->isRelease() ? 'release' : 'version',
                    (string) $version->getId(),
                    $version->isReleased(),
                    $version
                );
                $cache->save();
            }

            $view = new VersionView($version);
        } catch (\DomainException $e) {
            // Cas métier attendu : notice informative, pas une erreur bloquante.
            $notice = $e->getMessage();
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            // Erreur technique : credentials KO, cURL, réponse API invalide...
            $error = $e->getMessage();
        }

        return [$view, $error, $notice, $cachedAt];
    }

    /**
     * Déduit l'identité de cache (type, id) d'une saisie : Key Release, ID fixVersion, ou indéterminée
     * (saisie par nom → [null, ...], résolue uniquement en live).
     *
     * Reflète la détection de Version.
     *
     * @return array{0: ?string, 1: string}
     */
    private function cacheIdentity(string $input): array
    {
        $id = trim($input);
        if (preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $id)) {
            return ['release', $id];
        }
        if (is_numeric($id)) {
            return ['version', $id];
        }

        return [null, $id];
    }



    /**
     * Traite la soumission du formulaire d'analyse d'une Release / Version.
     *
     * L'identifiant releaseOrVersionId est obligatoire. L'analyse distingue deux familles d'échec :
     * une \DomainException devient une notice informative (version introuvable ou ambiguë), tout
     * autre \Throwable devient une erreur technique (credentials, cURL, réponse API invalide...).
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // getParsedBody() peut renvoyer un objet (corps JSON) ou null : on normalise en tableau.
        $data       = (array) ($request->getParsedBody() ?? []);
        $versionId  = trim((string) ($data['releaseOrVersionId'] ?? ''));
        $projectKey = trim((string) ($data['projectKey'] ?? ''));

        $view   = null;
        $error  = null;
        $notice   = null;
        $cachedAt = null;

        if ($versionId === '') {
            $error = 'Le paramètre releaseOrVersionId est requis.';
        } else {
            [$view, $error, $notice, $cachedAt] = $this->analyze($versionId, $projectKey);
        }

        ob_start();
        require __DIR__ . '/../../views/pages/version.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
