<?php

use App\Enums\FactoryApprovalStatus;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Agreement;
use App\Models\AgreementReview;
use App\Models\Contract;
use App\Models\Factory;
use App\Models\FactoryProfileChangeRequest;
use App\Models\FinancialPolicy;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\ProviderProfileChangeRequest;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\PublicAnnouncement;
use App\Models\ReadinessAssessment;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * The demo dataset (ADR-024): explicit, local or testing only, idempotent and built
 * through the same rules as real activity.
 */

/**
 * Counts that must not change when the seeder runs again.
 *
 * @return array<string, int>
 */
function demoCounts(): array
{
    return [
        'users' => User::query()->where('email', 'like', '%@'.DemoDataSeeder::EMAIL_DOMAIN)->count(),
        'factories' => Factory::query()->count(),
        'providers' => ServiceProvider::query()->count(),
        'listings' => DB::table('catalog_service_service_provider')->count(),
        'assessments' => ReadinessAssessment::query()->count(),
        'answers' => DB::table('readiness_assessment_answers')->count(),
        'requests' => ServiceRequest::query()->count(),
        'threads' => ProviderRequest::query()->count(),
        'messages' => ProviderRequestMessage::query()->count(),
        'offers' => Offer::query()->count(),
        'agreements' => Agreement::query()->count(),
        'reviews' => AgreementReview::query()->count(),
        'promotions' => ServicePromotion::query()->count(),
        'announcements' => PublicAnnouncement::query()->count(),
        'factory_changes' => FactoryProfileChangeRequest::query()->count(),
        'provider_changes' => ProviderProfileChangeRequest::query()->count(),
        'notifications' => DatabaseNotification::query()->count(),
        'audit' => DB::table('audit_logs')->count(),
    ];
}

it('does nothing outside the local and testing environments', function () {
    app()->detectEnvironment(fn (): string => 'production');

    // Even with --force, which skips db:seed's own production prompt.
    Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

    expect(User::query()->where('email', 'like', '%@'.DemoDataSeeder::EMAIL_DOMAIN)->count())->toBe(0)
        ->and(Factory::query()->count())->toBe(0);
});

it('is never run by the default database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'like', '%@'.DemoDataSeeder::EMAIL_DOMAIN)->count())->toBe(0);
});

it('builds a consistent demo dataset through the platform rules, once', function () {
    $this->seed(DemoDataSeeder::class);
    $first = demoCounts();

    // Organizations and their review states.
    expect($first['users'])->toBe(25)
        ->and(Factory::query()->get()->countBy(fn (Factory $factory): string => $factory->approval_status->value)->all())->toEqual([
            FactoryApprovalStatus::Approved->value => 11,
            FactoryApprovalStatus::Pending->value => 2,
            FactoryApprovalStatus::ChangesRequested->value => 1,
            FactoryApprovalStatus::Rejected->value => 1,
            FactoryApprovalStatus::Suspended->value => 1,
        ])
        ->and(ServiceProvider::query()->get()->countBy(fn (ServiceProvider $provider): string => $provider->approval_status->value)->all())->toEqual([
            ProviderApprovalStatus::Approved->value => 5,
            ProviderApprovalStatus::Pending->value => 1,
            ProviderApprovalStatus::ChangesRequested->value => 1,
            ProviderApprovalStatus::Rejected->value => 1,
        ])
        ->and(DB::table('catalog_service_service_provider')->pluck('status')->countBy()->all())->toEqual(['approved' => 12, 'pending' => 4, 'rejected' => 2, 'suspended' => 2]);

    // Readiness: every stored result is the server's calculation from its answers.
    $assessments = ReadinessAssessment::query()->with(['answers', 'questionnaire', 'category'])->get();
    expect($assessments)->toHaveCount(16);
    foreach ($assessments as $assessment) {
        expect($assessment->answers)->toHaveCount(10)
            ->and($assessment->total_score)->toBe((int) $assessment->answers->sum('points'))
            ->and($assessment->readiness_category_id)->toBe($assessment->questionnaire->categoryForScore($assessment->total_score)->id)
            ->and($assessment->answers->whereNull('question_text_ar'))->toBeEmpty();
    }
    expect($assessments->pluck('total_score')->intersect([10, 17, 18, 25, 26, 33, 34, 40])->unique()->sort()->values()->all())->toBe([10, 17, 18, 25, 26, 33, 34, 40])
        ->and(Factory::query()->with('currentReadinessAssessment.category')->get()->map(fn (Factory $factory): ?string => $factory->currentReadinessAssessment?->category->code->value)->filter()->unique()->sort()->values()->all())
        ->toBe(['advanced', 'b4_automation', 'basic', 'smart'])
        ->and(Factory::query()->has('readinessAssessments')->count())->toBe(13);

    // Marketplace activity.
    expect(ServiceRequest::query()->pluck('status')->map(fn (ServiceRequestStatus $status): string => $status->value)->countBy()->all())->toEqual(['awarded' => 4, 'open' => 5, 'cancelled' => 1])
        ->and(ProviderRequest::query()->pluck('status')->map(fn (ProviderRequestStatus $status): string => $status->value)->countBy()->all())->toEqual(['agreed' => 4, 'pending' => 1, 'accepted' => 2, 'declined' => 1, 'withdrawn' => 1, 'closed' => 1])
        ->and($first['offers'])->toBe(6)
        ->and($first['messages'])->toBe(11)
        ->and($first['agreements'])->toBe(4)
        ->and(AgreementReview::query()->pluck('decision')->map(fn ($decision): string => $decision->value)->sort()->values()->all())->toBe(['approved', 'rejected']);

    // No money and no policy; the one contract is a draft, never binding.
    expect(Contract::query()->pluck('status')->map(fn ($status): string => $status->value)->all())->toBe(['draft'])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(FinancialPolicy::query()->count())->toBe(0);

    // Promotions, announcements and change requests.
    expect(ServicePromotion::query()->get()->map(fn (ServicePromotion $promotion): string => $promotion->state())->sort()->values()->all())->toBe(['active', 'ended', 'scheduled'])
        ->and($first['announcements'])->toBe(4)
        ->and(FactoryProfileChangeRequest::query()->pluck('status')->map(fn ($status): string => $status->value)->sort()->values()->all())->toBe(['approved', 'pending'])
        ->and(ProviderProfileChangeRequest::query()->pluck('status')->map(fn ($status): string => $status->value)->sort()->values()->all())->toBe(['pending', 'rejected']);

    // Notifications went to every kind of recipient.
    $notified = fn (string $email): int => DatabaseNotification::query()->where('notifiable_id', User::query()->where('email', $email)->value('id'))->count();
    expect($notified('imc-reviewer@demo.jahez.test'))->toBeGreaterThan(0)
        ->and($notified('f01@demo.jahez.test'))->toBeGreaterThan(0)
        ->and($notified('p01@demo.jahez.test'))->toBeGreaterThan(0);

    // A UI change to a demo record survives a second run, which creates nothing.
    Factory::query()->where('name', 'مصنع النخبة للأغذية المحفوظة')->update(['city' => 'مدينة معدلة']);
    $this->seed(DemoDataSeeder::class);

    expect(demoCounts())->toBe($first)
        ->and(Factory::query()->where('name', 'مصنع النخبة للأغذية المحفوظة')->value('city'))->toBe('مدينة معدلة');
});

it('keeps drafts and ended announcements off the public page and invoicing blocked', function () {
    $this->seed(DemoDataSeeder::class);

    $titles = $this->getJson(route('api.v1.public.announcements.index'))->assertOk()->json('data.*.title');
    expect($titles)->toHaveCount(2)
        ->and($titles)->not->toContain('برنامج تدريبي في الأمن السيبراني الصناعي')
        ->and($titles)->not->toContain('ملتقى مزودي خدمات التحول الرقمي');

    $approved = Agreement::query()->whereHas('review', fn ($query) => $query->where('decision', 'approved'))->sole();
    Sanctum::actingAs(User::query()->where('factory_id', $approved->factory_id)->firstOrFail());
    $this->postJson(route('api.v1.agreements.invoices.store', $approved))
        ->assertConflict()
        ->assertJsonPath('code', 'policy_not_configured');
    $this->getJson(route('api.v1.contracts.show', Contract::query()->sole()))
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});
