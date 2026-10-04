<?php

use App\Jobs\SendAccountInvitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('emails the invitee a working set-password link', function () {
    $invitee = User::factory()->create();
    Notification::fake();

    (new SendAccountInvitation($invitee))->handle();

    Notification::assertSentTo(
        $invitee,
        AccountInvitation::class,
        fn (AccountInvitation $invitation): bool => Password::broker()->tokenExists($invitee, $invitation->token),
    );
});

it('sends nothing when the account was deactivated before the job ran', function () {
    $invitee = User::factory()->deactivated()->create();
    Notification::fake();

    (new SendAccountInvitation($invitee))->handle();

    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('serializes only the account identifier, never a token', function () {
    $invitee = User::factory()->create();

    $payload = serialize(new SendAccountInvitation($invitee));

    expect($payload)->toContain(User::class)
        ->not->toContain($invitee->password);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});
