<?php

use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/__test')->group(function () {
        Route::get('users/{user}', fn (User $user) => ['id' => $user->id]);
        Route::post('validation', fn (Request $request) => $request->validate([
            'name' => ['required', 'string', 'max:10'],
        ]));
        Route::post('validation-with-custom-response', fn () => throw new ValidationException(
            Validator::make([], ['name' => ['required']]),
            response()->json(['custom' => 'shape'], 422),
        ));
        Route::get('protected', fn () => 'secret')->middleware('auth:sanctum');
        Route::get('forbidden', fn () => throw new AuthorizationException('You may not view this factory.'));
        Route::get('conflict', fn () => abort(409, 'The offer has already been accepted.'));
        Route::get('crash', fn () => throw new RuntimeException('Database password is hunter2'));
        Route::get('duplicate', fn () => throw new UniqueConstraintViolationException(
            'mysql',
            'insert into `users` (`email`) values (?)',
            ['taken@example.test'],
            new PDOException("SQLSTATE[23000]: Duplicate entry 'taken@example.test' for key 'users_email_unique'"),
        ));
    });
});

it('returns the envelope for an unknown API route even without a JSON Accept header', function () {
    $response = $this->get('/api/v1/route-that-does-not-exist');

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'message' => 'Resource not found.',
            'code' => 'not_found',
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);
});

it('does not reveal the model class when a bound record is missing', function () {
    $response = $this->getJson('/api/v1/__test/users/999999');

    $response->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('code', 'not_found');

    expect($response->getContent())
        ->not->toContain('App\\\\Models')
        ->not->toContain('No query results');
});

it('returns 404 for a record id with trailing characters instead of the record it starts with', function (string $resource, string $modelClass) {
    $admin = User::factory()->imcAdmin()->create();
    $record = $modelClass === User::class ? $admin : $modelClass::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->getJson("/api/v1/{$resource}/{$record->getKey()}abc");

    $response->assertNotFound()->assertJsonPath('code', 'not_found');
})->with([
    'user' => ['users', User::class],
    'factory' => ['factories', Factory::class],
    'service provider' => ['service-providers', ServiceProvider::class],
]);

it('returns 405 with the Allow header and a fixed message', function () {
    $response = $this->postJson(route('api.v1.health'));

    $response->assertMethodNotAllowed()
        ->assertJsonPath('message', 'Method not allowed.')
        ->assertJsonPath('code', 'method_not_allowed');

    expect($response->headers->get('Allow'))->toContain('GET');
    expect($response->getContent())->not->toContain('api/v1/health');
});

it('returns field errors for invalid input', function () {
    $response = $this->postJson('/api/v1/__test/validation', []);

    $response->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('message', 'The name field is required.')
        ->assertJsonPath('errors.name.0', 'The name field is required.');
});

it('keeps a custom response attached to a validation exception', function () {
    $this->postJson('/api/v1/__test/validation-with-custom-response')
        ->assertUnprocessable()
        ->assertExactJson(['custom' => 'shape']);
});

it('returns 401 for a protected route without credentials', function () {
    $response = $this->getJson('/api/v1/__test/protected');

    $response->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
            'code' => 'unauthenticated',
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);
});

it('returns 403 and keeps the authorization message', function () {
    $this->getJson('/api/v1/__test/forbidden')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden')
        ->assertJsonPath('message', 'You may not view this factory.');
});

it('keeps an application-supplied message for other client errors', function () {
    $this->getJson('/api/v1/__test/conflict')
        ->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('message', 'The offer has already been accepted.');
});

it('returns 409 without SQL details when a unique key is violated', function () {
    Exceptions::fake();
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/__test/duplicate');

    $response->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('message', 'The request conflicts with an existing record.');
    expect($response->getContent())
        ->not->toContain('taken@example.test')
        ->not->toContain('insert into');
    Exceptions::assertReported(UniqueConstraintViolationException::class);
});

it('hides exception details on server errors when debug is off', function () {
    Exceptions::fake();
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/__test/crash');

    $response->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Server error.',
            'code' => 'server_error',
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);

    expect($response->getContent())
        ->not->toContain('hunter2')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');

    Exceptions::assertReported(RuntimeException::class);
});

it('includes debug details on server errors only when debug is on', function () {
    Exceptions::fake();
    config(['app.debug' => true]);

    $this->getJson('/api/v1/__test/crash')
        ->assertInternalServerError()
        ->assertJsonPath('code', 'server_error')
        ->assertJsonPath('debug.exception', RuntimeException::class)
        ->assertJsonPath('debug.message', 'Database password is hunter2');
});

it('leaves web routes to the default HTML error pages', function () {
    $response = $this->get('/web-route-that-does-not-exist');

    $response->assertNotFound();

    expect($response->headers->get('Content-Type'))->toContain('text/html');
});
