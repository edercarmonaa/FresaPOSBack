<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Tests\Support\ReceiptsSqlServerTestCase;

class Block05ContractEvidenceTest extends ReceiptsSqlServerTestCase
{
    public function test_every_current_api_operation_has_a_fresh_real_http_boundary_response(): void
    {
        $samples = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $template = substr($route->uri(), strlen('api/v1'));
                $path = preg_replace_callback('/\{([^}]+)\}/', fn (array $match) => match ($match[1]) {
                    'folio' => '202611170001', 'report' => 'summary', 'format' => 'pdf', default => '01j00000000000000000000000'
                }, $template);
                Auth::forgetGuards();
                $response = $this->json($method, '/api/v1'.$path, [], ['X-Request-Id' => 'block05-current-contract']);
                $this->assertContains($response->status(), [200, 400, 401, 403, 404, 422], $method.' '.$template);
                $response->assertHeader('Content-Type', 'application/json');
                $body = $response->json();
                $this->assertIsArray($body);
                $this->assertArrayHasKey('success', $body);
                $samples[] = ['method' => strtolower($method), 'path' => $template, 'status' => $response->status(), 'body' => $body];
            }
        }
        $this->assertCount(74, $samples);
        file_put_contents(base_path('.tools/block05-fresh-route-responses.json'), json_encode($samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
