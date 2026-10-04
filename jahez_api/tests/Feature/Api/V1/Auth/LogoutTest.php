<?php

use App\Models\User;

it('revokes only the token used for the request', function () {
    $user = User::factory()->create();
    $tokenUsedToLogOut = bearerTokenFor($user);
    $tokenOfAnotherDevice = bearerTokenFor($user);

    $this->withToken($tokenUsedToLogOut)->postJson(route('api.v1.auth.logout'))->assertNoContent();

    forgetResolvedUsers();
    $this->withToken($tokenUsedToLogOut)->getJson(route('api.v1.me'))->assertUnauthorized();
    forgetResolvedUsers();
    $this->withToken($tokenOfAnotherDevice)->getJson(route('api.v1.me'))->assertOk();
});

it('returns 401 when logging out without a token', function () {
    $response = $this->postJson(route('api.v1.auth.logout'));

    $response->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('returns 401 for an unknown token', function () {
    $response = $this->withToken('1|not-a-real-token')->getJson(route('api.v1.me'));

    $response->assertUnauthorized();
});
