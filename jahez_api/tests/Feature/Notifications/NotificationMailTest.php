<?php

use App\Enums\NotificationEvent;
use App\Enums\ProviderRequestStatus;
use App\Models\User;
use App\Notifications\PlatformEventMail;
use App\Notifications\PlatformNotifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
| Email copies of notifications (ADR-020, ADR-021), through the test (array) transport:
| what they contain, and how a failing transport is retried and recorded. No real mail is
| sent by these tests.
*/

it('emails the other party without the message text or any price', function () {
    ['factoryMember' => $factoryMember, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);

    Sanctum::actingAs($factoryMember);
    $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Confidential budget is 987654 EGP'])->assertCreated();
    Sanctum::actingAs($providerMembers[0]);
    $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), [
        'based_on_version' => null, 'scope' => 'Secret scope', 'deliverables' => 'Secret deliverables', 'duration_days' => 30,
        'price' => ['amount' => '424242.00', 'currency' => 'EGP'],
    ])->assertCreated();

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $bodies = collect($messages)->map(fn ($sent): string => $sent->getOriginalMessage()->toString())->implode("\n");
    $recipients = collect($messages)->flatMap(fn ($sent) => $sent->getEnvelope()->getRecipients())->map->getAddress()->all();

    expect($messages)->not->toBeEmpty()
        ->and($recipients)->toContain($providerMembers[0]->email, $factoryMember->email)
        ->and($bodies)->not->toContain('987654')
        ->and($bodies)->not->toContain('424242')
        ->and($bodies)->not->toContain('Secret scope')
        ->and($bodies)->not->toContain('Confidential');
});

it('retries a failing email on the database queue, then records it in failed_jobs, keeping the in-app copy', function () {
    Mail::extend('refusing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('421 Service not available');
        }

        public function __toString(): string
        {
            return 'refusing';
        }
    });
    config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing', 'queue.default' => 'database']);
    $user = User::factory()->factoryMember()->create();

    PlatformNotifier::factoryMembers($user->factory_id, NotificationEvent::FactoryApprovalChanged, 'mail-retry-test', 'Body', '/factory/settings');

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1);

    $mail = new PlatformEventMail(NotificationEvent::FactoryApprovalChanged, 'Body', null);
    foreach ([0, ...$mail->backoff] as $wait) {
        $this->travel($wait + 1)->seconds();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    expect($mail->tries)->toBe(3)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('421 Service not available')
        ->and($user->notifications()->count())->toBe(1);
});
