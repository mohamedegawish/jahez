<?php

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('emails an active account a working reset link', function () {
    $user = User::factory()->create(['email' => 'member@example.test']);
    Notification::fake();

    (new SendPasswordResetLink('member@example.test'))->handle();

    Notification::assertSentTo(
        $user,
        PasswordResetLink::class,
        fn (PasswordResetLink $notification): bool => Password::broker()->tokenExists($user, $notification->token),
    );
});

it('sends a working link when the job is retried after the email could not be delivered', function () {
    $user = User::factory()->create(['email' => 'member@example.test']);
    $deliveryAttempts = 0;
    Event::listen(NotificationSending::class, function () use (&$deliveryAttempts): void {
        if (++$deliveryAttempts === 1) {
            throw new RuntimeException('Mail server unavailable.');
        }
    });
    $sentNotifications = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sentNotifications): void {
        $sentNotifications[] = $event->notification;
    });

    expect(fn () => (new SendPasswordResetLink('member@example.test'))->handle())->toThrow(RuntimeException::class, 'Mail server unavailable.');
    $this->travel(10)->seconds();
    (new SendPasswordResetLink('member@example.test'))->handle();

    expect($sentNotifications)->toHaveCount(1)
        ->and($sentNotifications[0])->toBeInstanceOf(PasswordResetLink::class)
        ->and(Password::broker()->tokenExists($user, $sentNotifications[0]->token))->toBeTrue();
});

it('sends nothing for an unknown or deactivated account', function (string $email) {
    Notification::fake();

    (new SendPasswordResetLink($email))->handle();

    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with([
    'unknown email' => [fn (): string => 'nobody@example.test'],
    'deactivated account' => [fn (): string => User::factory()->deactivated()->create()->email],
]);

it('links the email to the front-end reset page with the token and email', function () {
    config(['api.frontend_url' => 'https://app.jahez.test/']);
    $user = User::factory()->make(['email' => 'member@example.test']);

    $mail = (new PasswordResetLink('reset-token-123'))->toMail($user);

    expect($mail->actionUrl)->toBe('https://app.jahez.test/reset-password?token=reset-token-123&email=member%40example.test');
});
