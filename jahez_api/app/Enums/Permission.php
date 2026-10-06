<?php

namespace App\Enums;

/**
 * Named platform-wide permissions (ADR-006). Policies check these rather than roles,
 * so IMC administration can later be split by department (docs/open-questions.md OQ-21)
 * by changing Role::permissions() only.
 */
enum Permission: string
{
    case FactoriesViewAny = 'factories.view_any';
    case FactoriesCreate = 'factories.create';
    case FactoriesUpdate = 'factories.update';
    case FactoriesApprove = 'factories.approve';
    case ServiceProvidersViewAny = 'service_providers.view_any';
    case ServiceProvidersCreate = 'service_providers.create';
    case ServiceProvidersUpdate = 'service_providers.update';
    case ServiceProvidersApprove = 'service_providers.approve';
    case ServiceProvidersEvaluate = 'service_providers.evaluate';
    case ServiceListingsReview = 'service_listings.review';
    case AssessmentsViewAny = 'assessments.view_any';
    case ReadinessQuestionnairesManage = 'readiness_questionnaires.manage';
    case ReadinessServicesManage = 'readiness_services.manage';
    case TransformationPlansViewAny = 'transformation_plans.view_any';
    case TransformationPlansManage = 'transformation_plans.manage';
    case ServiceRequestsViewAny = 'service_requests.view_any';
    case AgreementsViewAny = 'agreements.view_any';
    case AgreementsReview = 'agreements.review';
    case PromotionsManage = 'promotions.manage';
    case AnnouncementsManage = 'announcements.manage';
    case InvoicesViewAny = 'invoices.view_any';
    case InvoicesManage = 'invoices.manage';
    case FinancialPoliciesView = 'financial_policies.view';
    case FinancialPoliciesManage = 'financial_policies.manage';
    case FinancialPoliciesApprove = 'financial_policies.approve';
    case PaymentsRecord = 'payments.record';
    case UsersViewAny = 'users.view_any';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case AuditLogsView = 'audit_logs.view';

    /**
     * Permissions no role holds: each is granted to one IMC administrator at a time
     * (user_permission_grants, `php artisan jahez:permissions`), so preparing a financial
     * rule, approving it and recording money received can be given to different people
     * (ADR-023). A grant is valid only for an active IMC administrator.
     *
     * @return list<self>
     */
    public static function grantedIndividually(): array
    {
        return [self::FinancialPoliciesManage, self::FinancialPoliciesApprove, self::PaymentsRecord];
    }
}
