<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page d'analyse d'une Feature Jira : affichage initial et traitement du formulaire de recherche.
 */
class FeatureController
{
    /**
     * Affiche la page Feature et lance l'analyse directement si un identifiant est fourni en GET.
     *
     * Supporte le deep-link /feature?featureId=ABC-123 (lien « Analyse » de l'onglet Features de
     * /team-metrics) ; sans paramètre, la page reste vierge.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $featureId = trim((string) ($request->getQueryParams()['featureId'] ?? ''));

        $view     = null;
        $error    = null;
        $cachedAt = null;

        // Deep-link GET : lance directement l'analyse si un identifiant est fourni en query.
        if ($featureId !== '') {
            [$view, $error, $cachedAt] = $this->analyze($featureId);
        }

        ob_start();
        require __DIR__ . '/../../views/pages/feature.phtml';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Traite la soumission du formulaire : construit la vue de la Feature à partir de l'ID saisi.
     *
     * Le paramètre featureId est obligatoire ; toute erreur (paramètre manquant ou échec de
     * récupération Jira) est capturée et transmise à la vue sous forme de message d'erreur.
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data      = (array) ($request->getParsedBody() ?? []);
        $featureId = trim((string) ($data['featureId'] ?? ''));
        // Bandeau « Rafraîchir depuis Jira » : recalcul forcé, cache ignoré puis réécrit.
        $refresh   = !empty($data['refresh']);

        $view     = null;
        $error    = null;
        $cachedAt = null;

        if ($featureId === '') {
            $error = 'Le paramètre featureId est requis.';
        } else {
            [$view, $error, $cachedAt] = $this->analyze($featureId, $refresh);
        }

        ob_start();
        require __DIR__ . '/../../views/pages/feature.phtml';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Construit la vue d'une Feature (cache-first), partagée par index() (deep-link GET) et
     * process() (formulaire POST).
     *
     * @param  string $featureId ID ou Key de la Feature Jira (ex: ABC-123)
     * @param  bool   $refresh   Ignore le cache et recalcule depuis Jira (bandeau « Rafraîchir »)
     * @return array{0: ?\App\View\FeatureView, 1: ?string, 2: ?int}
     *         [view, message d'erreur, horodatage de mise en cache si servi du cache]
     * @throws \App\Exception\JiraCredentialsException Identifiants Jira invalides (page d'erreur dédiée)
     */
    private function analyze(string $featureId, bool $refresh = false): array
    {
        try {
            // Cache partagé (avec l'onglet Features de /team-metrics) : hydratation depuis la projection
            // si disponible (Done = permanent, en cours = TTL), sinon calcul live puis mise en cache.
            $cache    = new \App\Service\FeatureMetricsCacheService();
            $feature  = $refresh ? null : $cache->get($featureId);
            // Renseigné UNIQUEMENT sur un service depuis le cache : c'est ce qui déclenche le bandeau.
            $cachedAt = $feature !== null ? $cache->getCachedAt($featureId) : null;

            if ($feature === null) {
                $feature = new \App\Model\Feature($featureId);
                $cache->put($featureId, $feature->isDone(), $feature);
                // put() mémorise sans écrire (l'onglet Features persiste en un seul save() pour N Features) :
                // cette page n'en traite qu'une, c'est donc à elle de déclencher l'écriture.
                $cache->save();
            }

            return [new \App\View\FeatureView($feature), null, $cachedAt];
        } catch (\App\Exception\JiraCredentialsException $e) {
            // Identifiants Jira KO : laisser remonter au gestionnaire global (page d'erreur dédiée).
            throw $e;
        } catch (\Throwable $e) {
            return [null, $e->getMessage(), null];
        }
    }
}
