<?php

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

it('names the missing SMTP settings without printing any configured value', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.gmail.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'sender@example.org',
        'mail.mailers.smtp.password' => null,
        'mail.from.address' => 'sender@example.org',
    ]);

    $this->artisan('app:mail-check', ['address' => 'check@example.test', '--dry-run' => true])
        ->expectsOutputToContain('MAIL_PASSWORD')
        ->doesntExpectOutputToContain('sender@example.org')
        ->assertFailed();
});

it('lists what switching from the log transport to SMTP still needs, by name only', function () {
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 2525,
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.scheme' => null,
        'mail.from.address' => 'hello@example.com',
    ]);

    $this->artisan('app:mail-check', ['address' => 'check@example.test', '--dry-run' => true])
        ->expectsOutputToContain('MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD, MAIL_SCHEME')
        ->expectsOutputToContain('MAIL_FROM_ADDRESS')
        ->expectsOutputToContain('no: messages stay on this server')
        ->assertFailed();
});

it('reports a configuration with nothing missing', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.gmail.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'sender@example.org',
        'mail.mailers.smtp.password' => 'app-password-value',
        'mail.from.address' => 'sender@example.org',
    ]);

    $this->artisan('app:mail-check', ['address' => 'check@example.test', '--dry-run' => true])
        ->doesntExpectOutputToContain('app-password-value')
        ->assertSuccessful();
});

it('sends one message and fails with the transport error when the transport refuses it', function () {
    Mail::extend('refusing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('535 Authentication failed');
        }

        public function __toString(): string
        {
            return 'refusing';
        }
    });
    config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing', 'mail.from.address' => 'sender@example.org']);

    $this->artisan('app:mail-check', ['address' => 'check@example.test'])
        ->expectsOutputToContain('535 Authentication failed')
        ->assertFailed();
});

it('accepts a message through the array transport and says nothing was delivered', function () {
    config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.org']);

    $this->artisan('app:mail-check', ['address' => 'check@example.test'])
        ->expectsOutputToContain('nothing was delivered')
        ->assertSuccessful();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('refuses an invalid address', function () {
    $this->artisan('app:mail-check', ['address' => 'not-an-address'])->assertExitCode(2);
});
