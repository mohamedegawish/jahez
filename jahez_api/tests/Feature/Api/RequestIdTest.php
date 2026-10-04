<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('generates a UUID request ID when the client sends none', function () {
    $response = $this->getJson(route('api.v1.health'));

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue();
});

it('echoes a well-formed client request ID', function () {
    $this->getJson(route('api.v1.health'), ['X-Request-Id' => 'client-trace.0001_abc'])
        ->assertHeader('X-Request-Id', 'client-trace.0001_abc');
});

it('replaces a malformed client request ID', function (string $malformedRequestId) {
    $response = $this->getJson(route('api.v1.health'), ['X-Request-Id' => $malformedRequestId]);

    $returnedRequestId = $response->headers->get('X-Request-Id');

    expect($returnedRequestId)->not->toBe($malformedRequestId)
        ->and(Str::isUuid($returnedRequestId))->toBeTrue();
})->with([
    'too short' => 'abc',
    'too long' => str_repeat('a', 129),
    'markup characters' => '<script>alert(1)</script>',
    'spaces' => 'request id with spaces',
]);

it('shares the request ID with the log context', function () {
    Route::middleware('api')->get('api/v1/__test/context', fn () => ['request_id' => Context::get('request_id')]);

    $response = $this->getJson('/api/v1/__test/context', ['X-Request-Id' => 'correlate-me-123']);

    $response->assertOk()->assertExactJson(['request_id' => 'correlate-me-123']);
});

it('adds the request ID to responses outside the API too', function () {
    $response = $this->get('/up');

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue();
});
