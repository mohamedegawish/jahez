<?php

it('sends the API security headers on successful responses', function () {
    $response = $this->getJson(route('api.v1.health'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('sends the API security headers on error responses too', function () {
    $response = $this->getJson('/api/v1/route-that-does-not-exist');

    $response->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});

it('sends the baseline headers but no API content policy outside the API', function () {
    $response = $this->get('/up');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderMissing('Content-Security-Policy');
});

it('sends HSTS only over HTTPS', function () {
    $this->getJson('https://localhost/api/v1/health')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    $this->getJson('http://localhost/api/v1/health')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('does not serve or accept files through storage routes', function (string $method) {
    $response = $this->call($method, '/storage/../../.env');

    $response->assertNotFound();
})->with(['GET', 'PUT']);
