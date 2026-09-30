<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CsrfGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couvre la garde anti-CSRF : seules les écritures signalées cross-site sont refusées.
 *
 * L'enjeu principal des tests est le FAUX POSITIF : bloquer à tort casserait toutes les écritures de
 * l'application (formulaires, sauvegardes de config, mise à jour Jira). Les cas « doit passer » sont
 * donc au moins aussi importants que les cas « doit bloquer ».
 */
class CsrfGuardTest extends TestCase
{
    /**
     * Handler factice : renvoie 200 s'il est atteint, ce qui prouve que la garde a laissé passer.
     */
    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    /**
     * @param array<string, string> $headers En-têtes à positionner sur la requête
     */
    private function dispatch(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://opale.local' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return (new CsrfGuard(new ResponseFactory()))->process($request, $this->handler());
    }

    // =====================================================================
    // Doit BLOQUER
    // =====================================================================

    #[Test]
    public function test_process_postCrossSite_estRefuse(): void
    {
        $response = $this->dispatch('POST', '/sprint', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function test_process_postOrigineEtrangere_estRefuse(): void
    {
        // Navigateur ancien : pas de Sec-Fetch-Site, on retombe sur la comparaison d'Origin.
        $response = $this->dispatch('POST', '/sprint', ['Origin' => 'http://evil.example']);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function test_process_endpointApiRefuse_repondEnJson(): void
    {
        $response = $this->dispatch('POST', '/api/internal/issues/ABC-1/planning', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // =====================================================================
    // Doit PASSER (non-régression : ne jamais casser un usage légitime)
    // =====================================================================

    #[Test]
    public function test_process_postMemeOrigine_passe(): void
    {
        $response = $this->dispatch('POST', '/sprint', ['Sec-Fetch-Site' => 'same-origin']);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_process_postOrigineIdentique_passe(): void
    {
        $response = $this->dispatch('POST', '/sprint', ['Origin' => 'http://opale.local']);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_process_postSansAucunEntete_passe(): void
    {
        // Client non-navigateur (script, curl) : pas un vecteur CSRF, ne doit pas être bloqué.
        $response = $this->dispatch('POST', '/sprint');

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_process_getCrossSite_passe(): void
    {
        // Une lecture n'a pas d'effet de bord : hors périmètre de la garde.
        $response = $this->dispatch('GET', '/sprint', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_process_postDepuisFormulaireDuSite_passe(): void
    {
        // Cas réel d'une soumission de formulaire de l'app : Sec-Fetch-Site vaut « same-origin ».
        $response = $this->dispatch('POST', '/team-metrics', [
            'Sec-Fetch-Site' => 'same-origin',
            'Origin'         => 'http://opale.local',
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }
}
