<?php

use App\Enums\Role;
use App\Models\EvaluationCriterion;
use App\Models\LevelProviderRequirement;
use App\Models\MaturityTier;
use App\Models\Pathway;
use App\Models\PathwayLevel;
use App\Models\PathwayScopeItem;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDemoSeeder;
use Database\Seeders\ReferenceDataSeeder;

it('seeds the complete reference data set from the source document', function () {
    $this->seed(ReferenceDataSeeder::class);

    expect(Sector::query()->count())->toBe(4)
        ->and(Pathway::query()->count())->toBe(2)
        ->and(PathwayLevel::query()->count())->toBe(4)
        ->and(PathwayScopeItem::query()->count())->toBe(25)
        ->and(LevelProviderRequirement::query()->count())->toBe(9)
        ->and(MaturityTier::query()->count())->toBe(4)
        ->and(EvaluationCriterion::query()->count())->toBe(5);
});

it('seeds the four source sectors with their exact Arabic names and no invented English', function () {
    $this->seed(ReferenceDataSeeder::class);

    $sectors = Sector::query()->orderBy('sort_order')->get();

    expect($sectors->pluck('name_ar', 'code')->all())->toBe([
        'food' => 'الصناعات الغذائية',
        'chemical' => 'الصناعات الكيماوية',
        'engineering_metal' => 'الصناعات الهندسية والمعدنية',
        'medical_pharmaceutical' => 'الصناعات الطبية والدوائية',
    ]);
    expect($sectors->pluck('name_en')->filter()->all())->toBeEmpty();
});

it('places each level in its pathway with the source scope items and provider requirements', function () {
    $this->seed(ReferenceDataSeeder::class);

    $levels = PathwayLevel::query()
        ->with('pathway')
        ->withCount(['scopeItems', 'providerRequirements'])
        ->orderBy('sort_order')
        ->get();

    expect($levels->map(fn (PathwayLevel $level): array => [
        $level->code,
        $level->pathway->code,
        $level->name_ar,
        $level->name_en,
        $level->scope_items_count,
        $level->provider_requirements_count,
    ])->all())->toBe([
        ['foundational', 'foundational', 'التمكين التأسيسي وتأهيل البنية التحتية', 'Foundational Pathway', 6, 0],
        ['basic_dx', 'digital_transformation', 'المستوى الأساسي', 'Basic DX', 6, 3],
        ['advanced_dx', 'digital_transformation', 'المستوى المتقدم', 'Advanced DX', 7, 3],
        ['smart_dx', 'digital_transformation', 'المستوى الذكي', 'Smart DX', 6, 3],
    ]);
});

it('maps each maturity tier to its approved execution path and leaves score thresholds unset', function () {
    $this->seed(ReferenceDataSeeder::class);

    $tiers = MaturityTier::query()->with('pathwayLevel')->orderBy('sort_order')->get();

    expect($tiers->map(fn (MaturityTier $tier): array => [
        $tier->name_ar,
        $tier->name_en,
        $tier->readiness_band_ar,
        $tier->pathwayLevel->code,
        $tier->score_min,
        $tier->score_max,
    ])->all())->toBe([
        ['التأسيسي', 'Foundation Tier', 'ضعيف / منخفض', 'foundational', null, null],
        ['الرقمي الأساسي', 'Basic DX Tier', 'متوسط الأدنى', 'basic_dx', null, null],
        ['المصنع المتقدم', 'Advanced DX Tier', 'متوسط الأعلى', 'advanced_dx', null, null],
        ['المصنع الذكي', 'Smart DX Tier', 'متقدم / مرتفع', 'smart_dx', null, null],
    ]);
});

it('seeds the source evaluation weights for version 1, totalling 100 percent', function () {
    $this->seed(ReferenceDataSeeder::class);

    $criteria = EvaluationCriterion::query()->where('version', 1)->orderBy('sort_order');

    expect($criteria->pluck('weight_percent', 'code')->all())->toBe([
        'technical_expertise' => '30.00',
        'technical_cloud_model' => '25.00',
        'knowledge_transfer' => '20.00',
        'financial_flexibility' => '15.00',
        'technical_support_sla' => '10.00',
    ]);
    expect((string) EvaluationCriterion::query()->where('version', 1)->sum('weight_percent'))->toBe('100.00');
});

it('can run repeatedly without duplicating or replacing rows', function () {
    $collectRowIds = fn (): array => array_map(
        fn (string $model): array => $model::query()->orderBy('id')->pluck('id')->all(),
        [Sector::class, Pathway::class, PathwayLevel::class, PathwayScopeItem::class, LevelProviderRequirement::class, MaturityTier::class, EvaluationCriterion::class],
    );
    $this->seed(ReferenceDataSeeder::class);
    $rowIdsAfterFirstRun = $collectRowIds();

    $this->seed(ReferenceDataSeeder::class);

    expect($collectRowIds())->toBe($rowIdsAfterFirstRun);
});

it('restores changed source text and removes surplus items on a re-run', function () {
    $this->seed(ReferenceDataSeeder::class);
    $basicLevel = PathwayLevel::query()->where('code', 'basic_dx')->firstOrFail();
    Sector::query()->where('code', 'food')->update(['name_ar' => 'نص معدل']);
    PathwayScopeItem::query()->create(['pathway_level_id' => $basicLevel->id, 'text_ar' => 'بند إضافي', 'sort_order' => 7]);

    $this->seed(ReferenceDataSeeder::class);

    expect(Sector::query()->where('code', 'food')->value('name_ar'))->toBe('الصناعات الغذائية');
    expect(PathwayScopeItem::query()->whereBelongsTo($basicLevel)->count())->toBe(6);
});

it('never creates known-password accounts or demo organizations outside local and testing', function () {
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('factories', 0);
    $this->assertDatabaseCount('service_providers', 0);
    expect(Sector::query()->count())->toBe(4);
});

it('creates no demo accounts when the demo seeder is run directly in production', function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('factories', 0);
});

it('creates the local administrator and demo accounts only once across repeated runs', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'test@example.com')->value('role'))->toBe(Role::ImcAdmin)
        ->and(User::query()->orderBy('email')->pluck('role', 'email')->map(fn (Role $role): string => $role->value)->all())->toBe([
            'factory-a@example.test' => 'factory_member',
            'factory-b@example.test' => 'factory_member',
            'finance-approver@example.test' => 'imc_admin',
            'finance-maker@example.test' => 'imc_admin',
            'provider-p@example.test' => 'provider_member',
            'provider-q@example.test' => 'provider_member',
            'test@example.com' => 'imc_admin',
        ]);
    $this->assertDatabaseCount('factories', 2);
    $this->assertDatabaseCount('service_providers', 2);
    // ADR-023: the two demo finance administrators split preparing and approving; no policy is seeded.
    $this->assertDatabaseCount('user_permission_grants', 3);
    $this->assertDatabaseCount('financial_policies', 0);
});
