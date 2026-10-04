<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

it('reports ready when the database is reachable', function () {
    $response = $this->getJson(route('api.v1.health'));

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
                'checks' => ['database' => 'ok'],
            ],
        ]);
});

it('reports unavailable without leaking details when the database is unreachable', function () {
    Exceptions::fake();

    // Run one query first so the lazy database refresh happens on the real test
    // schema, then make a connection to a schema that does not exist the default.
    // The real connection is left untouched so the test transaction can roll back.
    DB::select('select 1');
    $realDefaultConnection = config('database.default');
    config([
        'database.connections.unreachable' => [
            ...config("database.connections.{$realDefaultConnection}"),
            'database' => 'jahez_schema_that_does_not_exist',
        ],
        'database.default' => 'unreachable',
    ]);

    $response = $this->getJson(route('api.v1.health'));

    config(['database.default' => $realDefaultConnection]);

    $response->assertServiceUnavailable()
        ->assertExactJson([
            'data' => [
                'status' => 'unavailable',
                'checks' => ['database' => 'failed'],
            ],
        ]);

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('jahez_schema_that_does_not_exist');

    Exceptions::assertReported(QueryException::class);
});

it('is not rate limited so probes cannot exhaust it', function () {
    config(['api.rate_limit_per_minute' => 1]);

    foreach (range(1, 3) as $attempt) {
        $this->getJson(route('api.v1.health'))->assertOk();
    }
});
