<?php

namespace App\Enums;

/**
 * Security-relevant actions recorded in the audit log (ADR-012).
 */
enum AuditEvent: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginThrottled = 'auth.login_throttled';
    case LoggedOut = 'auth.logged_out';
    case PasswordResetRequested = 'auth.password_reset_requested';
    case PasswordResetCompleted = 'auth.password_reset_completed';
    case UserCreated = 'user.created';
    case UserRenamed = 'user.renamed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case AdministratorCreatedFromConsole = 'user.administrator_created_from_console';
    case FactoryCreated = 'factory.created';
    case FactoryRegistered = 'factory.registered';
    case FactoryUpdated = 'factory.updated';
    case FactoryApprovalChanged = 'factory.approval_changed';
    case FactoryReviewRequested = 'factory.review_requested';
    case FactoryChangeRequestSubmitted = 'factory.change_request_submitted';
    case FactoryChangeRequestApproved = 'factory.change_request_approved';
    case FactoryChangeRequestRejected = 'factory.change_request_rejected';
    case FactoryChangeRequestCancelled = 'factory.change_request_cancelled';
    /**
     * Legacy: manual IMC classifications (ADR-016), superseded by ADR-018 and no longer
     * written. Kept so earlier audit entries still load.
     */
    case FactoryAssessmentRecorded = 'factory.assessment_recorded';
    case ReadinessAssessmentCompleted = 'factory.readiness_assessment_completed';
    case ReadinessLevelUnlocked = 'factory.readiness_level_unlocked';
    case ReadinessQuestionnaireDrafted = 'readiness_questionnaire.drafted';
    case ReadinessQuestionnaireUpdated = 'readiness_questionnaire.updated';
    case ReadinessQuestionnairePublished = 'readiness_questionnaire.published';
    case ReadinessQuestionnaireDeleted = 'readiness_questionnaire.deleted';
    case ReadinessLevelServiceAssigned = 'readiness_level_service.assigned';
    case ReadinessLevelServiceUpdated = 'readiness_level_service.updated';
    case ReadinessLevelServiceRemoved = 'readiness_level_service.removed';
    case TransformationPlanCreated = 'transformation_plan.created';
    case TransformationPlanDeleted = 'transformation_plan.deleted';
    case TransformationPlanDraftStarted = 'transformation_plan.draft_started';
    case TransformationPlanDraftSaved = 'transformation_plan.draft_saved';
    case TransformationPlanDraftDiscarded = 'transformation_plan.draft_discarded';
    case TransformationPlanPublished = 'transformation_plan.published';
    case TransformationPlanStatusChanged = 'transformation_plan.status_changed';
    case TransformationPlanItemStatusChanged = 'transformation_plan.item_status_changed';
    case ServiceProviderCreated = 'service_provider.created';
    case ServiceProviderRegistered = 'service_provider.registered';
    case ServiceProviderUpdated = 'service_provider.updated';
    case ServiceProviderApprovalChanged = 'service_provider.approval_changed';
    case ServiceProviderReviewRequested = 'service_provider.review_requested';
    case ServiceListingReviewed = 'service_listing.reviewed';
    case ServiceListingResubmitted = 'service_listing.resubmitted';
    case ServiceListingPackagesUpdated = 'service_listing.packages_updated';
    case ServiceProviderEvaluationRecorded = 'service_provider.evaluation_recorded';
    case ProviderChangeRequestSubmitted = 'service_provider.change_request_submitted';
    case ProviderChangeRequestApproved = 'service_provider.change_request_approved';
    case ProviderChangeRequestRejected = 'service_provider.change_request_rejected';
    case ProviderChangeRequestCancelled = 'service_provider.change_request_cancelled';
    case OrganizationDocumentUploaded = 'organization.document_uploaded';
    case ServiceRequestCreated = 'service_request.created';
    case ServiceRequestCancelled = 'service_request.cancelled';
    case ServiceRequestProvidersAdded = 'service_request.providers_added';
    case ProviderRequestAccepted = 'provider_request.accepted';
    case ProviderRequestDeclined = 'provider_request.declined';
    case ProviderRequestWithdrawn = 'provider_request.withdrawn';
    case OfferSubmitted = 'offer.submitted';
    case OfferAccepted = 'offer.accepted';
    case AgreementConcluded = 'agreement.concluded';
    case AgreementReviewed = 'agreement.reviewed';
    case PromotionCreated = 'promotion.created';
    case PromotionUpdated = 'promotion.updated';
    case PromotionEnded = 'promotion.ended';
    case AnnouncementCreated = 'announcement.created';
    case AnnouncementUpdated = 'announcement.updated';
    case AnnouncementPublished = 'announcement.published';
    case AnnouncementUnpublished = 'announcement.unpublished';
    case AnnouncementDeleted = 'announcement.deleted';
    case ContractDrafted = 'contract.drafted';
    case ContractCancelled = 'contract.cancelled';
    case InvoiceDrafted = 'invoice.drafted';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceCancelled = 'invoice.cancelled';
    case PaymentInitiated = 'payment.initiated';
    case PaymentStatusChanged = 'payment.status_changed';
    case PaymentRecordedManually = 'payment.recorded_manually';
    case FinancialPolicyCreated = 'financial_policy.created';
    case FinancialPolicyVersionDrafted = 'financial_policy.version_drafted';
    case FinancialPolicyVersionUpdated = 'financial_policy.version_updated';
    case FinancialPolicyVersionSubmitted = 'financial_policy.version_submitted';
    case FinancialPolicyVersionApproved = 'financial_policy.version_approved';
    case FinancialPolicyVersionRejected = 'financial_policy.version_rejected';
    case FinancialPolicyVersionArchived = 'financial_policy.version_archived';
    case FinancialPolicyVersionEnded = 'financial_policy.version_ended';
    case FinancialPolicyVersionSuperseded = 'financial_policy.version_superseded';
    case PermissionGranted = 'user.permission_granted';
    case PermissionRevoked = 'user.permission_revoked';
}
