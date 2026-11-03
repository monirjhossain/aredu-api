<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_health_endpoint_returns_the_api_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'API is healthy.')
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_laravel_health_url_still_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_unknown_api_route_returns_the_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_protected_route_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_frontend_origin_is_allowed_by_cors(): void
    {
        $origin = config('cors.allowed_origins.0');

        $this->assertNotSame('*', $origin);
        $this->assertNotEmpty($origin);

        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', $origin);
    }
}
