<?php

namespace App\Notifications;

use App\Enums\FactoryApprovalStatus;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceListingStatus;
use App\Models\Agreement;
use App\Models\CatalogService;
use App\Models\Contract;
use App\Models\Factory;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;

/**
 * Who is told about each marketplace, agreement, billing and profile event, and what
 * the notification says (ADR-020). Every text names records and parties only. Links are
 * paths of the web client (config api.frontend_url is prefixed in emails). Call these
 * inside the transaction that made the change: PlatformNotifier sends after the commit.
 */
class MarketplaceNotifications
{
    public static function providerThreadLink(int $threadId): string
    {
        return "/provider/requests/{$threadId}";
    }

    public static function factoryThreadLink(int $serviceRequestId, int $threadId): string
    {
        return "/factory/requests/{$serviceRequestId}?thread={$threadId}";
    }

    /**
     * A factory sent the request to these providers (on creation or when adding providers).
     *
     * @param  iterable<ProviderRequest>  $threads
     */
    public static function requestSent(ServiceRequest $serviceRequest, iterable $threads): void
    {
        $factoryName = Factory::query()->whereKey($serviceRequest->factory_id)->value('name');

        foreach ($threads as $thread) {
            PlatformNotifier::providerMembers(
                $thread->service_provider_id,
                NotificationEvent::RequestReceived,
                "provider_request.{$thread->id}.created",
                "أرسل المصنع «{$factoryName}» طلب خدمة جديدًا (رقم {$thread->id}) بعنوان «{$serviceRequest->title}».",
                self::providerThreadLink($thread->id),
                ['type' => 'provider_request', 'id' => $thread->id],
            );
        }
    }

    /**
     * The provider accepted or declined, or the factory withdrew.
     */
    public static function threadAnswered(ProviderRequest $thread, ProviderRequestStatus $status): void
    {
        $serviceRequest = ServiceRequest::query()->findOrFail($thread->service_request_id);
        $providerName = ServiceProvider::query()->whereKey($thread->service_provider_id)->value('name');
        $key = "provider_request.{$thread->id}.{$status->value}";
        $subject = ['type' => 'provider_request', 'id' => $thread->id];

        match ($status) {
            ProviderRequestStatus::Accepted => PlatformNotifier::factoryMembers(
                $serviceRequest->factory_id,
                NotificationEvent::RequestAccepted,
                $key,
                "قبل المزود «{$providerName}» طلبكم «{$serviceRequest->title}». يمكنكم الآن التفاوض معه داخل الطلب.",
                self::factoryThreadLink($serviceRequest->id, $thread->id),
                $subject,
            ),
            ProviderRequestStatus::Declined => PlatformNotifier::factoryMembers(
                $serviceRequest->factory_id,
                NotificationEvent::RequestDeclined,
                $key,
                "اعتذر المزود «{$providerName}» عن طلبكم «{$serviceRequest->title}».",
                self::factoryThreadLink($serviceRequest->id, $thread->id),
                $subject,
            ),
            ProviderRequestStatus::Withdrawn => PlatformNotifier::providerMembers(
                $thread->service_provider_id,
                NotificationEvent::RequestWithdrawn,
                $key,
                "سحب المصنع الطلب رقم {$thread->id} «{$serviceRequest->title}» من شركتكم.",
                self::providerThreadLink($thread->id),
                $subject,
            ),
            default => null,
        };
    }

    /**
     * Threads the system closed because the request was cancelled or awarded elsewhere.
     *
     * @param  list<int>  $threadIds
     */
    public static function threadsClosed(ServiceRequest $serviceRequest, array $threadIds, string $reason): void
    {
        $why = $reason === 'request_awarded' ? 'رُسّي الطلب على مزود آخر' : 'ألغى المصنع الطلب';

        foreach (ProviderRequest::query()->whereKey($threadIds)->get() as $thread) {
            PlatformNotifier::providerMembers(
                $thread->service_provider_id,
                NotificationEvent::RequestClosed,
                "provider_request.{$thread->id}.closed",
                "أُغلق الطلب رقم {$thread->id} «{$serviceRequest->title}»: {$why}.",
                self::providerThreadLink($thread->id),
                ['type' => 'provider_request', 'id' => $thread->id],
            );
        }
    }

    /**
     * A new negotiation message: the other side is told, never the text.
     */
    public static function messagePosted(ProviderRequest $thread, ProviderRequestMessage $message): void
    {
        $serviceRequest = ServiceRequest::query()->findOrFail($thread->service_request_id);
        $key = "message.{$message->id}";
        $subject = ['type' => 'provider_request', 'id' => $thread->id];

        if ($message->author_side === ProviderRequestMessage::SIDE_FACTORY) {
            PlatformNotifier::providerMembers(
                $thread->service_provider_id,
                NotificationEvent::MessageReceived,
                $key,
                "رسالة جديدة من المصنع على الطلب رقم {$thread->id} «{$serviceRequest->title}».",
                self::providerThreadLink($thread->id),
                $subject,
            );

            return;
        }

        $providerName = ServiceProvider::query()->whereKey($thread->service_provider_id)->value('name');
        PlatformNotifier::factoryMembers(
            $serviceRequest->factory_id,
            NotificationEvent::MessageReceived,
            $key,
            "رسالة جديدة من المزود «{$providerName}» على طلبكم «{$serviceRequest->title}».",
            self::factoryThreadLink($serviceRequest->id, $thread->id),
            $subject,
        );
    }

    /**
     * A new offer version: the factory is told the version, never the terms or price.
     */
    public static function offerSubmitted(ProviderRequest $thread, Offer $offer): void
    {
        $serviceRequest = ServiceRequest::query()->findOrFail($thread->service_request_id);
        $providerName = ServiceProvider::query()->whereKey($thread->service_provider_id)->value('name');

        PlatformNotifier::factoryMembers(
            $serviceRequest->factory_id,
            NotificationEvent::OfferSubmitted,
            "offer.{$offer->id}.submitted",
            "قدّم المزود «{$providerName}» النسخة {$offer->version} من عرضه على طلبكم «{$serviceRequest->title}».",
            self::factoryThreadLink($serviceRequest->id, $thread->id),
            ['type' => 'provider_request', 'id' => $thread->id],
        );
    }

    /**
     * The factory accepted an offer: the provider is told, and IMC reviewers get the
     * agreement in their queue when its approval is required.
     */
    public static function offerAccepted(ProviderRequest $thread, Agreement $agreement): void
    {
        $serviceRequest = ServiceRequest::query()->findOrFail($thread->service_request_id);
        $factoryName = Factory::query()->whereKey($serviceRequest->factory_id)->value('name');
        $requiresReview = (bool) config('jahez.agreements.imc_approval_required', true);

        PlatformNotifier::providerMembers(
            $thread->service_provider_id,
            NotificationEvent::OfferAccepted,
            "agreement.{$agreement->id}.concluded",
            "قبل المصنع «{$factoryName}» عرضكم على الطلب رقم {$thread->id}. سُجّلت اتفاقية رقم {$agreement->id}"
                .($requiresReview ? '، وهي بانتظار مراجعة مركز تحديث الصناعة.' : '.'),
            "/provider/contracts/{$agreement->id}",
            ['type' => 'agreement', 'id' => $agreement->id],
        );

        if ($requiresReview) {
            PlatformNotifier::imcWith(
                Permission::AgreementsReview,
                NotificationEvent::AgreementAwaitingReview,
                "agreement.{$agreement->id}.awaiting_review",
                "الاتفاقية رقم {$agreement->id} بين «{$factoryName}» ومزود الخدمة بانتظار مراجعتكم.",
                '/admin/contracts',
                ['type' => 'agreement', 'id' => $agreement->id],
            );
        }
    }

    public static function agreementReviewed(Agreement $agreement, bool $approved): void
    {
        $event = $approved ? NotificationEvent::AgreementApproved : NotificationEvent::AgreementRejected;
        $body = $approved
            ? "اعتمد مركز تحديث الصناعة الاتفاقية رقم {$agreement->id}. يمكن الآن صياغة مسودة العقد."
            : "رفض مركز تحديث الصناعة الاتفاقية رقم {$agreement->id}. راجعوا سبب الرفض في تفاصيل الاتفاقية.";
        $key = "agreement.{$agreement->id}.reviewed";
        $subject = ['type' => 'agreement', 'id' => $agreement->id];

        PlatformNotifier::factoryMembers($agreement->factory_id, $event, $key, $body, "/factory/contracts/{$agreement->id}", $subject);
        PlatformNotifier::providerMembers($agreement->service_provider_id, $event, $key, $body, "/provider/contracts/{$agreement->id}", $subject);
    }

    /**
     * A contract draft was made or cancelled: the other party is told.
     */
    public static function contractChanged(Agreement $agreement, Contract $contract, string $actorSide, bool $cancelled): void
    {
        $event = $cancelled ? NotificationEvent::ContractCancelled : NotificationEvent::ContractDrafted;
        $body = $cancelled
            ? "أُلغيت مسودة العقد (النسخة {$contract->version}) للاتفاقية رقم {$agreement->id}."
            : "أُعدّت مسودة عقد (النسخة {$contract->version}) للاتفاقية رقم {$agreement->id}. المسودة غير ملزمة قانونيًا.";
        $key = "contract.{$contract->id}.".($cancelled ? 'cancelled' : 'drafted');
        $subject = ['type' => 'agreement', 'id' => $agreement->id];

        if ($actorSide !== ProviderRequestMessage::SIDE_FACTORY) {
            PlatformNotifier::factoryMembers($agreement->factory_id, $event, $key, $body, "/factory/contracts/{$agreement->id}", $subject);
        }
        if ($actorSide !== ProviderRequestMessage::SIDE_PROVIDER) {
            PlatformNotifier::providerMembers($agreement->service_provider_id, $event, $key, $body, "/provider/contracts/{$agreement->id}", $subject);
        }
    }

    public static function invoiceIssued(Invoice $invoice, Agreement $agreement): void
    {
        $body = "صدرت الفاتورة رقم {$invoice->number} للاتفاقية رقم {$agreement->id}.";
        $key = "invoice.{$invoice->id}.issued";
        $subject = ['type' => 'invoice', 'id' => $invoice->id];

        PlatformNotifier::factoryMembers($agreement->factory_id, NotificationEvent::InvoiceIssued, $key, $body, "/factory/invoices?invoice={$invoice->id}", $subject);
        PlatformNotifier::providerMembers($agreement->service_provider_id, NotificationEvent::InvoiceIssued, $key, $body, "/provider/invoices?invoice={$invoice->id}", $subject);
    }

    /**
     * Verified gateway evidence marked the invoice paid.
     */
    public static function invoicePaid(Invoice $invoice, Agreement $agreement): void
    {
        $body = "تأكد سداد الفاتورة رقم {$invoice->number} عبر بوابة الدفع.";
        $key = "invoice.{$invoice->id}.paid";
        $subject = ['type' => 'invoice', 'id' => $invoice->id];

        PlatformNotifier::factoryMembers($agreement->factory_id, NotificationEvent::PaymentConfirmed, $key, $body, "/factory/invoices?invoice={$invoice->id}", $subject);
        PlatformNotifier::providerMembers($agreement->service_provider_id, NotificationEvent::PaymentConfirmed, $key, $body, "/provider/invoices?invoice={$invoice->id}", $subject);
    }

    /**
     * IMC approved, rejected or suspended the provider. The key includes the time of the
     * decision, so each decision notifies once.
     */
    public static function providerApprovalChanged(ServiceProvider $provider): void
    {
        $label = match ($provider->approval_status) {
            ProviderApprovalStatus::Approved => 'اعتمد مركز تحديث الصناعة ملف شركتكم، وأصبحت خدماتكم ظاهرة للمصانع المؤهلة.',
            ProviderApprovalStatus::Rejected => 'رفض مركز تحديث الصناعة اعتماد ملف شركتكم. راجعوا السبب في الإعدادات.',
            ProviderApprovalStatus::Suspended => 'أوقف مركز تحديث الصناعة اعتماد شركتكم مؤقتًا، وتتوقف طلباتكم المفتوحة حتى إعادة الاعتماد.',
            ProviderApprovalStatus::Pending => 'ملف شركتكم قيد المراجعة لدى مركز تحديث الصناعة.',
            ProviderApprovalStatus::ChangesRequested => 'طلب مركز تحديث الصناعة استكمال بيانات ملف شركتكم أو تصويبها قبل الاعتماد. راجعوا الملاحظات في الإعدادات ثم أعيدوا طلب المراجعة.',
        };

        PlatformNotifier::providerMembers(
            $provider->id,
            NotificationEvent::ProviderApprovalChanged,
            "service_provider.{$provider->id}.approval.{$provider->approval_status->value}.".($provider->approval_changed_at?->getTimestamp() ?? 0),
            $label,
            '/provider/settings',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
    }

    /**
     * A member submitted a legal change request: IMC reviewers are told.
     */
    public static function changeRequestSubmitted(string $organizationType, int $changeRequestId, string $organizationName): void
    {
        $permission = $organizationType === 'factory' ? Permission::FactoriesUpdate : Permission::ServiceProvidersApprove;
        PlatformNotifier::imcWith(
            $permission,
            NotificationEvent::ChangeRequestSubmitted,
            "{$organizationType}_change_request.{$changeRequestId}.submitted",
            "طلب تعديل بيانات قانونية من «{$organizationName}» بانتظار المراجعة.",
            $organizationType === 'factory' ? '/admin/change-requests?type=factory' : '/admin/change-requests?type=provider',
            ['type' => "{$organizationType}_change_request", 'id' => $changeRequestId],
        );
    }

    /**
     * IMC decided a legal change request: the organization's members are told.
     */
    public static function changeRequestDecided(string $organizationType, int $organizationId, int $changeRequestId, bool $approved): void
    {
        $event = $approved ? NotificationEvent::ChangeRequestApproved : NotificationEvent::ChangeRequestRejected;
        $body = $approved
            ? 'اعتمد مركز تحديث الصناعة طلب تعديل بياناتكم القانونية، وطُبّقت القيم الجديدة.'
            : 'رفض مركز تحديث الصناعة طلب تعديل بياناتكم القانونية، وبقيت البيانات المعتمدة كما هي. راجعوا السبب في الإعدادات.';
        $key = "{$organizationType}_change_request.{$changeRequestId}.decided";
        $subject = ['type' => "{$organizationType}_change_request", 'id' => $changeRequestId];

        if ($organizationType === 'factory') {
            PlatformNotifier::factoryMembers($organizationId, $event, $key, $body, '/factory/settings', $subject);
        } else {
            PlatformNotifier::providerMembers($organizationId, $event, $key, $body, '/provider/settings', $subject);
        }
    }

    /**
     * Where IMC reviews an organization: its approval details page.
     */
    public static function adminReviewLink(string $organizationType, int $organizationId): string
    {
        return $organizationType === 'factory' ? "/admin/approvals/factories/{$organizationId}" : "/admin/approvals/providers/{$organizationId}";
    }

    /**
     * A public registration created a factory or provider: IMC reviewers are told. The
     * registration job runs once per registration, so the key is the organization.
     */
    public static function organizationRegistered(string $organizationType, int $organizationId, string $organizationName): void
    {
        PlatformNotifier::imcWith(
            $organizationType === 'factory' ? Permission::FactoriesApprove : Permission::ServiceProvidersApprove,
            NotificationEvent::OrganizationRegistered,
            "{$organizationType}.{$organizationId}.registered",
            $organizationType === 'factory'
                ? "سجّلت المنشأة الصناعية «{$organizationName}» في المنصة، وملفها بانتظار المراجعة."
                : "سجّل مزود الخدمة «{$organizationName}» في المنصة، وملفه بانتظار المراجعة.",
            self::adminReviewLink($organizationType, $organizationId),
            ['type' => $organizationType, 'id' => $organizationId],
        );
    }

    /**
     * A rejected organization, or one asked for corrections, asked for a new review.
     */
    public static function reviewRequested(string $organizationType, int $organizationId, string $organizationName): void
    {
        PlatformNotifier::imcWith(
            $organizationType === 'factory' ? Permission::FactoriesApprove : Permission::ServiceProvidersApprove,
            NotificationEvent::ReviewRequested,
            "{$organizationType}.{$organizationId}.review_requested.".now()->getTimestamp(),
            "طلبت «{$organizationName}» إعادة مراجعة ملفها بعد تحديثه.",
            self::adminReviewLink($organizationType, $organizationId),
            ['type' => $organizationType, 'id' => $organizationId],
        );
    }

    /**
     * IMC decided on a factory's account (ADR-021). The key includes the time of the
     * decision, so each decision notifies once.
     */
    public static function factoryApprovalChanged(Factory $factory): void
    {
        $body = match ($factory->approval_status) {
            FactoryApprovalStatus::Approved => 'اعتمد مركز تحديث الصناعة ملف منشأتكم، ويمكنكم الآن إرسال طلبات الخدمة إلى المزودين المؤهلين.',
            FactoryApprovalStatus::Rejected => 'رفض مركز تحديث الصناعة اعتماد ملف منشأتكم. راجعوا السبب في الإعدادات.',
            FactoryApprovalStatus::ChangesRequested => 'طلب مركز تحديث الصناعة استكمال بيانات منشأتكم أو تصويبها قبل الاعتماد. راجعوا الملاحظات في الإعدادات ثم أعيدوا طلب المراجعة.',
            FactoryApprovalStatus::Suspended => 'أوقف مركز تحديث الصناعة اعتماد منشأتكم مؤقتًا، ولا يمكن إرسال طلبات خدمة جديدة حتى إعادة الاعتماد.',
            FactoryApprovalStatus::Pending => 'ملف منشأتكم قيد المراجعة لدى مركز تحديث الصناعة.',
        };

        PlatformNotifier::factoryMembers(
            $factory->id,
            NotificationEvent::FactoryApprovalChanged,
            "factory.{$factory->id}.approval.{$factory->approval_status->value}.".($factory->approval_changed_at?->getTimestamp() ?? 0),
            $body,
            '/factory/settings',
            ['type' => 'factory', 'id' => $factory->id],
        );
    }

    /**
     * A provider listed new services: they wait for IMC review (ADR-021).
     *
     * @param  list<int>  $catalogServiceIds
     */
    public static function serviceListingsSubmitted(ServiceProvider $provider, array $catalogServiceIds): void
    {
        sort($catalogServiceIds);
        $count = count($catalogServiceIds);

        PlatformNotifier::imcWith(
            Permission::ServiceListingsReview,
            NotificationEvent::ServiceListingSubmitted,
            "service_provider.{$provider->id}.listings_submitted.".implode('-', $catalogServiceIds).'.'.now()->getTimestamp(),
            $count === 1
                ? "أضاف مزود الخدمة «{$provider->name}» خدمة جديدة بانتظار المراجعة."
                : "أضاف مزود الخدمة «{$provider->name}» {$count} خدمات جديدة بانتظار المراجعة.",
            '/admin/services?listing_status=pending',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
    }

    /**
     * IMC decided on one of the provider's listings.
     */
    public static function serviceListingReviewed(ServiceProvider $provider, CatalogService $service, ServiceListingStatus $status): void
    {
        $body = match ($status) {
            ServiceListingStatus::Approved => "اعتمد مركز تحديث الصناعة خدمة «{$service->name_ar}» في ملفكم، وأصبحت ظاهرة للمصانع المؤهلة متى كانت شركتكم معتمدة.",
            ServiceListingStatus::Rejected => "رفض مركز تحديث الصناعة إدراج خدمة «{$service->name_ar}» في ملفكم. راجعوا السبب في صفحة خدماتي.",
            ServiceListingStatus::Suspended => "أوقف مركز تحديث الصناعة ظهور خدمة «{$service->name_ar}» للمصانع مؤقتًا. راجعوا السبب في صفحة خدماتي.",
            ServiceListingStatus::Pending => "خدمة «{$service->name_ar}» قيد المراجعة لدى مركز تحديث الصناعة.",
        };

        PlatformNotifier::providerMembers(
            $provider->id,
            NotificationEvent::ServiceListingReviewed,
            "service_listing.{$provider->id}.{$service->id}.{$status->value}.".now()->getTimestamp(),
            $body,
            '/provider/services',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
    }

    /**
     * The provider sent a rejected listing back to review: IMC reviewers are told, and
     * the provider's members get the confirmation.
     */
    /**
     * The provider changed the packages and prices of a listing (ADR-027), which is back
     * in IMC's review queue. The text names the provider and the service, never a price.
     */
    public static function serviceListingPackagesChanged(ServiceProvider $provider, CatalogService $service): void
    {
        PlatformNotifier::imcWith(
            Permission::ServiceListingsReview,
            NotificationEvent::ServiceListingPackagesChanged,
            "service_listing.{$provider->id}.{$service->id}.packages_changed.".now()->getTimestamp(),
            "حدّث مزود الخدمة «{$provider->name}» باقات وأسعار خدمة «{$service->name_ar}»، وهي بانتظار المراجعة.",
            '/admin/services?listing_status=pending',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
    }

    public static function serviceListingResubmitted(ServiceProvider $provider, CatalogService $service): void
    {
        $key = "service_listing.{$provider->id}.{$service->id}.resubmitted.".now()->getTimestamp();

        PlatformNotifier::imcWith(
            Permission::ServiceListingsReview,
            NotificationEvent::ServiceListingResubmitted,
            $key,
            "أعاد مزود الخدمة «{$provider->name}» تقديم خدمة «{$service->name_ar}» للمراجعة بعد رفضها.",
            '/admin/services?listing_status=pending',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
        PlatformNotifier::providerMembers(
            $provider->id,
            NotificationEvent::ServiceListingResubmitted,
            $key,
            "أُعيد تقديم خدمة «{$service->name_ar}» إلى مركز تحديث الصناعة، وهي الآن بانتظار الاعتماد.",
            '/provider/services',
            ['type' => 'service_provider', 'id' => $provider->id],
        );
    }

    /**
     * An IMC promotion of the provider's listing started or ended.
     */
    public static function promotionChanged(ServicePromotion $promotion, bool $ended): void
    {
        $serviceName = CatalogService::query()->whereKey($promotion->catalog_service_id)->value('name_ar');

        PlatformNotifier::providerMembers(
            $promotion->service_provider_id,
            $ended ? NotificationEvent::PromotionEnded : NotificationEvent::PromotionStarted,
            "promotion.{$promotion->id}.".($ended ? 'ended' : 'created'),
            $ended
                ? "انتهى إعلان خدمة «{$serviceName}» في بوابة المصانع."
                : "أضاف مركز تحديث الصناعة إعلانًا لخدمة «{$serviceName}» يظهر للمصانع المؤهلة بعلامة «إعلان».",
            '/provider/services',
            ['type' => 'service_promotion', 'id' => $promotion->id],
        );
    }

    /**
     * The factory completed a readiness assessment: its members get the result's link.
     * The score and category are on the page, not in the email.
     */
    public static function readinessAssessmentCompleted(int $factoryId, int $assessmentId): void
    {
        PlatformNotifier::factoryMembers(
            $factoryId,
            NotificationEvent::ReadinessAssessmentCompleted,
            "readiness_assessment.{$assessmentId}.completed",
            'سُجّل تقييم الجاهزية الرقمية لمنشأتكم وحُدّد مستوى الجاهزية. اطّلعوا على مستوى منشأتكم والخدمات الموصى بها.',
            '/factory/assessment',
            ['type' => 'readiness_assessment', 'id' => $assessmentId],
        );
    }
}
