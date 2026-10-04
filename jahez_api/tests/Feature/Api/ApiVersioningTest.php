<?php

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

it('serves every API route under a version prefix', function () {
    $apiRouteUris = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RegisteredRoute $route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/'));

    expect($apiRouteUris)->not->toBeEmpty();

    $apiRouteUris->each(
        fn (string $uri) => expect($uri)->toStartWith('api/v1/')
    );
});

it('constrains the format of every API route parameter', function () {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RegisteredRoute $route): bool => str_starts_with($route->uri(), 'api/'));

    $apiRoutes->each(function (RegisteredRoute $route): void {
        foreach ($route->parameterNames() as $parameter) {
            expect($route->wheres)->toHaveKey($parameter, message: "{$route->uri()} has no format for {{$parameter}}; MySQL would match \"5abc\" to record 5.");
        }
    });
});
