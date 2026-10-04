<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * The query parameters of a pagination link.
 *
 * @return array<string, mixed>
 */
function paginationLinkQuery(?string $link): array
{
    parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

    return $query;
}

it('keeps the page size in the next-page link of offset-paginated lists', function (string $routeName, Closure $createRecords) {
    $admin = User::factory()->imcAdmin()->create();
    $createRecords();
    Sanctum::actingAs($admin);

    $response = $this->getJson(route($routeName, ['per_page' => 1]));

    expect(paginationLinkQuery($response->json('links.next')))->toMatchArray(['per_page' => '1', 'page' => '2']);
})->with([
    'factories' => ['api.v1.factories.index', fn () => Factory::factory()->count(2)->create()],
    'service providers' => ['api.v1.service-providers.index', fn () => ServiceProvider::factory()->count(2)->create()],
    'users' => ['api.v1.users.index', fn () => User::factory()->count(2)->create()],
]);

it('keeps the filters and page size in the next-page link of the audit log', function () {
    $admin = User::factory()->imcAdmin()->create();
    AuditLog::record(AuditEvent::LoggedOut, $admin);
    AuditLog::record(AuditEvent::LoggedOut, $admin);
    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.audit-logs.index', ['event' => 'auth.logged_out', 'actor_user_id' => $admin->id, 'per_page' => 1]));

    expect(paginationLinkQuery($response->json('links.next')))
        ->toMatchArray(['event' => 'auth.logged_out', 'actor_user_id' => (string) $admin->id, 'per_page' => '1'])
        ->toHaveKey('cursor');
});
