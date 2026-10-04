<?php

use Illuminate\Testing\TestResponse;

/**
 * Sends a browser-style CORS preflight request for the health endpoint.
 */
function sendCorsPreflight(string $origin): TestResponse
{
    return test()->call('OPTIONS', route('api.v1.health'), server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
}

it('grants no cross-origin access when no origins are configured', function () {
    config(['cors.allowed_origins' => []]);

    sendCorsPreflight('https://attacker.example')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('allows only configured origins', function () {
    config(['cors.allowed_origins' => ['https://app.jahez.test', 'https://admin.jahez.test']]);

    sendCorsPreflight('https://app.jahez.test')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.jahez.test');

    sendCorsPreflight('https://attacker.example')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('lets browser clients read the request ID header', function () {
    config(['cors.allowed_origins' => ['https://app.jahez.test', 'https://admin.jahez.test']]);

    $response = $this->getJson(route('api.v1.health'), ['Origin' => 'https://app.jahez.test']);

    expect($response->headers->get('Access-Control-Expose-Headers'))->toContain('X-Request-Id');
});
