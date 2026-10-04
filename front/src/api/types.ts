// Contract types for the Jahez API (/api/v1).
//
// Transcribed field-for-field from jahez_api/app/Http/Resources/V1/* and app/Enums/*.
// Rules that matter to consumers:
//   - field names are snake_case and are NOT renamed here;
//   - record ids are integers;
//   - money is { amount: "250000.00", currency: "EGP" } (decimal STRING, never a float);
//   - timestamps are ISO 8601 UTC strings, plain dates are YYYY-MM-DD;
//   - fields marked optional (`?`) are only present when the API loads that relation or,
//     for a few party-only fields, when the viewer is allowed to see them.

// ───────────────────────── Envelopes ─────────────────────────

export interface DataEnvelope<T> {
  data: T;
}

export interface PageLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PageMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  path: string;
  per_page: number;
  to: number | null;
  total: number;
  links: { url: string | null; label: string; active: boolean }[];
}

/** Length-aware pagination (Laravel `paginate()`): used by every list except the audit log. */
export interface Paginated<T> {
  data: T[];
  links: PageLinks;
  meta: PageMeta;
}

export interface CursorMeta {
  path: string;
  per_page: number;
  next_cursor: string | null;
  prev_cursor: string | null;
}

/** Cursor pagination: only the audit log. Newest first. */
export interface CursorPaginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: CursorMeta;
}

// ───────────────────────── Money ─────────────────────────

export interface Money {
  amount: string;
  currency: 'EGP';
}

// ───────────────────────── Accounts ─────────────────────────

export type Role = 'imc_admin' | 'factory_member' | 'provider_member';

export type Permission =
  | 'factories.view_any'
  | 'factories.create'
  | 'factories.update'
  | 'factories.approve'
  | 'service_providers.view_any'
  | 'service_providers.create'
  | 'service_providers.update'
  | 'service_providers.approve'
  | 'service_providers.evaluate'
  | 'service_listings.review'
  | 'assessments.view_any'
  | 'readiness_questionnaires.manage'
  | 'service_requests.view_any'
  | 'agreements.view_any'
  | 'agreements.review'
  | 'promotions.manage'
  | 'announcements.manage'
  | 'invoices.view_any'
  | 'invoices.manage'
  | 'financial_policies.view'
  // Granted to individual administrators only, never by role (ADR-023).
  | 'financial_policies.manage'
  | 'financial_policies.approve'
  | 'payments.record'
  | 'users.view_any'
  | 'users.create'
  | 'users.update'
  | 'audit_logs.view';

export interface Organization {
  type: 'factory' | 'service_provider';
  id: number;
  name: string;
}

/** UserResource */
export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  organization: Organization | null;
  is_active: boolean;
  created_at: string | null;
}

/** CurrentUserResource: `GET /me` and the `user` inside the login response. */
export interface CurrentUser extends User {
  permissions: Permission[];
  /** Whether platform notifications are also emailed (ADR-020). */
  email_notifications: boolean;
}

/** `PATCH /me`: the account's own display name and email preference. */
export interface AccountSettingsPayload {
  name?: string;
  email_notifications?: boolean;
}

/** `POST /auth/login` → `data` */
export interface LoginResult {
  token_type: 'Bearer';
  access_token: string;
  /** ISO 8601 UTC. There are no refresh tokens: after this moment the user must log in again. */
  expires_at: string;
  user: CurrentUser;
}

export interface LoginPayload {
  email: string;
  password: string;
  /** Required by the API (≤100 chars). Names the token in the audit log. */
  device_name: string;
}

export interface MessageResult {
  message: string;
}

// ───────────────────────── Reference data ─────────────────────────

export interface Sector {
  code: string;
  name_ar: string;
  name_en: string | null;
}

export interface FactorySize {
  code: string;
  name_ar: string;
}

export interface PathwayRef {
  code: string;
  name_ar: string;
}

export interface PathwayLevelRef {
  code: string;
  name_ar: string;
  pathway: PathwayRef | null;
}

export interface MaturityTier {
  code: string;
  name_ar: string;
  name_en: string | null;
  readiness_band_ar: string | null;
  operational_state_ar: string | null;
  approved_path_ar: string | null;
  expected_impact_ar: string | null;
  pathway_level: PathwayLevelRef | null;
}

export interface PathwayScopeItem {
  group_ar: string | null;
  group_en: string | null;
  text_ar: string;
}

export interface PathwayLevel {
  code: string;
  name_ar: string;
  subtitle_ar: string | null;
  name_en: string | null;
  target_group_ar: string | null;
  scope_items: PathwayScopeItem[];
  provider_requirements: string[];
}

export interface Pathway {
  code: string;
  name_ar: string;
  name_en: string | null;
  target_group_ar: string | null;
  levels: PathwayLevel[];
}

export interface EvaluationCriterion {
  version: number;
  code: string;
  name_ar: string;
  name_en: string | null;
  sub_elements_ar: string | null;
  weight_percent: number | string;
  verification_ar: string | null;
}

// ───────────────────────── Catalog ─────────────────────────

export interface CatalogCategoryRef {
  id: number;
  code: string;
  name_ar: string;
  name_en: string | null;
}

/** CatalogServiceResource. `category` is present only where the API loads it. */
export interface CatalogService {
  id: number;
  code: string;
  name_ar: string;
  category?: CatalogCategoryRef | null;
}

/** ServiceCategoryResource */
export interface ServiceCategory extends CatalogCategoryRef {
  services?: CatalogService[];
}

// ───────────────────────── Readiness assessment (ADR-018) ─────────────────────────

export type ReadinessCategoryCode = 'b4_automation' | 'basic' | 'advanced' | 'smart';

export interface ReadinessRecommendation {
  position: number;
  text_ar: string;
  /** Empty when the catalog does not offer the recommended service (OQ-42). */
  services: CatalogService[];
}

export interface ReadinessRoadmap {
  focus_ar: string | null;
  steps_ar: string | null;
  recommendations: ReadinessRecommendation[];
}

/** ReadinessCategoryResource. `roadmap` is present only when recommendations are loaded. */
export interface ReadinessCategory {
  code: ReadinessCategoryCode;
  name_en: string;
  name_ar: string;
  description_ar: string | null;
  min_score: number;
  max_score: number;
  roadmap?: ReadinessRoadmap;
}

export interface ReadinessChoice {
  id: number;
  code: string;
  label_ar: string;
  text_ar: string;
  points: number;
}

export interface ReadinessQuestion {
  id: number;
  code: string;
  number: number;
  text_ar: string;
  choices: ReadinessChoice[];
}

export interface ReadinessPillar {
  code: string;
  name_ar: string;
  name_en: string | null;
  questions: ReadinessQuestion[];
}

/** `GET /readiness-questionnaire` */
export interface ReadinessQuestionnaire {
  version: number;
  title_ar: string;
  title_en: string | null;
  min_score: number;
  max_score: number;
  pillars: ReadinessPillar[];
  categories: ReadinessCategory[];
}

export interface ReadinessPillarScore {
  code: string;
  name_ar: string;
  name_en: string | null;
  score: number;
  max_score: number;
}

export interface ReadinessAnswerResult {
  question_id: number;
  question_code: string | null;
  question_number: number | null;
  /** The text the answer was given with (a snapshot; ADR-018 addendum 2). */
  question_text_ar?: string | null;
  choice_id: number;
  choice_code: string | null;
  choice_label_ar: string | null;
  choice_text_ar?: string | null;
  points: number;
}

/**
 * ReadinessAssessmentResource. List items are the summary; `show` and `store` add the
 * result fields (min/max score, pillars, answers).
 */
export interface ReadinessAssessment {
  id: number;
  factory_id: number;
  /** Only in the IMC list across factories (GET /readiness-assessments). */
  factory?: { id: number; name: string } | null;
  questionnaire_version: number | null;
  total_score: number;
  category: ReadinessCategory | null;
  submitted_by: { id: number; name: string } | null;
  completed_at: string;
  min_score?: number;
  max_score?: number;
  pillars?: ReadinessPillarScore[];
  answers?: ReadinessAnswerResult[];
}

export interface ReadinessAnswerPayload {
  question_id: number;
  choice_id: number;
}

export interface ReadinessSubmitPayload {
  questionnaire_version: number;
  answers: ReadinessAnswerPayload[];
}

/** ReadinessQuestionnaireVersionResource: a questionnaire version for IMC administrators. */
export interface ReadinessQuestionnaireVersion {
  id: number;
  version: number;
  title_ar: string;
  title_en: string | null;
  source_ref: string;
  /** `draft` can still change; `current` is what factories answer; `retired` was current before. */
  status: 'draft' | 'current' | 'retired';
  published_at: string | null;
  assessments_count?: number;
  /** The full structure, on `show`, `store`, `update` and `publish`. */
  definition?: Omit<ReadinessQuestionnaire, 'categories'> & {
    categories: (ReadinessCategory & { focus_ar: string; steps_ar: string })[];
  };
  /** Who drafted, last changed and published the version; null for the seeded source version. */
  created_by?: { id: number; name: string } | null;
  updated_by?: { id: number; name: string } | null;
  published_by?: { id: number; name: string } | null;
  created_at: string | null;
  updated_at?: string | null;
}

/** `PUT /readiness-questionnaires/{id}`: the whole definition of a draft, in display order. */
export interface ReadinessDefinitionPayload {
  title_ar: string;
  title_en?: string | null;
  pillars: {
    code: string;
    name_ar: string;
    name_en?: string | null;
    questions: {
      code: string;
      text_ar: string;
      choices: { code: string; label_ar: string; text_ar: string; points: number }[];
    }[];
  }[];
  categories: {
    code: ReadinessCategoryCode;
    name_ar: string;
    name_en: string;
    description_ar: string;
    min_score: number;
    max_score: number;
    focus_ar: string;
    steps_ar: string;
    /** Optional: without it the roadmap lines stay as they are. Services are catalog codes. */
    recommendations?: { text_ar: string; services: string[] }[];
  }[];
}

/** FactoryAssessmentResource: legacy manual classification, read-only since ADR-018. */
export interface LegacyFactoryAssessment {
  id: number;
  factory_id: number;
  classification_method: 'manual';
  score: null;
  maturity_tier: {
    code: string;
    name_ar: string;
    name_en: string | null;
    readiness_band_ar: string | null;
    approved_path_ar: string | null;
    pathway_level: PathwayLevelRef | null;
  } | null;
  justification: string | null;
  assessed_on: string;
  recorded_by: { id: number; name: string } | null;
  created_at: string;
}

// ───────────────────────── Organization documents (ADR-019) ─────────────────────────

export type DocumentType = 'logo' | 'commercial_registration' | 'tax_registration';

export type DocumentStatus = 'active' | 'pending_review' | 'superseded' | 'rejected';

/**
 * OrganizationDocumentResource: metadata only. The file itself is private and is read through
 * the organization's document endpoint with the session token (never a public URL).
 */
export interface OrganizationDocument {
  id: number;
  type: DocumentType;
  status: DocumentStatus;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  uploaded_at: string | null;
}

/** The current document of each type, or null where nothing was uploaded. */
export type OrganizationDocuments = Record<DocumentType, OrganizationDocument | null>;

// ───────────────────────── Self-registration (ADR-019) ─────────────────────────

/** `GET /registration/options` (public). */
export interface RegistrationOptions {
  sectors: Sector[];
  factory_sizes: FactorySize[];
  service_categories: ServiceCategory[];
  documents: {
    logo: { extensions: string[]; max_kb: number };
    legal: { extensions: string[]; max_kb: number };
  };
}

// ───────────────────────── Factories ─────────────────────────

export interface FactoryCurrentReadiness {
  assessment_id: number;
  questionnaire_version: number | null;
  total_score: number;
  category: { code: ReadinessCategoryCode; name_en: string; name_ar: string } | null;
  completed_at: string;
}

/** The onboarding steps of a factory (ADR-019): informational, never a block on the assessment. */
export interface FactoryOnboarding {
  /** Field names from the configured list (OQ-19), for example `sectors`. */
  missing_profile_fields: string[];
  profile_complete: boolean;
  readiness_status: 'not_started' | 'completed';
}

/** FactoryResource. `sectors`, `documents`, `current_readiness` and `onboarding` are present only when loaded. */
export interface Factory {
  id: number;
  name: string;
  legal_name: string | null;
  /** A code from `GET /reference/factory-sizes`, or null. */
  size: string | null;
  contact_name: string | null;
  contact_job_title: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  website: string | null;
  governorate: string | null;
  city: string | null;
  address: string | null;
  commercial_registration_number: string | null;
  tax_registration_number: string | null;
  sectors?: Sector[];
  documents?: OrganizationDocuments;
  current_readiness?: FactoryCurrentReadiness | null;
  onboarding?: FactoryOnboarding;
  approval: FactoryApproval;
  created_at: string | null;
  updated_at: string | null;
}

/**
 * FactorySummaryResource: a factory in the IMC list (`GET /factories`), what a card shows. No legal or
 * contact details and no document but the logo; those are on `GET /factories/{id}` only.
 */
export interface FactorySummary {
  id: number;
  name: string;
  size: string | null;
  governorate: string | null;
  city: string | null;
  sectors?: Sector[];
  /** The active logo, fetched with `api.factories.documentFile(id, logo.id)`. */
  logo?: { id: number; uploaded_at: string | null } | null;
  onboarding?: FactoryOnboarding;
  current_readiness?: FactoryCurrentReadiness | null;
  approval: FactoryApproval;
  /** Which profile fields are filled: field names and counts only, never values. */
  profile_completion?: { filled: number; total: number; missing: string[] };
  service_requests_count?: number;
  created_at: string | null;
  updated_at: string | null;
}

/** The registration details a factory member edits. */
export interface FactoryProfilePayload {
  legal_name?: string | null;
  contact_name?: string | null;
  contact_job_title?: string | null;
  contact_email?: string | null;
  contact_phone?: string | null;
  website?: string | null;
  governorate?: string | null;
  city?: string | null;
  address?: string | null;
  commercial_registration_number?: string | null;
  tax_registration_number?: string | null;
}

export interface FactoryCreatePayload {
  name: string;
  size?: string | null;
  sectors?: string[];
}

/** Members may send `name`, `sectors` and the profile fields; `size` is honoured for IMC admins only. */
export interface FactoryUpdatePayload extends FactoryProfilePayload {
  name?: string;
  sectors?: string[];
  size?: string | null;
}

// ───────────────────────── Service providers ─────────────────────────

/** `changes_requested` (ADR-021): IMC asked for corrections before deciding. */
export type ProviderApprovalStatus = 'pending' | 'approved' | 'rejected' | 'suspended' | 'changes_requested';

/** IMC review of a factory's account (ADR-021); the same states as a provider's. Never the readiness category. */
export type FactoryApprovalStatus = ProviderApprovalStatus;

export interface FactoryApproval {
  status: FactoryApprovalStatus;
  reason: string | null;
  changed_at: string | null;
  /** False while the approval gate keeps the factory from sending service requests (OQ-46). */
  may_send_requests: boolean;
}

/** IMC review of one service a provider lists (ADR-021). */
export type ServiceListingStatus = 'pending' | 'approved' | 'rejected' | 'suspended';

export interface ServiceListingReview {
  status: ServiceListingStatus;
  reason: string | null;
  changed_at: string | null;
  submitted_at: string | null;
}

/** One entry of ServiceProviderResource.service_listings. */
export interface ProviderServiceListing extends ServiceListingReview {
  service: { id: number; code: string; name_ar: string; category: { code: string; name_ar: string } | null };
}

export interface ProviderApproval {
  status: ProviderApprovalStatus;
  reason: string | null;
  changed_at: string | null;
}

/** ProviderChangeRequestResource (ADR-019): a request to change IMC-verified legal information. */
export interface ProviderChangeRequest {
  id: number;
  service_provider_id: number;
  /** The values on the provider now, for comparison. */
  service_provider?: {
    id: number;
    name: string;
    legal_name: string | null;
    commercial_registration_number: string | null;
    tax_registration_number: string | null;
  } | null;
  status: 'pending' | 'approved' | 'rejected' | 'cancelled';
  /** Requested text values by field (only the fields that change). */
  changes: Partial<Record<LegalField, string | null>>;
  documents?: OrganizationDocument[];
  note: string | null;
  requested_by?: { id: number; name: string } | null;
  reviewed_by?: { id: number; name: string } | null;
  reviewed_at: string | null;
  review_reason: string | null;
  created_at: string | null;
}

/** Legal fields IMC verifies (ADR-019). */
export type LegalField = 'legal_name' | 'commercial_registration_number' | 'tax_registration_number';

/** ServiceProviderResource */
export interface ServiceProvider {
  id: number;
  name: string;
  legal_name: string | null;
  description: string | null;
  representative_name: string | null;
  job_title: string | null;
  email: string | null;
  phone: string | null;
  website: string | null;
  dx_experience_years: number | null;
  governorate: string | null;
  city: string | null;
  address: string | null;
  commercial_registration_number: string | null;
  tax_registration_number: string | null;
  sectors?: Sector[];
  services?: CatalogService[];
  documents?: OrganizationDocuments;
  /** True once IMC has approved the provider: members then change the legal fields only through a change request. */
  legal_information_verified: boolean;
  open_change_request?: ProviderChangeRequest | null;
  /** Each listed service with IMC's review (ADR-021): only approved ones reach factories. */
  service_listings?: ProviderServiceListing[];
  /** On the single-record response only: configured required fields still empty (OQ-36). */
  missing_required_fields?: string[];
  approval: ProviderApproval;
  created_at: string | null;
  updated_at: string | null;
}

export interface ServiceProviderPayload {
  name?: string;
  legal_name?: string | null;
  description?: string | null;
  governorate?: string | null;
  city?: string | null;
  address?: string | null;
  commercial_registration_number?: string | null;
  tax_registration_number?: string | null;
  representative_name?: string | null;
  job_title?: string | null;
  email?: string | null;
  phone?: string | null;
  website?: string | null;
  dx_experience_years?: number | null;
  /** Sector codes. Replaced only when sent. */
  sectors?: string[];
  /** Catalog service codes. Replaced only when sent. */
  services?: string[];
}

export interface ApprovalDecisionPayload {
  decision: 'approved' | 'rejected' | 'suspended' | 'changes_requested';
  /** Required for every decision but approval (≤2000). */
  reason?: string | null;
}

export interface ListingDecisionPayload {
  decision: 'approved' | 'rejected' | 'suspended';
  /** Required to reject or suspend (≤2000). */
  reason?: string | null;
}

/** GET /review-summary (ADR-021): every IMC review queue's size in one call. */
export interface ReviewSummary {
  providers: Record<ProviderApprovalStatus, number>;
  factories: Record<FactoryApprovalStatus, number>;
  listings: Record<ServiceListingStatus, number>;
  change_requests: { providers: number; factories: number };
}

/** GET /readiness-analytics (ADR-021): read from the stored assessments. */
export interface ReadinessAnalytics {
  factories_total: number;
  factories_assessed: number;
  factories_not_assessed: number;
  /** factories_assessed / factories_total × 100; null with no factories. */
  completion_rate_percent: number | null;
  assessments_total: number;
  current: {
    average_score: number | null;
    by_category: { code: string; name_ar: string; name_en: string | null; min_score: number; max_score: number; factories: number; average_score: number | null }[];
  };
  period: {
    from: string;
    to: string;
    submissions: number;
    average_score: number | null;
    by_month: { month: string; submissions: number; average_score: number | null }[];
    by_version: { version: number; submissions: number }[];
  };
  definition: {
    version: number;
    pillars: number;
    questions: number;
    choices: number;
    score_range: { min: number; max: number };
    problems: string[];
  } | null;
}

/** ProviderDirectoryResource. Contact fields exist only when the owner enables them (OQ-37). */
export interface ProviderDirectoryEntry {
  id: number;
  name: string;
  description: string | null;
  governorate: string | null;
  city: string | null;
  /** The logo is read with the session token from `/provider-directory/{id}/logo`. */
  has_logo: boolean;
  website: string | null;
  dx_experience_years: number | null;
  representative_name?: string | null;
  job_title?: string | null;
  email?: string | null;
  phone?: string | null;
  sectors?: Sector[];
  services?: CatalogService[];
}

export interface ProviderEvaluationCriterionScore {
  code: string | null;
  name_ar: string | null;
  weight_percent: number | string | null;
  /** Null while no scale is approved (OQ-13). */
  score: string | number | null;
  note: string | null;
}

/** ProviderEvaluationResource */
export interface ProviderEvaluation {
  id: number;
  service_provider_id: number;
  criteria_version: number;
  summary: string;
  evaluated_on: string;
  scoring: 'not_configured' | 'scored';
  scale_max: number | null;
  weighted_total: string | number | null;
  pass_mark: string | number | null;
  meets_pass_mark: boolean | null;
  criteria: ProviderEvaluationCriterionScore[];
  recorded_by: { id: number; name: string } | null;
  created_at: string;
}

export interface ProviderEvaluationPayload {
  summary: string;
  /** YYYY-MM-DD, today or earlier. */
  evaluated_on: string;
  /** Every criterion code of the current matrix. `score` is refused until a scale is approved (OQ-13). */
  criteria: Record<string, { note: string; score?: string | number }>;
}

// ───────────────────────── Marketplace (ADR-015) ─────────────────────────

export type ServiceRequestStatus = 'open' | 'awarded' | 'cancelled';

export type ProviderRequestStatus =
  | 'pending'
  | 'accepted'
  | 'declined'
  | 'withdrawn'
  | 'agreed'
  | 'closed';

/** ProviderRequestResource. `service_request` is nested only on the inbox/detail endpoints. */
export interface ProviderRequest {
  id: number;
  provider?: { id: number; name: string } | null;
  status: ProviderRequestStatus;
  status_reason: string | null;
  status_changed_at: string | null;
  agreed_offer_id: number | null;
  agreement_id?: number | null;
  /** The agreement's IMC review and contract-draft statuses, never its terms (ADR-020). */
  agreement?: { id: number; review_status: AgreementReviewStatus | null; contract_status: ContractStatus | null } | null;
  /** The other side's messages the caller has not read (parties only). */
  unread_messages_count?: number;
  last_message_at?: string | null;
  latest_offer_version?: number | null;
  service_request?: ServiceRequest;
  created_at: string | null;
}

export type AgreementReviewStatus = 'pending' | 'approved' | 'rejected';

/** ServiceRequestResource */
export interface ServiceRequest {
  id: number;
  factory?: { id: number; name: string } | null;
  service?: CatalogService;
  title: string;
  need: string;
  requirements: string | null;
  status: ServiceRequestStatus;
  status_changed_at: string | null;
  /** A provider sees only its own thread here. */
  provider_requests?: ProviderRequest[];
  active_provider_count?: number;
  created_at: string | null;
}

export interface ServiceRequestCreatePayload {
  /** Catalog service code. */
  service: string;
  title: string;
  need: string;
  requirements?: string | null;
  /** 1–20 provider ids, each eligible for the factory. */
  provider_ids: number[];
}

export interface ProviderRequestTransition {
  from: ProviderRequestStatus | null;
  to: ProviderRequestStatus;
  reason: string | null;
  actor: { id: number; name: string; side: 'factory' | 'provider' | null } | null;
  at: string;
}

export interface NegotiationMessage {
  id: number;
  author_side: 'factory' | 'provider';
  author: { id: number; name: string } | null;
  body: string;
  created_at: string;
}

export type OfferState = 'current' | 'expired' | 'lapsed' | 'superseded' | 'accepted';

export interface Offer {
  id: number;
  version: number;
  state: OfferState;
  scope: string;
  deliverables: string;
  duration_days: number;
  valid_until: string | null;
  price: Money;
  author: { id: number; name: string } | null;
  created_at: string;
}

export interface OfferPayload {
  /** The latest version the author has seen; `null` for the first offer. */
  based_on_version: number | null;
  scope: string;
  deliverables: string;
  duration_days: number;
  /** YYYY-MM-DD, today or later. */
  valid_until?: string | null;
  price: Money;
}

// ───────────────────────── Agreements and contracts (ADR-017) ─────────────────────────

export type ContractStatus = 'draft' | 'cancelled';

/**
 * AgreementResource. `terms` and `price` are present for the two parties and IMC reviewers.
 * `imc_review` is the ministry approval that must come before a contract draft or an invoice.
 */
export interface Agreement {
  id: number;
  service_request_id: number;
  provider_request_id: number;
  service_request?: { id: number; title: string } | null;
  imc_review: {
    required: boolean;
    status: AgreementReviewStatus;
    reason: string | null;
    decided_at: string | null;
    reviewed_by: { id: number; name: string } | null;
  };
  next_step: 'awaiting_imc_review' | 'rejected_by_imc' | 'contract_draft' | 'contract_drafted';
  factory: { id: number; name: string } | null;
  provider: { id: number; name: string } | null;
  service: { code: string; name_ar: string } | null;
  binding: false;
  terms?: {
    offer_id: number;
    offer_version: number;
    scope: string;
    deliverables: string;
    duration_days: number;
  } | null;
  price?: Money;
  contract: { id: number; version: number; status: ContractStatus } | null;
  concluded_by: { id: number; name: string } | null;
  concluded_at: string;
  /** ADR-023: `legacy` agreements predate the managed policies. */
  policy_basis: PolicyBasis;
  /** The revenue-share version in effect when concluded, or null if none was. */
  revenue_share_policy_version_id: number | null;
}

/** ContractResource. `knowledge_transfer` and `notes` are present only for the two parties. */
export interface Contract {
  id: number;
  agreement_id: number;
  version: number;
  status: ContractStatus;
  status_reason: string | null;
  binding: false;
  legal_status: 'draft_not_binding';
  signature: { status: 'not_available'; decision_needed: string };
  knowledge_transfer?: {
    trainees: number;
    training_plan: string;
    commitment_memo: { status: 'not_available'; decision_needed: string };
  };
  notes?: string | null;
  drafted_by: { id: number; name: string } | null;
  /** `legacy`: drafted before the managed policies (ADR-023). */
  policy_basis: PolicyBasis;
  /** The contract template version the draft was generated with, or null. */
  template_version: PolicyVersionReference | null;
  /** Parties only: the frozen agreed terms, policy versions and template clauses. */
  terms_snapshot?: ContractTermsSnapshot | null;
  status_changed_at: string | null;
  created_at: string | null;
}

export interface ContractTermsSnapshot {
  legal_status: 'draft_not_binding';
  generated_on: string;
  agreement: {
    id: number;
    concluded_at: string;
    service: { code: string | null; name_ar: string | null };
    price_amount: string;
    currency: string;
    offer: { id: number; version: number; scope: string; deliverables: string; duration_days: number } | null;
  };
  parties: { factory: { id: number; name: string | null }; service_provider: { id: number; name: string | null } };
  policy_versions: { revenue_share: PolicyVersionReference | null; contract_template: PolicyVersionReference | null };
  template: ContractTemplateParameters | null;
}

export interface ContractDraftPayload {
  knowledge_transfer: { trainees: number; training_plan: string };
  notes?: string | null;
}

// ───────────────────────── Billing (ADR-017) ─────────────────────────

export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'refunded' | 'cancelled';
export type InvoiceIssuer = 'imc' | 'service_provider';
export type PaymentStatus = 'pending' | 'succeeded' | 'failed' | 'cancelled' | 'refunded';

export interface InvoiceLine {
  id: number;
  position: number;
  description: string;
  quantity: number;
  unit_amount: string;
  line_amount: string;
}

export type InvoiceRevenueShare =
  | { status: 'not_configured'; decision_needed: string }
  | { status: 'calculated'; rate_percent: string | number; amount: string };

/** InvoiceResource. Amounts are decimal strings in `currency`. */
export interface Invoice {
  id: number;
  agreement_id: number;
  /** The agreement's parties and service (list and detail). */
  parties?: {
    factory: { id: number; name: string } | null;
    provider: { id: number; name: string } | null;
    service: { code: string; name_ar: string } | null;
  } | null;
  /** The only invoice type that exists: the invoice for an agreed service (no commission invoices, OQ-15). */
  type: 'agreement_service';
  /** From the approved payment terms, set when issued (ADR-023). */
  due_date: string | null;
  /** Computed by the server from the due date; never stored. */
  is_overdue: boolean;
  payer: 'factory' | null;
  /** `legacy`: made before the managed policies; no version is referenced (ADR-023). */
  policy_basis: PolicyBasis;
  policy_versions: {
    invoicing: PolicyVersionReference | { id: number } | null;
    tax: PolicyVersionReference | null;
    payment_terms: PolicyVersionReference | null;
    revenue_share: PolicyVersionReference | null;
  };
  fees: string | null;
  amount_paid: string;
  outstanding: string | null;
  /** The server's calculation, frozen when issued. */
  calculation: InvoiceCalculation | null;
  number: string | null;
  status: InvoiceStatus;
  status_reason: string | null;
  issuer: InvoiceIssuer;
  currency: 'EGP';
  lines: InvoiceLine[];
  subtotal: string;
  tax: { rate_percent: string | number; amount: string } | null;
  total: string | null;
  revenue_share: InvoiceRevenueShare;
  issued_at: string | null;
  paid_at: string | null;
  status_changed_at: string | null;
  created_at: string | null;
}

export interface InvoiceCalculation {
  subtotal: string;
  prices_include_tax: boolean;
  net_service_amount: string;
  fees: { code: string; name_ar: string; calculation: 'fixed' | 'percentage'; rate_percent: string | null; amount: string; taxable: boolean }[];
  fees_total: string;
  taxes: { code: string; name_ar: string; rate_percent: string; base: string; amount: string }[];
  tax_rate_percent: string;
  tax_total: string;
  total: string;
  revenue_share: { rate_percent: string; base: string; amount: string } | null;
  issued_on?: string;
  policy_versions?: Record<string, PolicyVersionReference | null>;
}

export interface InvoiceLinePayload {
  description: string;
  quantity: number;
  /** Decimal string, ≤2 places. */
  unit_amount: string;
}

/** PaymentResource */
export interface Payment {
  id: number;
  invoice_id: number;
  /** `manual`: an authorised entry of money received outside the platform (ADR-023). */
  method: 'gateway' | 'manual';
  gateway: string;
  gateway_reference: string | null;
  status: PaymentStatus;
  amount: string;
  currency: 'EGP';
  checkout_url: string | null;
  failure_reason: string | null;
  received_on: string | null;
  evidence_note: string | null;
  recorded_by_user_id: number | null;
  status_changed_at: string | null;
  created_at: string | null;
}

export interface ManualPaymentPayload {
  /** Decimal string, ≤2 places; checked by the server against what is outstanding. */
  amount: string;
  /** YYYY-MM-DD, not in the future. */
  received_on: string;
  /** The bank's transfer reference; unique. */
  reference: string;
  evidence_note?: string | null;
}

export interface BillingCapability {
  available: boolean;
  decision_needed: string | null;
}

/** `GET /billing/configuration` */
export interface BillingConfiguration {
  invoice_drafting: BillingCapability;
  invoice_issuing: BillingCapability;
  revenue_share: BillingCapability;
  contract_templates: BillingCapability;
  payments: BillingCapability;
  refund_initiation: BillingCapability;
  payouts: BillingCapability;
}

// ───────────────────────── Users and audit log ─────────────────────────

export interface UserCreatePayload {
  name: string;
  email: string;
  role: Role;
  /** Required for `factory_member`, otherwise prohibited. */
  factory_id?: number;
  /** Required for `provider_member`, otherwise prohibited. */
  service_provider_id?: number;
}

export interface UserUpdatePayload {
  name?: string;
  is_active?: boolean;
}

export type AuditSubjectType = 'user' | 'factory' | 'service_provider';

/** AuditLogResource. `metadata` never contains secrets or negotiation text. */
export interface AuditLogEntry {
  id: number;
  event: string;
  actor: { id: number; name: string | null; email: string | null } | null;
  subject: { type: string; id: number } | null;
  ip_address: string | null;
  request_id: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface AuditLogQuery {
  event?: string;
  actor_user_id?: number;
  subject_type?: AuditSubjectType;
  subject_id?: number;
  /** YYYY-MM-DD, whole UTC days, inclusive. */
  from?: string;
  to?: string;
  per_page?: number;
  cursor?: string;
}

// ───────────────────────── Phase 2 portals (ADR-020) ─────────────────────────

/** NotificationResource: an in-app notification of a platform event. */
export interface AppNotification {
  id: string;
  event: string | null;
  title: string | null;
  body: string | null;
  /** A path of this web client (for example `/provider/requests/12`). */
  link: string | null;
  subject: { type: string; id: number } | null;
  read_at: string | null;
  created_at: string | null;
}

export interface NotificationPage extends Paginated<AppNotification> {
  meta: PageMeta & { unread_count: number };
}

/** ServiceListingResource: a provider's listing of one catalog service. */
export interface ServiceListing {
  id: string;
  provider: {
    id: number;
    name: string;
    description: string | null;
    governorate: string | null;
    dx_experience_years: number | null;
    has_logo: boolean;
    /** API path (relative to the base URL) of the logo, read with the session token. */
    logo_path: string | null;
    /** Shown to the provider itself and IMC only. */
    approval_status?: ProviderApprovalStatus;
  } | null;
  service: { id: number; code: string; name_ar: string; category: { code: string; name_ar: string } | null } | null;
  /** An IMC promotion: labelled «إعلان». Never makes a listing eligible. */
  promotion: { id: number; label: string; headline: string | null; ends_at: string | null } | null;
  /** Factory viewers: whether the readiness roadmap recommends the service. */
  recommended: boolean | null;
  /** IMC and the provider itself (ADR-021); a factory only ever sees approved listings. */
  review?: ServiceListingReview;
}

export interface ServiceListingPage extends Paginated<ServiceListing> {
  meta: PageMeta & {
    viewer?: 'factory' | 'provider' | 'imc' | 'other';
    has_sectors?: boolean;
    readiness?: {
      category: { code: string; name_ar: string } | null;
      total_score: number;
      completed_at: string;
    } | null;
  };
}

export type PromotionState = 'active' | 'scheduled' | 'expired' | 'ended';

/** ServicePromotionResource (IMC). */
export interface ServicePromotion {
  id: number;
  provider?: { id: number; name: string; approval_status: ProviderApprovalStatus } | null;
  service?: CatalogService;
  headline: string | null;
  priority: number;
  state: PromotionState;
  starts_at: string;
  ends_at: string | null;
  ended_at: string | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
}

export interface ServicePromotionPayload {
  service_provider_id: number;
  service: string;
  headline?: string | null;
  priority?: number;
  /** ISO date-time; default now. */
  starts_at?: string | null;
  ends_at?: string | null;
}

/** `GET /reports/marketplace`: counts and sums from stored records, scoped to the caller. */
export interface MarketplaceReport {
  period: { from: string; to: string; timezone: string };
  requests: {
    total: number;
    by_status: Record<ProviderRequestStatus, number>;
    by_month: { month: string; count: number }[];
    top_services: { code: string; name_ar: string; count: number }[];
  };
  response_time: { answered_count: number; average_hours: number | null; median_hours: number | null };
  conversion: {
    requests: number;
    agreed: number;
    imc_approved: number;
    agreed_rate_percent: number | null;
    approved_rate_percent: number | null;
  };
  agreements: {
    total: number;
    by_review_status: Record<AgreementReviewStatus, number>;
    with_contract_draft: number;
    expiry: { status: 'not_available'; decision_needed: string };
  };
  invoices: {
    by_status: Record<InvoiceStatus, number>;
    issued_totals: { status: InvoiceStatus; currency: string; amount: string; count: number }[];
  };
  activity_by_month: { month: string; messages: number; offers: number }[];
}

/** FactoryChangeRequestResource: a reviewed change to a factory's recorded legal information. */
export interface FactoryChangeRequest {
  id: number;
  factory_id: number;
  factory?: {
    id: number;
    name: string;
    legal_name: string | null;
    commercial_registration_number: string | null;
    tax_registration_number: string | null;
  } | null;
  status: ProviderChangeRequest['status'];
  changes: Partial<Record<LegalField, string | null>>;
  documents?: OrganizationDocument[];
  note: string | null;
  requested_by?: { id: number; name: string } | null;
  reviewed_by?: { id: number; name: string } | null;
  reviewed_at: string | null;
  review_reason: string | null;
  created_at: string | null;
}

// ───────────────────────── Landing-page announcements (ADR-022) ─────────────────────────

/** The colour presets of the advertisement cards (components/admin/colorOptions). */
export type AnnouncementColor = 'green' | 'blue' | 'orange' | 'red' | 'beige' | 'purple';

/** PublicAnnouncementResource as visitors get it (GET /public/announcements). */
export interface PublicAnnouncement {
  id: number;
  title: string;
  description: string;
  badge_text: string | null;
  color: AnnouncementColor;
  /** A path of this web client (validated by the API), or null. */
  link_path: string | null;
  tags: string[];
  countdown_text: string | null;
  cover_alt: string | null;
  /** Relative to the API base URL; public for live announcements, token-only on the admin routes. */
  cover_path: string | null;
  ends_at: string | null;
}

/** The same record for IMC administrators. */
export interface AdminAnnouncement extends PublicAnnouncement {
  state: 'draft' | 'scheduled' | 'live' | 'ended';
  sort_order: number;
  starts_at: string | null;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AnnouncementPayload {
  title?: string;
  description?: string;
  color?: AnnouncementColor;
  badge_text?: string | null;
  link_path?: string | null;
  tags?: string[];
  countdown_text?: string | null;
  cover_alt?: string | null;
  sort_order?: number;
  starts_at?: string | null;
  ends_at?: string | null;
}

// ───────────────────────── Financial and contract policies (ADR-023) ─────────────────────────

export type PolicyBasis = 'legacy' | 'policy';
export type FinancialPolicyKind = 'revenue_share' | 'tax' | 'invoicing' | 'payment_terms' | 'contract_template';
export type FinancialPolicyScopeType = 'global' | 'sector' | 'catalog_service' | 'service_provider';
export type FinancialPolicyVersionStatus = 'draft' | 'pending_approval' | 'rejected' | 'approved' | 'superseded' | 'ended' | 'archived';
/** `status` for unapproved versions; `scheduled` / `active` / `expired` for approved ones, from their dates. */
export type FinancialPolicyEffectiveStatus = Exclude<FinancialPolicyVersionStatus, 'approved' | 'superseded' | 'ended'> | 'scheduled' | 'active' | 'expired';

export interface PolicyVersionReference {
  id: number;
  policy_id?: number;
  version?: number;
}

export interface RevenueShareParameters {
  method: 'percentage';
  rate_percent: string;
  base: 'subtotal_before_tax';
}

export interface TaxParameters {
  prices_include_tax: boolean;
  taxes: { code: string; name_ar: string; rate_percent: string }[];
  fees: { code: string; name_ar: string; calculation: 'fixed' | 'percentage'; amount: string | null; rate_percent: string | null; taxable: boolean }[];
}

export interface InvoicingParameters {
  issuer: InvoiceIssuer;
  payer: 'factory';
  currency: 'EGP';
  number_prefix: string;
  number_padding: number;
  invoice_types: 'agreement_service'[];
  manual_payments_allowed: boolean;
  requires_revenue_share: boolean;
}

export interface PaymentTermsParameters {
  due_rule: 'days_after_issue';
  due_days: number;
  partial_payments_allowed: boolean;
}

export interface ContractTemplateParameters {
  title_ar: string;
  parties: ('factory' | 'service_provider' | 'imc')[];
  duration_months: number | null;
  knowledge_transfer_min_trainees: number;
  clauses: { heading_ar: string; body_ar: string }[];
}

export type FinancialPolicyParameters =
  | RevenueShareParameters
  | TaxParameters
  | InvoicingParameters
  | PaymentTermsParameters
  | ContractTemplateParameters;

export interface FinancialPolicyVersionSummary {
  id: number;
  version: number;
  status: FinancialPolicyVersionStatus;
  effective_status: FinancialPolicyEffectiveStatus;
  effective_from: string;
  effective_to: string | null;
  parameters: FinancialPolicyParameters;
}

/** FinancialPolicyResource */
export interface FinancialPolicy {
  id: number;
  kind: FinancialPolicyKind;
  kind_label_ar: string;
  decision_needed: string;
  scope: { type: FinancialPolicyScopeType; id: number | null; code: string | null; label_ar: string; name: string };
  name_ar: string;
  description_ar: string | null;
  /** The version in effect today, or null: then operations that need it are blocked. */
  current_version: FinancialPolicyVersionSummary | null;
  /** The draft or pending version, if one is being prepared. */
  open_version: FinancialPolicyVersionSummary | null;
  versions?: FinancialPolicyVersion[];
  created_at: string | null;
}

/** FinancialPolicyVersionResource */
export interface FinancialPolicyVersion {
  id: number;
  policy_id: number;
  policy?: FinancialPolicy;
  version: number;
  status: FinancialPolicyVersionStatus;
  status_label_ar: string;
  effective_status: FinancialPolicyEffectiveStatus;
  effective_from: string;
  effective_to: string | null;
  parameters: FinancialPolicyParameters;
  change_reason: string;
  created_by: { id: number; name: string } | null;
  submitted_by: { id: number; name: string } | null;
  submitted_at: string | null;
  decided_by: { id: number; name: string } | null;
  decided_at: string | null;
  decision_note: string | null;
  superseded_by_version_id: number | null;
  status_reason: string | null;
  status_changed_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  /** What the current administrator may do now; the server checks again. */
  actions: { edit: boolean; submit: boolean; approve: boolean; reject: boolean; archive: boolean; end: boolean } | null;
}

export interface FinancialPolicyDraftPayload {
  parameters: FinancialPolicyParameters;
  effective_from: string;
  effective_to: string | null;
  change_reason: string;
}

export interface FinancialPolicyCreatePayload extends FinancialPolicyDraftPayload {
  kind: FinancialPolicyKind;
  scope_type: FinancialPolicyScopeType;
  /** A catalog service or service provider id. */
  scope_id?: number | null;
  /** A sector, by its public code. */
  scope_code?: string | null;
  name_ar: string;
  description_ar?: string | null;
}

export interface FinancialPolicyHistoryEntry {
  id: number;
  event: string;
  actor: { id: number; name: string | null } | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface FinancialPolicyResolution {
  kind: FinancialPolicyKind;
  date: string;
  version: FinancialPolicyVersion | null;
  reason_ar: string | null;
}

export interface FinancialPreview {
  date: string;
  calculation: InvoiceCalculation;
  due_date: string | null;
  policy_versions: Record<'invoicing' | 'tax' | 'payment_terms' | 'revenue_share', PolicyVersionReference | null>;
  missing_ar: string[];
}

export interface FinancialVersionPreview {
  version_id: number;
  kind: FinancialPolicyKind;
  calculation?: InvoiceCalculation;
  due_date_if_issued_today?: string;
  first_number_example?: string;
  clauses?: number;
  parties?: string[];
}

export interface FinancialReadinessReason {
  code: string;
  message_ar: string;
  decision_needed: string | null;
}

/** `GET /agreements/{id}/financial-readiness` */
export type AgreementFinancialReadiness = Record<
  'contract_drafting' | 'invoice_drafting' | 'invoice_issuing' | 'gateway_payment' | 'contract_signature' | 'payouts',
  { available: boolean; reasons: FinancialReadinessReason[] }
>;
