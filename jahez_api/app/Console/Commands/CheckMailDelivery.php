<?php

namespace App\Console\Commands;

use App\Enums\NotificationEvent;
use App\Notifications\PlatformEventMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Mail delivery check (ADR-021): reports which transport is configured and which of its
 * settings are missing, by name only (never a value), then sends one platform email to
 * the given address synchronously, outside the queue, and reports whether the transport
 * accepted it. Acceptance by the transport is not proof of delivery to the inbox: check
 * the mailbox (and spam folder) of the address.
 */
class CheckMailDelivery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mail-check {address : The test mailbox to send to} {--dry-run : Only report the configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report the mail configuration and send one test email through it';

    /**
     * Settings an SMTP transport needs, by environment variable and config key.
     */
    private const SMTP_SETTINGS = [
        'MAIL_HOST' => 'host',
        'MAIL_PORT' => 'port',
        'MAIL_USERNAME' => 'username',
        'MAIL_PASSWORD' => 'password',
    ];

    public function handle(): int
    {
        $address = (string) $this->argument('address');
        if (Validator::make(['address' => $address], ['address' => ['required', 'email:rfc']])->fails()) {
            $this->components->error('The address is not a valid email address.');

            return self::INVALID;
        }

        $mailer = Config::string('mail.default');
        $transport = (string) Config::get("mail.mailers.{$mailer}.transport", $mailer);
        $from = (string) Config::get('mail.from.address', '');
        $missing = [];

        if ($transport === 'smtp') {
            foreach (self::SMTP_SETTINGS as $variable => $key) {
                $value = Config::get("mail.mailers.{$mailer}.{$key}");
                if ($value === null || $value === '') {
                    $missing[] = $variable;
                }
            }
        }
        if ($from === '' || str_ends_with($from, '@example.com')) {
            $missing[] = 'MAIL_FROM_ADDRESS';
        }

        // What switching to SMTP (Gmail) would still need, when another transport is set.
        $smtpMissing = [];
        if ($transport !== 'smtp') {
            foreach (self::SMTP_SETTINGS as $variable => $key) {
                $value = Config::get("mail.mailers.smtp.{$key}");
                if ($value === null || $value === '' || ($key === 'host' && in_array($value, ['127.0.0.1', 'localhost'], true))) {
                    $smtpMissing[] = $variable;
                }
            }
            $scheme = Config::get('mail.mailers.smtp.scheme');
            if ($scheme === null || $scheme === '') {
                $smtpMissing[] = 'MAIL_SCHEME';
            }
        }

        $this->table(['Setting', 'State'], [
            ['Mailer (MAIL_MAILER)', $mailer],
            ['Transport', $transport],
            ['Delivers real email', in_array($transport, ['log', 'array'], true) ? 'no: messages stay on this server' : 'yes, if the provider accepts them'],
            ['Missing settings', $missing === [] ? 'none' : implode(', ', $missing)],
            ...($transport !== 'smtp' ? [['Still needed to switch to SMTP (MAIL_MAILER=smtp)', $smtpMissing === [] ? 'none' : implode(', ', $smtpMissing)]] : []),
            ['Notification emails (JAHEZ_NOTIFICATION_EMAILS)', (bool) Config::get('jahez.notifications.mail', true) ? 'on' : 'off'],
            ['Queue for notification emails', Config::string('queue.default')],
        ]);

        if ($this->option('dry-run')) {
            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        try {
            Notification::route('mail', $address)->notifyNow(new PlatformEventMail(
                NotificationEvent::OrganizationRegistered,
                'رسالة اختبار من منصة جاهز للتحقق من إعدادات البريد. لا يلزم أي إجراء.',
                null,
            ));
        } catch (Throwable $exception) {
            // The class and message only: transport messages do not include the password.
            $this->components->error('The transport refused the message: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(in_array($transport, ['log', 'array'], true)
            ? "The message was built and handed to the {$transport} transport; nothing was delivered."
            : "The {$transport} transport accepted the message for {$address}. Confirm it arrived in that mailbox.");

        return self::SUCCESS;
    }
}
