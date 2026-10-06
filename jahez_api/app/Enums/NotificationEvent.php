<?php

namespace App\Enums;

/**
 * Platform events that notify an account (ADR-020, extended by ADR-021): stored in-app
 * and, unless the recipient turned email off, emailed. The text names records and
 * parties only: never message text, offer terms, prices or documents.
 */
enum NotificationEvent: string
{
    case RequestReceived = 'request_received';
    case RequestAccepted = 'request_accepted';
    case RequestDeclined = 'request_declined';
    case RequestWithdrawn = 'request_withdrawn';
    case RequestClosed = 'request_closed';
    case MessageReceived = 'message_received';
    case OfferSubmitted = 'offer_submitted';
    case OfferAccepted = 'offer_accepted';
    case AgreementAwaitingReview = 'agreement_awaiting_review';
    case AgreementApproved = 'agreement_approved';
    case AgreementRejected = 'agreement_rejected';
    case ContractDrafted = 'contract_drafted';
    case ContractCancelled = 'contract_cancelled';
    case InvoiceIssued = 'invoice_issued';
    case PaymentConfirmed = 'payment_confirmed';
    case ProviderApprovalChanged = 'provider_approval_changed';
    case ChangeRequestSubmitted = 'change_request_submitted';
    case ChangeRequestApproved = 'change_request_approved';
    case ChangeRequestRejected = 'change_request_rejected';
    case OrganizationRegistered = 'organization_registered';
    case ReviewRequested = 'review_requested';
    case FactoryApprovalChanged = 'factory_approval_changed';
    case ServiceListingSubmitted = 'service_listing_submitted';
    case ServiceListingReviewed = 'service_listing_reviewed';
    case ServiceListingResubmitted = 'service_listing_resubmitted';
    case ServiceListingPackagesChanged = 'service_listing_packages_changed';
    case PromotionStarted = 'promotion_started';
    case PromotionEnded = 'promotion_ended';
    case ReadinessAssessmentCompleted = 'readiness_assessment_completed';
    case ReadinessLevelUnlocked = 'readiness_level_unlocked';
    case TransformationPlanPublished = 'transformation_plan_published';
    case TransformationPlanStatusChanged = 'transformation_plan_status_changed';
    case TransformationPlanItemUpdated = 'transformation_plan_item_updated';

    /**
     * The Arabic title shown in the list and used as the email subject.
     */
    public function title(): string
    {
        return match ($this) {
            self::RequestReceived => 'طلب خدمة جديد من مصنع',
            self::RequestAccepted => 'قبل المزود طلبكم وفُتح التفاوض',
            self::RequestDeclined => 'اعتذر المزود عن طلبكم',
            self::RequestWithdrawn => 'سحب المصنع طلبه',
            self::RequestClosed => 'أُغلق الطلب',
            self::MessageReceived => 'رسالة تفاوض جديدة',
            self::OfferSubmitted => 'عرض تجاري جديد على طلبكم',
            self::OfferAccepted => 'قبل المصنع عرضكم',
            self::AgreementAwaitingReview => 'اتفاقية بانتظار مراجعة المركز',
            self::AgreementApproved => 'اعتمد مركز تحديث الصناعة الاتفاقية',
            self::AgreementRejected => 'رفض مركز تحديث الصناعة الاتفاقية',
            self::ContractDrafted => 'مسودة عقد جديدة',
            self::ContractCancelled => 'أُلغيت مسودة العقد',
            self::InvoiceIssued => 'فاتورة جديدة صادرة',
            self::PaymentConfirmed => 'تأكيد سداد فاتورة',
            self::ProviderApprovalChanged => 'تحديث حالة اعتماد الشركة',
            self::ChangeRequestSubmitted => 'طلب تعديل بيانات قانونية بانتظار المراجعة',
            self::ChangeRequestApproved => 'اعتُمد طلب تعديل البيانات القانونية',
            self::ChangeRequestRejected => 'رُفض طلب تعديل البيانات القانونية',
            self::OrganizationRegistered => 'تسجيل جديد بانتظار المراجعة',
            self::ReviewRequested => 'طلب إعادة مراجعة ملف',
            self::FactoryApprovalChanged => 'تحديث حالة اعتماد المنشأة',
            self::ServiceListingSubmitted => 'خدمة جديدة بانتظار المراجعة',
            self::ServiceListingReviewed => 'تحديث حالة إحدى خدماتكم',
            self::ServiceListingResubmitted => 'أُعيد تقديم خدمة للمراجعة',
            self::ServiceListingPackagesChanged => 'تحديث باقات وأسعار خدمة بانتظار المراجعة',
            self::PromotionStarted => 'بدأ إعلان لإحدى خدماتكم',
            self::PromotionEnded => 'انتهى إعلان لإحدى خدماتكم',
            self::ReadinessAssessmentCompleted => 'سُجّل تقييم الجاهزية الرقمية',
            self::ReadinessLevelUnlocked => 'فُتح مستوى جاهزية جديد لمنشأتكم',
            self::TransformationPlanPublished => 'نُشرت خطة التحول الرقمي لمنشأتكم',
            self::TransformationPlanStatusChanged => 'تحديث حالة خطة التحول الرقمي',
            self::TransformationPlanItemUpdated => 'تحديث حالة خدمة في خطة التحول الرقمي',
        };
    }
}
