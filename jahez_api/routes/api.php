<?php

use App\Http\Controllers\Api\V1\AccountSettingsController;
use App\Http\Controllers\Api\V1\AgreementController;
use App\Http\Controllers\Api\V1\AgreementFinancialReadinessController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AccessTokenController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\BillingConfigurationController;
use App\Http\Controllers\Api\V1\CatalogServiceController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\FactoryApprovalController;
use App\Http\Controllers\Api\V1\FactoryAssessmentController;
use App\Http\Controllers\Api\V1\FactoryChangeRequestController;
use App\Http\Controllers\Api\V1\FactoryController;
use App\Http\Controllers\Api\V1\FinancialPolicyController;
use App\Http\Controllers\Api\V1\FinancialPolicyVersionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceLineController;
use App\Http\Controllers\Api\V1\ManualPaymentController;
use App\Http\Controllers\Api\V1\MarketplaceReportController;
use App\Http\Controllers\Api\V1\NegotiationMessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OrganizationDocumentController;
use App\Http\Controllers\Api\V1\PaymentCallbackController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProviderChangeRequestController;
use App\Http\Controllers\Api\V1\ProviderDirectoryController;
use App\Http\Controllers\Api\V1\ProviderEvaluationController;
use App\Http\Controllers\Api\V1\ProviderRequestController;
use App\Http\Controllers\Api\V1\PublicAnnouncementController;
use App\Http\Controllers\Api\V1\ReadinessAnalyticsController;
use App\Http\Controllers\Api\V1\ReadinessAssessmentController;
use App\Http\Controllers\Api\V1\ReadinessQuestionnaireController;
use App\Http\Controllers\Api\V1\ReadinessQuestionnaireVersionController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\ReviewSummaryController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceListingController;
use App\Http\Controllers\Api\V1\ServiceListingReviewController;
use App\Http\Controllers\Api\V1\ServicePromotionController;
use App\Http\Controllers\Api\V1\ServiceProviderApprovalController;
use App\Http\Controllers\Api\V1\ServiceProviderController;
use App\Http\Controllers\Api\V1\ServiceProviderReviewRequestController;
use App\Http\Controllers\Api\V1\ServiceRequestController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
| All API routes are versioned. Every route in this file must live inside a version
| group; tests/Feature/Api/ApiVersioningTest.php enforces this.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)
        ->name('health')
        ->withoutMiddleware('throttle:api');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [AccessTokenController::class, 'store'])->name('login');
        Route::post('forgot-password', PasswordResetLinkController::class)
            ->middleware('throttle:password-reset')
            ->name('password.email');
        Route::post('reset-password', NewPasswordController::class)
            ->middleware('throttle:password-reset')
            ->name('password.store');
        Route::post('logout', [AccessTokenController::class, 'destroy'])
            ->middleware('auth:sanctum')
            ->name('logout');
    });

    // Public self-registration (ADR-019). The response is the same 202 whether or not the
    // email already has an account; the work is finished in a queued job.
    Route::prefix('registration')->name('registration.')->group(function (): void {
        Route::get('options', [RegistrationController::class, 'options'])->name('options');
        Route::post('factories', [RegistrationController::class, 'factory'])
            ->middleware('throttle:registration')
            ->name('factories');
        Route::post('service-providers', [RegistrationController::class, 'serviceProvider'])
            ->middleware('throttle:registration')
            ->name('service-providers');
    });

    // Landing-page announcements (ADR-022): live ones only, without a token.
    Route::prefix('public')->name('public.')->group(function (): void {
        Route::get('announcements', [PublicAnnouncementController::class, 'publicIndex'])->name('announcements.index');
        Route::get('announcements/{publicAnnouncement}', [PublicAnnouncementController::class, 'publicShow'])
            ->whereNumber('publicAnnouncement')
            ->name('announcements.show');
        Route::get('announcements/{publicAnnouncement}/cover', [PublicAnnouncementController::class, 'publicCover'])
            ->whereNumber('publicAnnouncement')
            ->name('announcements.cover');
    });

    // Payment gateway callbacks: public, verified by the gateway adapter (ADR-017).
    // Any key but the configured gateway's is 404.
    Route::post('payment-gateways/{gateway}/callback', PaymentCallbackController::class)
        ->where('gateway', '[a-z0-9_-]{1,50}')
        ->name('payment-gateways.callback');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', CurrentUserController::class)->name('me');

        // Ids must be whole numbers: MySQL would match "5abc" to record 5.
        Route::apiResource('factories', FactoryController::class)
            ->except('destroy')
            ->where(['factory' => '[0-9]+']);
        // Legacy manual classifications (ADR-016): read-only history since ADR-018.
        Route::get('factories/{factory}/assessments', [FactoryAssessmentController::class, 'index'])
            ->whereNumber('factory')
            ->name('factories.assessments.index');

        // Logos and registration documents (ADR-019): private files, read only here.
        Route::post('factories/{factory}/documents', [OrganizationDocumentController::class, 'storeForFactory'])
            ->whereNumber('factory')
            ->name('factories.documents.store');
        Route::get('factories/{factory}/documents/{document}', [OrganizationDocumentController::class, 'showForFactory'])
            ->whereNumber(['factory', 'document'])
            ->scopeBindings()
            ->name('factories.documents.show');

        // Digital readiness assessments (ADR-018): the server scores and classifies them.
        Route::get('readiness-questionnaire', [ReadinessQuestionnaireController::class, 'show'])->name('readiness-questionnaire.show');
        // Questionnaire versions for IMC administrators: drafts change, published versions never do.
        Route::get('readiness-questionnaires', [ReadinessQuestionnaireVersionController::class, 'index'])->name('readiness-questionnaires.index');
        Route::post('readiness-questionnaires', [ReadinessQuestionnaireVersionController::class, 'store'])->name('readiness-questionnaires.store');
        Route::get('readiness-questionnaires/{readinessQuestionnaire}', [ReadinessQuestionnaireVersionController::class, 'show'])
            ->whereNumber('readinessQuestionnaire')
            ->name('readiness-questionnaires.show');
        Route::put('readiness-questionnaires/{readinessQuestionnaire}', [ReadinessQuestionnaireVersionController::class, 'update'])
            ->whereNumber('readinessQuestionnaire')
            ->name('readiness-questionnaires.update');
        Route::delete('readiness-questionnaires/{readinessQuestionnaire}', [ReadinessQuestionnaireVersionController::class, 'destroy'])
            ->whereNumber('readinessQuestionnaire')
            ->name('readiness-questionnaires.destroy');
        Route::post('readiness-questionnaires/{readinessQuestionnaire}/publish', [ReadinessQuestionnaireVersionController::class, 'publish'])
            ->whereNumber('readinessQuestionnaire')
            ->name('readiness-questionnaires.publish');
        Route::get('factories/{factory}/readiness-assessments', [ReadinessAssessmentController::class, 'index'])
            ->whereNumber('factory')
            ->name('factories.readiness-assessments.index');
        Route::post('factories/{factory}/readiness-assessments', [ReadinessAssessmentController::class, 'store'])
            ->whereNumber('factory')
            ->name('factories.readiness-assessments.store');
        Route::get('factories/{factory}/readiness-assessments/{readinessAssessment}', [ReadinessAssessmentController::class, 'show'])
            ->whereNumber(['factory', 'readinessAssessment'])
            ->scopeBindings()
            ->name('factories.readiness-assessments.show');
        Route::apiResource('service-providers', ServiceProviderController::class)
            ->except('destroy')
            ->parameters(['service-providers' => 'serviceProvider'])
            ->where(['serviceProvider' => '[0-9]+']);
        Route::post('service-providers/{serviceProvider}/approval', ServiceProviderApprovalController::class)
            ->whereNumber('serviceProvider')
            ->name('service-providers.approval');
        Route::post('service-providers/{serviceProvider}/review-request', ServiceProviderReviewRequestController::class)
            ->whereNumber('serviceProvider')
            ->name('service-providers.review-request');
        Route::post('service-providers/{serviceProvider}/documents', [OrganizationDocumentController::class, 'storeForServiceProvider'])
            ->whereNumber('serviceProvider')
            ->name('service-providers.documents.store');
        Route::get('service-providers/{serviceProvider}/documents/{document}', [OrganizationDocumentController::class, 'showForServiceProvider'])
            ->whereNumber(['serviceProvider', 'document'])
            ->scopeBindings()
            ->name('service-providers.documents.show');

        // Changes to verified legal information go through IMC review (ADR-019).
        Route::get('provider-change-requests', [ProviderChangeRequestController::class, 'queue'])->name('provider-change-requests.index');
        Route::get('service-providers/{serviceProvider}/change-requests', [ProviderChangeRequestController::class, 'index'])
            ->whereNumber('serviceProvider')
            ->name('service-providers.change-requests.index');
        Route::post('service-providers/{serviceProvider}/change-requests', [ProviderChangeRequestController::class, 'store'])
            ->whereNumber('serviceProvider')
            ->name('service-providers.change-requests.store');
        foreach (['approve', 'reject', 'cancel'] as $action) {
            Route::post("service-providers/{serviceProvider}/change-requests/{changeRequest}/{$action}", [ProviderChangeRequestController::class, $action])
                ->whereNumber(['serviceProvider', 'changeRequest'])
                ->scopeBindings()
                ->name("service-providers.change-requests.{$action}");
        }

        Route::get('service-providers/{serviceProvider}/evaluations', [ProviderEvaluationController::class, 'index'])
            ->whereNumber('serviceProvider')
            ->name('service-providers.evaluations.index');
        Route::post('service-providers/{serviceProvider}/evaluations', [ProviderEvaluationController::class, 'store'])
            ->whereNumber('serviceProvider')
            ->name('service-providers.evaluations.store');
        Route::apiResource('users', UserController::class)
            ->except('destroy')
            ->where(['user' => '[0-9]+']);

        Route::prefix('reference')->name('reference.')->group(function (): void {
            Route::get('sectors', [ReferenceDataController::class, 'sectors'])->name('sectors');
            Route::get('factory-sizes', [ReferenceDataController::class, 'factorySizes'])->name('factory-sizes');
            Route::get('maturity-tiers', [ReferenceDataController::class, 'maturityTiers'])->name('maturity-tiers');
            Route::get('pathways', [ReferenceDataController::class, 'pathways'])->name('pathways');
            Route::get('evaluation-criteria', [ReferenceDataController::class, 'evaluationCriteria'])->name('evaluation-criteria');
        });

        Route::prefix('catalog')->name('catalog.')->group(function (): void {
            Route::get('categories', [ServiceCategoryController::class, 'index'])->name('categories.index');
            Route::get('categories/{serviceCategory}', [ServiceCategoryController::class, 'show'])
                ->whereNumber('serviceCategory')
                ->name('categories.show');
            Route::get('services', [CatalogServiceController::class, 'index'])->name('services.index');
            Route::get('services/{catalogService}', [CatalogServiceController::class, 'show'])
                ->whereNumber('catalogService')
                ->name('services.show');
        });

        Route::get('provider-directory', [ProviderDirectoryController::class, 'index'])->name('provider-directory');
        Route::get('provider-directory/{serviceProvider}', [ProviderDirectoryController::class, 'show'])
            ->whereNumber('serviceProvider')
            ->name('provider-directory.show');
        Route::get('provider-directory/{serviceProvider}/logo', [OrganizationDocumentController::class, 'directoryLogo'])
            ->whereNumber('serviceProvider')
            ->name('provider-directory.logo');

        // Marketplace requests and negotiation (ADR-015). Actions are explicit routes:
        // clients never set a status field.
        Route::get('service-requests', [ServiceRequestController::class, 'index'])->name('service-requests.index');
        Route::post('service-requests', [ServiceRequestController::class, 'store'])->name('service-requests.store');
        Route::get('service-requests/{serviceRequest}', [ServiceRequestController::class, 'show'])
            ->whereNumber('serviceRequest')
            ->name('service-requests.show');
        Route::post('service-requests/{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel'])
            ->whereNumber('serviceRequest')
            ->name('service-requests.cancel');
        Route::post('service-requests/{serviceRequest}/providers', [ServiceRequestController::class, 'addProviders'])
            ->whereNumber('serviceRequest')
            ->name('service-requests.providers.store');

        Route::prefix('provider-requests')->name('provider-requests.')->group(function (): void {
            Route::get('/', [ProviderRequestController::class, 'index'])->name('index');
            Route::get('{providerRequest}', [ProviderRequestController::class, 'show'])->whereNumber('providerRequest')->name('show');
            Route::post('{providerRequest}/accept', [ProviderRequestController::class, 'accept'])->whereNumber('providerRequest')->name('accept');
            Route::post('{providerRequest}/decline', [ProviderRequestController::class, 'decline'])->whereNumber('providerRequest')->name('decline');
            Route::post('{providerRequest}/withdraw', [ProviderRequestController::class, 'withdraw'])->whereNumber('providerRequest')->name('withdraw');
            Route::get('{providerRequest}/history', [ProviderRequestController::class, 'history'])->whereNumber('providerRequest')->name('history');

            Route::get('{providerRequest}/messages', [NegotiationMessageController::class, 'index'])->whereNumber('providerRequest')->name('messages.index');
            Route::post('{providerRequest}/messages', [NegotiationMessageController::class, 'store'])
                ->whereNumber('providerRequest')
                ->middleware('throttle:negotiation-messages')
                ->name('messages.store');

            Route::get('{providerRequest}/offers', [OfferController::class, 'index'])->whereNumber('providerRequest')->name('offers.index');
            Route::post('{providerRequest}/offers', [OfferController::class, 'store'])
                ->whereNumber('providerRequest')
                ->middleware('throttle:negotiation-offers')
                ->name('offers.store');
            Route::post('{providerRequest}/offers/{offer}/accept', [OfferController::class, 'accept'])
                ->whereNumber(['providerRequest', 'offer'])
                ->scopeBindings()
                ->name('offers.accept');
        });

        // Agreements and contract drafts (ADR-017). Agreements are created only by
        // accepting an offer; contracts are drafts and never binding (OQ-17).
        Route::get('agreements', [AgreementController::class, 'index'])->name('agreements.index');
        Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->whereNumber('agreement')->name('agreements.show');
        Route::post('agreements/{agreement}/contracts', [ContractController::class, 'store'])->whereNumber('agreement')->name('agreements.contracts.store');
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereNumber('contract')->name('contracts.show');
        Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->whereNumber('contract')->name('contracts.cancel');

        // Billing and payments (ADR-017, ADR-023): each operation needs the approved
        // financial policy it depends on, or gets 409 policy_not_configured.
        Route::get('billing/configuration', BillingConfigurationController::class)->name('billing.configuration');
        Route::post('agreements/{agreement}/invoices', [InvoiceController::class, 'store'])->whereNumber('agreement')->name('agreements.invoices.store');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->whereNumber('invoice')->name('invoices.issue');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('invoice')->name('invoices.cancel');
        Route::post('invoices/{invoice}/lines', [InvoiceLineController::class, 'store'])->whereNumber('invoice')->name('invoices.lines.store');
        Route::delete('invoices/{invoice}/lines/{line}', [InvoiceLineController::class, 'destroy'])
            ->whereNumber(['invoice', 'line'])
            ->scopeBindings()
            ->name('invoices.lines.destroy');
        Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index'])->whereNumber('invoice')->name('invoices.payments.index');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->whereNumber('invoice')->name('invoices.payments.store');
        Route::post('invoices/{invoice}/manual-payments', [ManualPaymentController::class, 'store'])->whereNumber('invoice')->name('invoices.manual-payments.store');
        Route::get('agreements/{agreement}/financial-readiness', AgreementFinancialReadinessController::class)->whereNumber('agreement')->name('agreements.financial-readiness');

        // Financial and contract policies (ADR-023): versioned, approved by a second
        // administrator, never edited once approved.
        Route::get('financial-policies', [FinancialPolicyController::class, 'index'])->name('financial-policies.index');
        Route::post('financial-policies', [FinancialPolicyController::class, 'store'])->name('financial-policies.store');
        Route::get('financial-policies/resolve', [FinancialPolicyController::class, 'resolve'])->name('financial-policies.resolve');
        Route::post('financial-policies/preview', [FinancialPolicyController::class, 'preview'])->name('financial-policies.preview');
        Route::get('financial-policies/{financialPolicy}', [FinancialPolicyController::class, 'show'])->whereNumber('financialPolicy')->name('financial-policies.show');
        Route::post('financial-policies/{financialPolicy}/versions', [FinancialPolicyVersionController::class, 'store'])->whereNumber('financialPolicy')->name('financial-policies.versions.store');
        Route::get('financial-policy-versions/{financialPolicyVersion}', [FinancialPolicyVersionController::class, 'show'])->whereNumber('financialPolicyVersion')->name('financial-policy-versions.show');
        Route::patch('financial-policy-versions/{financialPolicyVersion}', [FinancialPolicyVersionController::class, 'update'])->whereNumber('financialPolicyVersion')->name('financial-policy-versions.update');
        Route::post('financial-policy-versions/{financialPolicyVersion}/submit', [FinancialPolicyVersionController::class, 'submit'])->whereNumber('financialPolicyVersion')->name('financial-policy-versions.submit');
        foreach (['approve', 'reject', 'archive', 'end'] as $action) {
            Route::post("financial-policy-versions/{financialPolicyVersion}/{$action}", [FinancialPolicyVersionController::class, $action])
                ->whereNumber('financialPolicyVersion')
                ->name("financial-policy-versions.{$action}");
        }
        Route::get('financial-policy-versions/{financialPolicyVersion}/history', [FinancialPolicyVersionController::class, 'history'])->whereNumber('financialPolicyVersion')->name('financial-policy-versions.history');
        Route::post('financial-policy-versions/{financialPolicyVersion}/preview', [FinancialPolicyVersionController::class, 'preview'])->whereNumber('financialPolicyVersion')->name('financial-policy-versions.preview');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // Phase 2 portals (ADR-020): IMC review of agreements, read marks, notifications,
        // account settings, promoted listings, reports and factory legal changes.
        Route::post('agreements/{agreement}/review', [AgreementController::class, 'review'])->whereNumber('agreement')->name('agreements.review');
        Route::post('provider-requests/{providerRequest}/read', [ProviderRequestController::class, 'read'])->whereNumber('providerRequest')->name('provider-requests.read');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
        Route::patch('me', [AccountSettingsController::class, 'update'])->name('me.update');

        Route::get('service-listings', [ServiceListingController::class, 'index'])->name('service-listings.index');
        Route::get('promotions', [ServicePromotionController::class, 'index'])->name('promotions.index');
        Route::post('promotions', [ServicePromotionController::class, 'store'])->name('promotions.store');
        Route::patch('promotions/{servicePromotion}', [ServicePromotionController::class, 'update'])->whereNumber('servicePromotion')->name('promotions.update');
        Route::post('promotions/{servicePromotion}/end', [ServicePromotionController::class, 'end'])->whereNumber('servicePromotion')->name('promotions.end');

        Route::get('reports/marketplace', MarketplaceReportController::class)->name('reports.marketplace');

        Route::get('factory-change-requests', [FactoryChangeRequestController::class, 'queue'])->name('factory-change-requests.index');
        Route::get('factories/{factory}/change-requests', [FactoryChangeRequestController::class, 'index'])
            ->whereNumber('factory')
            ->name('factories.change-requests.index');
        Route::post('factories/{factory}/change-requests', [FactoryChangeRequestController::class, 'store'])
            ->whereNumber('factory')
            ->name('factories.change-requests.store');
        foreach (['approve', 'reject', 'cancel'] as $action) {
            Route::post("factories/{factory}/change-requests/{changeRequest}/{$action}", [FactoryChangeRequestController::class, $action])
                ->whereNumber(['factory', 'changeRequest'])
                ->scopeBindings()
                ->name("factories.change-requests.{$action}");
        }

        // Phase 3 administration (ADR-021): factory approval, per-service listing review
        // and the readiness assessment results and analytics.
        Route::post('factories/{factory}/approval', [FactoryApprovalController::class, 'decide'])
            ->whereNumber('factory')
            ->name('factories.approval');
        Route::post('factories/{factory}/review-request', [FactoryApprovalController::class, 'requestReview'])
            ->whereNumber('factory')
            ->name('factories.review-request');
        Route::post('service-providers/{serviceProvider}/services/{catalogService}/review', ServiceListingReviewController::class)
            ->whereNumber(['serviceProvider', 'catalogService'])
            ->name('service-providers.services.review');
        Route::post('service-providers/{serviceProvider}/services/{catalogService}/resubmit', [ServiceListingReviewController::class, 'resubmit'])
            ->whereNumber(['serviceProvider', 'catalogService'])
            ->name('service-providers.services.resubmit');
        Route::get('readiness-assessments', [ReadinessAnalyticsController::class, 'index'])->name('readiness-assessments.index');
        Route::get('readiness-analytics', [ReadinessAnalyticsController::class, 'summary'])->name('readiness-analytics');
        Route::get('review-summary', ReviewSummaryController::class)->name('review-summary');

        // Landing-page announcements (ADR-022), IMC administration.
        Route::get('announcements', [PublicAnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [PublicAnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('announcements/{publicAnnouncement}', [PublicAnnouncementController::class, 'show'])->whereNumber('publicAnnouncement')->name('announcements.show');
        Route::patch('announcements/{publicAnnouncement}', [PublicAnnouncementController::class, 'update'])->whereNumber('publicAnnouncement')->name('announcements.update');
        Route::delete('announcements/{publicAnnouncement}', [PublicAnnouncementController::class, 'destroy'])->whereNumber('publicAnnouncement')->name('announcements.destroy');
        Route::post('announcements/{publicAnnouncement}/publish', [PublicAnnouncementController::class, 'publish'])->whereNumber('publicAnnouncement')->name('announcements.publish');
        Route::post('announcements/{publicAnnouncement}/unpublish', [PublicAnnouncementController::class, 'unpublish'])->whereNumber('publicAnnouncement')->name('announcements.unpublish');
        Route::get('announcements/{publicAnnouncement}/cover', [PublicAnnouncementController::class, 'cover'])->whereNumber('publicAnnouncement')->name('announcements.cover');
        Route::post('announcements/{publicAnnouncement}/cover', [PublicAnnouncementController::class, 'storeCover'])->whereNumber('publicAnnouncement')->name('announcements.cover.store');
        Route::delete('announcements/{publicAnnouncement}/cover', [PublicAnnouncementController::class, 'destroyCover'])->whereNumber('publicAnnouncement')->name('announcements.cover.destroy');
    });
});
