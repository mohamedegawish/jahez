// One typed function per route in jahez_api/routes/api.php (all under /api/v1).
//
// Conventions (docs/api-conventions.md):
//   - single resources are unwrapped from `{ data }`; lists return the full paginated body;
//   - status changes are explicit action endpoints: a `status` field is never sent;
//   - the payment-gateway callback (`POST /payment-gateways/{gateway}/callback`) is
//     server-to-server and intentionally has no browser binding.

import type { ApiClient, Query, RequestOptions } from './client';
import type {
  AccountSettingsPayload,
  AgreementFinancialReadiness,
  FinancialPolicy,
  FinancialPolicyCreatePayload,
  FinancialPolicyDraftPayload,
  FinancialPolicyHistoryEntry,
  FinancialPolicyKind,
  FinancialPolicyResolution,
  FinancialPolicyScopeType,
  FinancialPolicyVersion,
  FinancialPreview,
  FinancialVersionPreview,
  ManualPaymentPayload,
  Agreement,
  AgreementReviewStatus,
  AppNotification,
  FactoryChangeRequest,
  ListingDecisionPayload,
  MarketplaceReport,
  ReadinessAnalytics,
  ReviewSummary,
  AdminAnnouncement,
  AnnouncementPayload,
  PublicAnnouncement,
  NotificationPage,
  ServiceListingPage,
  ServicePromotion,
  ServicePromotionPayload,
  ApprovalDecisionPayload,
  AuditLogEntry,
  AuditLogQuery,
  BillingConfiguration,
  CatalogService,
  Contract,
  ContractDraftPayload,
  CurrentUser,
  CursorPaginated,
  DataEnvelope,
  DocumentType,
  EvaluationCriterion,
  Factory,
  FactorySummary,
  FactoryCreatePayload,
  FactorySize,
  FactoryUpdatePayload,
  Invoice,
  InvoiceLinePayload,
  LegacyFactoryAssessment,
  LoginPayload,
  LoginResult,
  MaturityTier,
  MessageResult,
  NegotiationMessage,
  Offer,
  OfferPayload,
  OrganizationDocument,
  Paginated,
  Pathway,
  Payment,
  ProviderDirectoryEntry,
  ProviderChangeRequest,
  ProviderEvaluation,
  ProviderEvaluationPayload,
  ProviderRequest,
  ProviderRequestTransition,
  ReadinessAssessment,
  ReadinessDefinitionPayload,
  ReadinessQuestionnaire,
  ReadinessQuestionnaireVersion,
  ReadinessSubmitPayload,
  RegistrationOptions,
  Sector,
  ServiceCategory,
  ServiceProvider,
  ServiceProviderPayload,
  ServiceRequest,
  ServiceRequestCreatePayload,
  User,
  UserCreatePayload,
  UserUpdatePayload,
} from './types';

type Signal = { signal?: AbortSignal };

/** `?page=&per_page=&search=&sort=&filter[...]=`; `filter` keys are allow-listed per endpoint. */
export interface ListQuery<F extends object = Record<string, never>> extends Signal {
  page?: number;
  per_page?: number;
  search?: string;
  sort?: string;
  filter?: F;
}

export function createEndpoints(client: Pick<ApiClient, 'request'>) {
  const send = <T>(method: Parameters<ApiClient['request']>[0], path: string, options?: RequestOptions) =>
    client.request<T>(method, path, options);

  const get = <T>(path: string, query?: Query, signal?: AbortSignal) => send<T>('GET', path, { query, signal });
  const data = async <T>(promise: Promise<DataEnvelope<T>>): Promise<T> => (await promise).data;

  /** Strip our own `signal` and hand the rest to the query serializer. */
  const listQuery = (params: ListQuery<object> = {}): Query => {
    const { signal: _signal, ...rest } = params;
    return rest as Query;
  };

  const reasonBody = (reason?: string | null) => (reason ? { reason } : {});

  /** One organization document as multipart form data. */
  const documentForm = (type: DocumentType, file: File): FormData => {
    const form = new FormData();
    form.append('type', type);
    form.append('file', file);
    return form;
  };

  /** A private file, read with the session token. */
  const file = (path: string, signal?: AbortSignal) => send<Blob>('GET', path, { signal, responseType: 'blob' });

  return {
    // ─── Health ───
    // Public: never sends the session token and never triggers 401 handling.
    health: (signal?: AbortSignal) =>
      send<DataEnvelope<{ status: string; checks: Record<string, string> }>>('GET', '/health', { signal, auth: false }),

    // ─── Authentication ───
    auth: {
      login: (payload: LoginPayload) =>
        data(send<DataEnvelope<LoginResult>>('POST', '/auth/login', { body: payload, auth: false })),
      logout: () => send<void>('POST', '/auth/logout'),
      forgotPassword: (email: string) =>
        data(send<DataEnvelope<MessageResult>>('POST', '/auth/forgot-password', { body: { email }, auth: false })),
      resetPassword: (payload: { token: string; email: string; password: string; password_confirmation: string }) =>
        data(send<DataEnvelope<MessageResult>>('POST', '/auth/reset-password', { body: payload, auth: false })),
    },

    // ─── Public self-registration (ADR-019) ───
    // The registration endpoints answer 202 with the same message whether or not the email is new;
    // the account owner receives an email with a link to set the password.
    registration: {
      options: (signal?: AbortSignal) =>
        data(send<DataEnvelope<RegistrationOptions>>('GET', '/registration/options', { signal, auth: false })),
      factory: (form: FormData) =>
        data(send<DataEnvelope<MessageResult>>('POST', '/registration/factories', { body: form, auth: false })),
      serviceProvider: (form: FormData) =>
        data(send<DataEnvelope<MessageResult>>('POST', '/registration/service-providers', { body: form, auth: false })),
    },

    // ─── Current user ───
    me: (signal?: AbortSignal) => data(get<DataEnvelope<CurrentUser>>('/me', undefined, signal)),

    // ─── Factories ───
    factories: {
      list: (p: ListQuery<{ sector?: string; size?: string; approval_status?: string; readiness?: string }> = {}) =>
        get<Paginated<FactorySummary>>('/factories', listQuery(p), p.signal),
      /** IMC decision on the account (ADR-021). Never changes the readiness classification. */
      decide: (id: number, payload: ApprovalDecisionPayload) =>
        data(send<DataEnvelope<Factory>>('POST', `/factories/${id}/approval`, { body: payload })),
      /** A rejected factory, or one asked for corrections, asks IMC for a new review. */
      requestReview: (id: number, note?: string | null) =>
        data(send<DataEnvelope<Factory>>('POST', `/factories/${id}/review-request`, { body: note ? { note } : {} })),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<Factory>>(`/factories/${id}`, undefined, signal)),
      create: (payload: FactoryCreatePayload) => data(send<DataEnvelope<Factory>>('POST', '/factories', { body: payload })),
      update: (id: number, payload: FactoryUpdatePayload) =>
        data(send<DataEnvelope<Factory>>('PATCH', `/factories/${id}`, { body: payload })),
      /** Legacy manual classifications (ADR-016): read-only history. */
      legacyAssessments: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<LegacyFactoryAssessment[]>>(`/factories/${id}/assessments`, undefined, signal)),
      /** Upload or replace the logo or a registration document. */
      uploadDocument: (id: number, type: DocumentType, upload: File) =>
        data(send<DataEnvelope<OrganizationDocument>>('POST', `/factories/${id}/documents`, { body: documentForm(type, upload) })),
      documentFile: (id: number, documentId: number, signal?: AbortSignal) => file(`/factories/${id}/documents/${documentId}`, signal),
    },

    // ─── Digital readiness assessment (ADR-018): the server scores and classifies ───
    readiness: {
      questionnaire: (signal?: AbortSignal) =>
        data(get<DataEnvelope<ReadinessQuestionnaire>>('/readiness-questionnaire', undefined, signal)),
      list: (factoryId: number, p: ListQuery = {}) =>
        get<Paginated<ReadinessAssessment>>(`/factories/${factoryId}/readiness-assessments`, listQuery(p), p.signal),
      get: (factoryId: number, assessmentId: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ReadinessAssessment>>(`/factories/${factoryId}/readiness-assessments/${assessmentId}`, undefined, signal)),
      /**
       * `idempotencyKey` (8–100 of A-Za-z0-9_-) makes a repeated submission of the same attempt
       * return the assessment already stored instead of recording a second one.
       */
      submit: (factoryId: number, payload: ReadinessSubmitPayload, idempotencyKey?: string) =>
        data(
          send<DataEnvelope<ReadinessAssessment>>('POST', `/factories/${factoryId}/readiness-assessments`, {
            body: payload,
            headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
          }),
        ),
      /** IMC: submitted assessments across factories (ADR-021). `current` keeps each factory's latest. */
      listAll: (
        p: ListQuery<{ category?: string; version?: number; current?: boolean; from?: string; to?: string; score_min?: number; score_max?: number; factory?: number }> = {},
      ) =>
        get<Paginated<ReadinessAssessment>>('/readiness-assessments', listQuery(p), p.signal),
      /** IMC analytics, computed from the stored assessments. */
      analytics: (p: { from?: string; to?: string; signal?: AbortSignal } = {}) =>
        data(get<DataEnvelope<ReadinessAnalytics>>('/readiness-analytics', { filter: { from: p.from, to: p.to } }, p.signal)),
    },

    // ─── Questionnaire versions (IMC; ADR-018 addendum): drafts change, published versions never do ───
    readinessVersions: {
      list: (signal?: AbortSignal) =>
        data(get<DataEnvelope<ReadinessQuestionnaireVersion[]>>('/readiness-questionnaires', undefined, signal)),
      get: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ReadinessQuestionnaireVersion>>(`/readiness-questionnaires/${id}`, undefined, signal)),
      /** A new draft, copied from the current version. One draft at a time (409 otherwise). */
      createDraft: () => data(send<DataEnvelope<ReadinessQuestionnaireVersion>>('POST', '/readiness-questionnaires')),
      update: (id: number, payload: ReadinessDefinitionPayload) =>
        data(send<DataEnvelope<ReadinessQuestionnaireVersion>>('PUT', `/readiness-questionnaires/${id}`, { body: payload })),
      publish: (id: number) =>
        data(send<DataEnvelope<ReadinessQuestionnaireVersion>>('POST', `/readiness-questionnaires/${id}/publish`)),
      remove: (id: number) => send<void>('DELETE', `/readiness-questionnaires/${id}`),
    },

    /** Landing-page announcements (ADR-022). `public*` need no session and never trigger 401 handling. */
    announcements: {
      publicList: (signal?: AbortSignal) =>
        data(send<DataEnvelope<PublicAnnouncement[]>>('GET', '/public/announcements', { signal, auth: false })),
      publicGet: (id: number, signal?: AbortSignal) =>
        data(send<DataEnvelope<PublicAnnouncement>>('GET', `/public/announcements/${id}`, { signal, auth: false })),
      list: (p: ListQuery<{ published?: boolean }> = {}) => get<Paginated<AdminAnnouncement>>('/announcements', listQuery(p), p.signal),
      create: (payload: AnnouncementPayload) => data(send<DataEnvelope<AdminAnnouncement>>('POST', '/announcements', { body: payload })),
      update: (id: number, payload: AnnouncementPayload) => data(send<DataEnvelope<AdminAnnouncement>>('PATCH', `/announcements/${id}`, { body: payload })),
      publish: (id: number) => data(send<DataEnvelope<AdminAnnouncement>>('POST', `/announcements/${id}/publish`)),
      unpublish: (id: number) => data(send<DataEnvelope<AdminAnnouncement>>('POST', `/announcements/${id}/unpublish`)),
      remove: (id: number) => send<void>('DELETE', `/announcements/${id}`),
      uploadCover: (id: number, upload: File) => {
        const form = new FormData();
        form.append('file', upload);
        return data(send<DataEnvelope<AdminAnnouncement>>('POST', `/announcements/${id}/cover`, { body: form }));
      },
      removeCover: (id: number) => data(send<DataEnvelope<AdminAnnouncement>>('DELETE', `/announcements/${id}/cover`)),
      /** A draft's cover for the admin preview, read with the session token. */
      cover: (coverPath: string, signal?: AbortSignal) => file(coverPath, signal),
    },

    /** IMC: the size of every review queue (providers, factories, listings, change requests) in one call. */
    reviewSummary: (signal?: AbortSignal) => data(get<DataEnvelope<ReviewSummary>>('/review-summary', undefined, signal)),

    // ─── Service providers ───
    serviceProviders: {
      list: (p: ListQuery<{ approval_status?: string; sector?: string; service?: string; category?: string; listing_status?: string }> = {}) =>
        get<Paginated<ServiceProvider>>('/service-providers', listQuery(p), p.signal),
      /** IMC decision on one listed service (ADR-021). */
      reviewListing: (id: number, catalogServiceId: number, payload: ListingDecisionPayload) =>
        data(send<DataEnvelope<ServiceProvider>>('POST', `/service-providers/${id}/services/${catalogServiceId}/review`, { body: payload })),
      /** The provider sends a rejected listing back to review (ADR-022); 409 for any other status. */
      resubmitListing: (id: number, catalogServiceId: number, note?: string | null) =>
        data(send<DataEnvelope<ServiceProvider>>('POST', `/service-providers/${id}/services/${catalogServiceId}/resubmit`, { body: note ? { note } : {} })),
      get: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ServiceProvider>>(`/service-providers/${id}`, undefined, signal)),
      create: (payload: ServiceProviderPayload & { name: string }) =>
        data(send<DataEnvelope<ServiceProvider>>('POST', '/service-providers', { body: payload })),
      update: (id: number, payload: ServiceProviderPayload) =>
        data(send<DataEnvelope<ServiceProvider>>('PATCH', `/service-providers/${id}`, { body: payload })),
      /** IMC decision. Approval fields are never writable through `update`. */
      decide: (id: number, payload: ApprovalDecisionPayload) =>
        data(send<DataEnvelope<ServiceProvider>>('POST', `/service-providers/${id}/approval`, { body: payload })),
      /** A rejected provider asks IMC for a new review. */
      requestReview: (id: number, note?: string | null) =>
        data(send<DataEnvelope<ServiceProvider>>('POST', `/service-providers/${id}/review-request`, { body: note ? { note } : {} })),
      /**
       * Upload or replace the logo or a registration document. After IMC approval a member's legal
       * document answers 409: it goes through a change request instead.
       */
      uploadDocument: (id: number, type: DocumentType, upload: File) =>
        data(send<DataEnvelope<OrganizationDocument>>('POST', `/service-providers/${id}/documents`, { body: documentForm(type, upload) })),
      documentFile: (id: number, documentId: number, signal?: AbortSignal) =>
        file(`/service-providers/${id}/documents/${documentId}`, signal),
      changeRequests: {
        list: (id: number, p: ListQuery = {}) =>
          get<Paginated<ProviderChangeRequest>>(`/service-providers/${id}/change-requests`, listQuery(p), p.signal),
        /** Members of an approved provider: legal fields and/or registration documents as multipart. */
        create: (id: number, form: FormData) =>
          data(send<DataEnvelope<ProviderChangeRequest>>('POST', `/service-providers/${id}/change-requests`, { body: form })),
        approve: (id: number, requestId: number) =>
          data(send<DataEnvelope<ProviderChangeRequest>>('POST', `/service-providers/${id}/change-requests/${requestId}/approve`)),
        /** `reason` is required (≤2000). */
        reject: (id: number, requestId: number, reason: string) =>
          data(send<DataEnvelope<ProviderChangeRequest>>('POST', `/service-providers/${id}/change-requests/${requestId}/reject`, { body: { reason } })),
        cancel: (id: number, requestId: number) =>
          data(send<DataEnvelope<ProviderChangeRequest>>('POST', `/service-providers/${id}/change-requests/${requestId}/cancel`)),
      },
      evaluations: {
        list: (id: number, signal?: AbortSignal) =>
          data(get<DataEnvelope<ProviderEvaluation[]>>(`/service-providers/${id}/evaluations`, undefined, signal)),
        create: (id: number, payload: ProviderEvaluationPayload) =>
          data(send<DataEnvelope<ProviderEvaluation>>('POST', `/service-providers/${id}/evaluations`, { body: payload })),
      },
    },

    // ─── IMC queue of provider change requests (default filter: pending) ───
    providerChangeRequests: {
      list: (p: ListQuery<{ status?: ProviderChangeRequest['status'] }> = {}) =>
        get<Paginated<ProviderChangeRequest>>('/provider-change-requests', listQuery(p), p.signal),
    },

    // ─── Accounts ───
    users: {
      list: (p: ListQuery = {}) => get<Paginated<User>>('/users', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<User>>(`/users/${id}`, undefined, signal)),
      create: (payload: UserCreatePayload) => data(send<DataEnvelope<User>>('POST', '/users', { body: payload })),
      update: (id: number, payload: UserUpdatePayload) =>
        data(send<DataEnvelope<User>>('PATCH', `/users/${id}`, { body: payload })),
    },

    // ─── Reference data (bounded lists, not paginated) ───
    reference: {
      sectors: (signal?: AbortSignal) => data(get<DataEnvelope<Sector[]>>('/reference/sectors', undefined, signal)),
      factorySizes: (signal?: AbortSignal) => data(get<DataEnvelope<FactorySize[]>>('/reference/factory-sizes', undefined, signal)),
      maturityTiers: (signal?: AbortSignal) => data(get<DataEnvelope<MaturityTier[]>>('/reference/maturity-tiers', undefined, signal)),
      pathways: (signal?: AbortSignal) => data(get<DataEnvelope<Pathway[]>>('/reference/pathways', undefined, signal)),
      evaluationCriteria: (signal?: AbortSignal) =>
        data(get<DataEnvelope<EvaluationCriterion[]>>('/reference/evaluation-criteria', undefined, signal)),
    },

    // ─── Catalog (7 categories, 42 services; fixed by ADR-014) ───
    catalog: {
      categories: (signal?: AbortSignal) => data(get<DataEnvelope<ServiceCategory[]>>('/catalog/categories', undefined, signal)),
      category: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ServiceCategory>>(`/catalog/categories/${id}`, undefined, signal)),
      /** `eligible` / `recommended` are for factory members only (422 for others). */
      services: (p: { filter?: { category?: string; eligible?: boolean; recommended?: boolean }; signal?: AbortSignal } = {}) =>
        data(get<DataEnvelope<CatalogService[]>>('/catalog/services', listQuery(p), p.signal)),
      service: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<CatalogService>>(`/catalog/services/${id}`, undefined, signal)),
    },

    // ─── Provider directory (factory members: eligible providers; IMC: every approved provider) ───
    directory: {
      list: (p: ListQuery<{ service?: string; category?: string; sector?: string; recommended?: boolean }> = {}) =>
        get<Paginated<ProviderDirectoryEntry>>('/provider-directory', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ProviderDirectoryEntry>>(`/provider-directory/${id}`, undefined, signal)),
      /** Only for entries with `has_logo`. */
      logo: (id: number, signal?: AbortSignal) => file(`/provider-directory/${id}/logo`, signal),
    },

    // ─── Marketplace requests (ADR-015) ───
    serviceRequests: {
      /** `search` matches the title or a provider's name; `sort`: newest | oldest. */
      list: (p: ListQuery<{ status?: string; service?: string; thread_status?: string }> = {}) =>
        get<Paginated<ServiceRequest>>('/service-requests', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<ServiceRequest>>(`/service-requests/${id}`, undefined, signal)),
      create: (payload: ServiceRequestCreatePayload) =>
        data(send<DataEnvelope<ServiceRequest>>('POST', '/service-requests', { body: payload })),
      cancel: (id: number, reason?: string | null) =>
        data(send<DataEnvelope<ServiceRequest>>('POST', `/service-requests/${id}/cancel`, { body: reasonBody(reason) })),
      addProviders: (id: number, providerIds: number[]) =>
        data(send<DataEnvelope<ServiceRequest>>('POST', `/service-requests/${id}/providers`, { body: { provider_ids: providerIds } })),
    },

    // ─── Provider requests (one thread per provider), messages and offers ───
    providerRequests: {
      /** `search` matches the title, factory or provider; `sort`: newest | oldest | recent_activity. */
      list: (p: ListQuery<{ status?: string; service_request?: number; unread?: boolean; service?: string }> = {}) =>
        get<Paginated<ProviderRequest>>('/provider-requests', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ProviderRequest>>(`/provider-requests/${id}`, undefined, signal)),
      /** Mark the thread read up to its latest message (parties only). */
      markRead: (id: number) => data(send<DataEnvelope<ProviderRequest>>('POST', `/provider-requests/${id}/read`)),
      accept: (id: number) => data(send<DataEnvelope<ProviderRequest>>('POST', `/provider-requests/${id}/accept`)),
      decline: (id: number, reason?: string | null) =>
        data(send<DataEnvelope<ProviderRequest>>('POST', `/provider-requests/${id}/decline`, { body: reasonBody(reason) })),
      withdraw: (id: number, reason?: string | null) =>
        data(send<DataEnvelope<ProviderRequest>>('POST', `/provider-requests/${id}/withdraw`, { body: reasonBody(reason) })),
      history: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<ProviderRequestTransition[]>>(`/provider-requests/${id}/history`, undefined, signal)),
      messages: {
        list: (id: number, p: ListQuery = {}) =>
          get<Paginated<NegotiationMessage>>(`/provider-requests/${id}/messages`, listQuery(p), p.signal),
        create: (id: number, body: string) =>
          data(send<DataEnvelope<NegotiationMessage>>('POST', `/provider-requests/${id}/messages`, { body: { body } })),
      },
      offers: {
        list: (id: number, signal?: AbortSignal) =>
          data(get<DataEnvelope<Offer[]>>(`/provider-requests/${id}/offers`, undefined, signal)),
        create: (id: number, payload: OfferPayload) =>
          data(send<DataEnvelope<Offer>>('POST', `/provider-requests/${id}/offers`, { body: payload })),
        /** Factory only. Concludes the agreement in the same transaction. */
        accept: (id: number, offerId: number) =>
          data(send<DataEnvelope<Offer>>('POST', `/provider-requests/${id}/offers/${offerId}/accept`)),
      },
    },

    // ─── Agreements and contract drafts (ADR-017) ───
    agreements: {
      list: (p: ListQuery<{ review_status?: AgreementReviewStatus; contract_status?: 'none' | 'draft' }> = {}) =>
        get<Paginated<Agreement>>('/agreements', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<Agreement>>(`/agreements/${id}`, undefined, signal)),
      /** IMC's final decision; `reason` is required to reject. */
      review: (id: number, decision: 'approved' | 'rejected', reason?: string | null) =>
        data(send<DataEnvelope<Agreement>>('POST', `/agreements/${id}/review`, { body: { decision, ...(reason ? { reason } : {}) } })),
      draftContract: (id: number, payload: ContractDraftPayload) =>
        data(send<DataEnvelope<Contract>>('POST', `/agreements/${id}/contracts`, { body: payload })),
      /** For each financial operation: whether it is possible today, and every Arabic reason it is not (ADR-023). */
      financialReadiness: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<AgreementFinancialReadiness>>(`/agreements/${id}/financial-readiness`, undefined, signal)),
    },
    contracts: {
      list: (p: ListQuery<{ status?: string; agreement?: number }> = {}) =>
        get<Paginated<Contract>>('/contracts', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<Contract>>(`/contracts/${id}`, undefined, signal)),
      cancel: (id: number, reason?: string | null) =>
        data(send<DataEnvelope<Contract>>('POST', `/contracts/${id}/cancel`, { body: reasonBody(reason) })),
    },

    // ─── Billing and payments (ADR-017): undecided rules answer 409 policy_not_configured ───
    billing: {
      configuration: (signal?: AbortSignal) =>
        data(get<DataEnvelope<BillingConfiguration>>('/billing/configuration', undefined, signal)),
      draftInvoice: (agreementId: number) =>
        data(send<DataEnvelope<Invoice>>('POST', `/agreements/${agreementId}/invoices`)),
    },
    invoices: {
      /** `from`/`to`: UTC creation days; `counterparty`: the other party's id. */
      list: (p: ListQuery<{ status?: string; agreement?: number | string; service?: string; from?: string; to?: string; counterparty?: number | string }> = {}) =>
        get<Paginated<Invoice>>('/invoices', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<Invoice>>(`/invoices/${id}`, undefined, signal)),
      issue: (id: number) => data(send<DataEnvelope<Invoice>>('POST', `/invoices/${id}/issue`)),
      cancel: (id: number, reason?: string | null) =>
        data(send<DataEnvelope<Invoice>>('POST', `/invoices/${id}/cancel`, { body: reasonBody(reason) })),
      addLine: (id: number, payload: InvoiceLinePayload) =>
        data(send<DataEnvelope<Invoice>>('POST', `/invoices/${id}/lines`, { body: payload })),
      removeLine: (id: number, lineId: number) =>
        data(send<DataEnvelope<Invoice>>('DELETE', `/invoices/${id}/lines/${lineId}`)),
      payments: {
        list: (id: number, signal?: AbortSignal) =>
          data(get<DataEnvelope<Payment[]>>(`/invoices/${id}/payments`, undefined, signal)),
        /**
         * Factory party only. `idempotencyKey` (8–100 of A-Za-z0-9_-) makes a retry return the
         * same payment instead of starting another one; a new attempt needs a new key.
         */
        start: (id: number, idempotencyKey: string) =>
          data(send<DataEnvelope<Payment>>('POST', `/invoices/${id}/payments`, { headers: { 'Idempotency-Key': idempotencyKey } })),
        /**
         * IMC administrators granted payments.record: money received outside the platform, with its
         * evidence reference. The server checks the amount against what is outstanding (ADR-023).
         */
        recordManual: (id: number, payload: ManualPaymentPayload, idempotencyKey: string) =>
          data(send<DataEnvelope<Payment>>('POST', `/invoices/${id}/manual-payments`, { body: payload, headers: { 'Idempotency-Key': idempotencyKey } })),
      },
    },

    // ─── Financial and contract policies (ADR-023): drafts approved by a second administrator ───
    financialPolicies: {
      list: (p: ListQuery<{ kind?: FinancialPolicyKind; scope_type?: FinancialPolicyScopeType }> = {}) =>
        get<Paginated<FinancialPolicy>>('/financial-policies', listQuery(p), p.signal),
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<FinancialPolicy>>(`/financial-policies/${id}`, undefined, signal)),
      /** Creates the policy and its first draft version (returned). */
      create: (payload: FinancialPolicyCreatePayload) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', '/financial-policies', { body: payload })),
      draftVersion: (policyId: number, payload: FinancialPolicyDraftPayload) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policies/${policyId}/versions`, { body: payload })),
      resolve: (query: { kind: FinancialPolicyKind; catalog_service?: number; sectors?: string[]; service_provider?: number; date?: string }, signal?: AbortSignal) =>
        data(get<DataEnvelope<FinancialPolicyResolution>>('/financial-policies/resolve', query as Query, signal)),
      /** The server's calculation for an amount under the policies that apply; nothing is stored. */
      preview: (body: { amount: string; catalog_service?: number; sectors?: string[]; service_provider?: number; date?: string }) =>
        data(send<DataEnvelope<FinancialPreview>>('POST', '/financial-policies/preview', { body })),
    },
    financialPolicyVersions: {
      get: (id: number, signal?: AbortSignal) => data(get<DataEnvelope<FinancialPolicyVersion>>(`/financial-policy-versions/${id}`, undefined, signal)),
      update: (id: number, payload: Partial<FinancialPolicyDraftPayload>) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('PATCH', `/financial-policy-versions/${id}`, { body: payload })),
      submit: (id: number) => data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policy-versions/${id}/submit`)),
      approve: (id: number, note?: string | null) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policy-versions/${id}/approve`, { body: reasonBody(note) })),
      reject: (id: number, reason: string) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policy-versions/${id}/reject`, { body: { reason } })),
      archive: (id: number, reason: string) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policy-versions/${id}/archive`, { body: { reason } })),
      end: (id: number, effectiveTo: string, reason: string) =>
        data(send<DataEnvelope<FinancialPolicyVersion>>('POST', `/financial-policy-versions/${id}/end`, { body: { effective_to: effectiveTo, reason } })),
      history: (id: number, signal?: AbortSignal) =>
        data(get<DataEnvelope<FinancialPolicyHistoryEntry[]>>(`/financial-policy-versions/${id}/history`, undefined, signal)),
      preview: (id: number, amount: string) =>
        data(send<DataEnvelope<FinancialVersionPreview>>('POST', `/financial-policy-versions/${id}/preview`, { body: { amount } })),
    },

    // ─── Audit log (IMC only, cursor pagination, plain query parameters) ───
    auditLogs: {
      list: (query: AuditLogQuery = {}, signal?: AbortSignal) =>
        get<CursorPaginated<AuditLogEntry>>('/audit-logs', query as Query, signal),
    },

    // ─── Phase 2 portals (ADR-020) ───

    /** The signed-in account's own settings. */
    account: {
      update: (payload: AccountSettingsPayload) => data(send<DataEnvelope<CurrentUser>>('PATCH', '/me', { body: payload })),
    },

    /** The signed-in account's in-app notifications, newest first; `meta.unread_count`. */
    notifications: {
      list: (p: ListQuery<{ unread?: boolean }> = {}) => get<NotificationPage>('/notifications', listQuery(p), p.signal),
      read: (id: string) => data(send<DataEnvelope<AppNotification>>('POST', `/notifications/${id}/read`)),
      readAll: () => send<DataEnvelope<{ unread_count: number }>>('POST', '/notifications/read-all'),
    },

    /** Provider listings of catalog services: eligible ones for a factory (promoted first), own ones for a provider. */
    serviceListings: {
      list: (
        p: ListQuery<{ category?: string; service?: string; recommended?: boolean; promoted?: boolean; approval_status?: string; provider?: number; listing_status?: string }> = {},
      ) => get<ServiceListingPage>('/service-listings', listQuery(p), p.signal),
      /** A logo by the `logo_path` the listing gives (relative to the API base URL). */
      logo: (logoPath: string, signal?: AbortSignal) => file(logoPath, signal),
    },

    /** IMC promotions («إعلان») of provider listings. */
    promotions: {
      list: (p: ListQuery<{ state?: 'running' | 'ended' }> = {}) => get<Paginated<ServicePromotion>>('/promotions', listQuery(p), p.signal),
      create: (payload: ServicePromotionPayload) => data(send<DataEnvelope<ServicePromotion>>('POST', '/promotions', { body: payload })),
      update: (id: number, payload: { headline?: string | null; priority?: number; ends_at?: string | null }) =>
        data(send<DataEnvelope<ServicePromotion>>('PATCH', `/promotions/${id}`, { body: payload })),
      end: (id: number) => data(send<DataEnvelope<ServicePromotion>>('POST', `/promotions/${id}/end`)),
    },

    /** Marketplace report for the caller's own organization (IMC: all). Days are UTC `YYYY-MM-DD`. */
    reports: {
      marketplace: (p: { from?: string; to?: string; service?: string; signal?: AbortSignal } = {}) =>
        data(
          get<DataEnvelope<MarketplaceReport>>(
            '/reports/marketplace',
            { from: p.from, to: p.to, filter: p.service ? { service: p.service } : undefined },
            p.signal,
          ),
        ),
    },

    /** Reviewed changes to a factory's recorded legal information. */
    factoryChangeRequests: {
      queue: (p: ListQuery<{ status?: FactoryChangeRequest['status'] }> = {}) =>
        get<Paginated<FactoryChangeRequest>>('/factory-change-requests', listQuery(p), p.signal),
      list: (factoryId: number, p: ListQuery = {}) =>
        get<Paginated<FactoryChangeRequest>>(`/factories/${factoryId}/change-requests`, listQuery(p), p.signal),
      /** Legal fields and/or registration documents as multipart. */
      create: (factoryId: number, form: FormData) =>
        data(send<DataEnvelope<FactoryChangeRequest>>('POST', `/factories/${factoryId}/change-requests`, { body: form })),
      approve: (factoryId: number, requestId: number) =>
        data(send<DataEnvelope<FactoryChangeRequest>>('POST', `/factories/${factoryId}/change-requests/${requestId}/approve`)),
      reject: (factoryId: number, requestId: number, reason: string) =>
        data(send<DataEnvelope<FactoryChangeRequest>>('POST', `/factories/${factoryId}/change-requests/${requestId}/reject`, { body: { reason } })),
      cancel: (factoryId: number, requestId: number) =>
        data(send<DataEnvelope<FactoryChangeRequest>>('POST', `/factories/${factoryId}/change-requests/${requestId}/cancel`)),
    },
  };
}

export type Endpoints = ReturnType<typeof createEndpoints>;
