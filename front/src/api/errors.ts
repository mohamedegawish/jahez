// Typed errors for the Jahez API error envelope (jahez_api/docs/api-conventions.md §4).
//
//   { "message": "...", "code": "machine_code", "request_id": "...",
//     "errors": { "field.path": ["msg"] },      // 422 only
//     "decision_needed": "OQ-16",               // 409 policy_not_configured only
//     "reason_ar": "...", "missing_policies": ["tax"] }   // ditto, when the API knows them (ADR-023)

export interface ApiErrorBody {
  message?: string;
  code?: string;
  request_id?: string | null;
  errors?: Record<string, string[]>;
  decision_needed?: string;
  reason_ar?: string;
  missing_policies?: string[];
}

export interface ApiErrorInit {
  status: number;
  code: string;
  message: string;
  requestId?: string | null;
  fieldErrors?: Record<string, string[]>;
  decisionNeeded?: string | null;
  reasonAr?: string | null;
  missingPolicies?: string[];
  retryAfter?: number | null;
}

/** A non-2xx response from the API, parsed from its standard envelope. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly requestId: string | null;
  readonly fieldErrors: Record<string, string[]>;
  readonly decisionNeeded: string | null;
  /** The server's Arabic explanation of what is missing (policy_not_configured). */
  readonly reasonAr: string | null;
  /** The financial policy kinds that are missing (policy_not_configured). */
  readonly missingPolicies: string[];
  /** Seconds from the `Retry-After` header (429), when present. */
  readonly retryAfter: number | null;

  constructor(init: ApiErrorInit) {
    super(init.message);
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.requestId = init.requestId ?? null;
    this.fieldErrors = init.fieldErrors ?? {};
    this.decisionNeeded = init.decisionNeeded ?? null;
    this.reasonAr = init.reasonAr ?? null;
    this.missingPolicies = init.missingPolicies ?? [];
    this.retryAfter = init.retryAfter ?? null;
  }
}

/** The request never produced an HTTP response: offline, DNS, CORS or a blocked preflight. */
export class NetworkError extends Error {
  constructor(message = 'Network request failed') {
    super(message);
    this.name = 'NetworkError';
  }
}

/** A configuration problem on the client (for example a missing VITE_API_BASE_URL). */
export class ConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConfigurationError';
  }
}

export const isApiError = (e: unknown): e is ApiError => e instanceof ApiError;
export const isNetworkError = (e: unknown): e is NetworkError => e instanceof NetworkError;

export const isUnauthenticated = (e: unknown): boolean => isApiError(e) && e.status === 401;
export const isForbidden = (e: unknown): boolean => isApiError(e) && e.status === 403;
export const isNotFound = (e: unknown): boolean => isApiError(e) && e.status === 404;
export const isValidationError = (e: unknown): boolean => isApiError(e) && e.status === 422;
export const isRateLimited = (e: unknown): boolean => isApiError(e) && e.status === 429;
export const isServerError = (e: unknown): boolean => isApiError(e) && e.status >= 500;
/** 409 `conflict`: the action is not allowed in the resource's current state. */
export const isConflict = (e: unknown): boolean =>
  isApiError(e) && e.status === 409 && e.code !== 'policy_not_configured';
/** 409 `policy_not_configured`: the business rule is still undecided (see `decisionNeeded`). */
export const isPolicyNotConfigured = (e: unknown): boolean =>
  isApiError(e) && e.status === 409 && e.code === 'policy_not_configured';

/** Parse a JSON error body defensively; the API envelope is the only shape expected. */
export function parseErrorBody(raw: unknown): ApiErrorBody {
  if (raw === null || typeof raw !== 'object') return {};
  const body = raw as Record<string, unknown>;
  const errors: Record<string, string[]> = {};
  if (body.errors !== null && typeof body.errors === 'object') {
    for (const [key, value] of Object.entries(body.errors as Record<string, unknown>)) {
      if (Array.isArray(value)) errors[key] = value.filter((v): v is string => typeof v === 'string');
    }
  }
  return {
    message: typeof body.message === 'string' ? body.message : undefined,
    code: typeof body.code === 'string' ? body.code : undefined,
    request_id: typeof body.request_id === 'string' ? body.request_id : null,
    errors: Object.keys(errors).length > 0 ? errors : undefined,
    decision_needed: typeof body.decision_needed === 'string' ? body.decision_needed : undefined,
    reason_ar: typeof body.reason_ar === 'string' ? body.reason_ar : undefined,
    missing_policies: Array.isArray(body.missing_policies)
      ? body.missing_policies.filter((v): v is string => typeof v === 'string')
      : undefined,
  };
}

/**
 * First message per field, keyed by the Laravel dotted path (`sectors.0`, `price.amount`,
 * `knowledge_transfer.trainees`). Non-validation errors yield an empty object.
 */
export function firstFieldErrors(error: unknown): Record<string, string> {
  if (!isApiError(error)) return {};
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(error.fieldErrors)) {
    if (messages.length > 0) out[key] = messages[0];
  }
  return out;
}

/**
 * Messages for one form field, including nested paths: asking for `sectors` also returns
 * the messages for `sectors.0`, `sectors.1`, …; asking for `price` returns `price.amount`.
 */
export function fieldMessages(error: unknown, field: string): string[] {
  if (!isApiError(error)) return [];
  const out: string[] = [];
  for (const [key, messages] of Object.entries(error.fieldErrors)) {
    if (key === field || key.startsWith(`${field}.`)) out.push(...messages);
  }
  return out;
}

/**
 * Feed a 422 into a react-hook-form `setError`. Returns the messages whose field is not in
 * `knownFields`, so the caller can show them as a form-level (non-field) error.
 */
export function applyToForm(
  error: unknown,
  setError: (name: never, error: { type: string; message: string }) => void,
  knownFields: readonly string[],
): string[] {
  const leftover: string[] = [];
  for (const [key, message] of Object.entries(firstFieldErrors(error))) {
    const root = key.split('.')[0];
    if (knownFields.includes(key) || knownFields.includes(root)) {
      setError(key as never, { type: 'server', message });
    } else {
      leftover.push(message);
    }
  }
  return leftover;
}

export type ErrorTone = 'warning' | 'error' | 'info';

export interface ErrorDescription {
  /** Arabic headline for the user. */
  title: string;
  /** Extra context: the server message (English until OQ-23), request id, retry hint. */
  detail?: string;
  tone: ErrorTone;
  /** True when retrying the same request later can succeed. */
  retryable: boolean;
}

/**
 * Arabic, user-facing description of any error. API messages are English until OQ-23 is
 * answered, so the headline is chosen by status/code and the server text is kept as detail.
 */
export function describeError(error: unknown): ErrorDescription {
  if (isNetworkError(error)) {
    return {
      title: 'تعذّر الاتصال بالخادم. تحقق من اتصالك بالإنترنت ثم أعد المحاولة.',
      detail: 'لم يصل أي رد من الخادم (انقطاع الشبكة أو رفض CORS).',
      tone: 'error',
      retryable: true,
    };
  }
  if (error instanceof ConfigurationError) {
    return { title: 'إعدادات الاتصال بالخادم غير مكتملة.', detail: error.message, tone: 'error', retryable: false };
  }
  if (!isApiError(error)) {
    return { title: 'حدث خطأ غير متوقع.', detail: error instanceof Error ? error.message : undefined, tone: 'error', retryable: true };
  }

  const requestRef = error.requestId ? `رقم الطلب: ${error.requestId}` : undefined;

  if (isPolicyNotConfigured(error)) {
    return {
      title: error.reasonAr ?? 'هذه العملية تعتمد على قاعدة عمل لم يعتمدها مركز تحديث الصناعة بعد، ولذلك لا يمكن تنفيذها حاليًا.',
      detail: [error.decisionNeeded ? `القرار المطلوب: ${error.decisionNeeded}` : null, error.message].filter(Boolean).join(' — '),
      tone: 'info',
      retryable: false,
    };
  }

  switch (error.status) {
    case 400:
      return { title: 'تعذّر معالجة الطلب.', detail: error.message, tone: 'error', retryable: false };
    case 401:
      return { title: 'انتهت الجلسة أو لم يتم تسجيل الدخول. الرجاء تسجيل الدخول مرة أخرى.', tone: 'warning', retryable: false };
    case 403:
      return { title: 'ليست لديك صلاحية لتنفيذ هذا الإجراء.', detail: error.message, tone: 'warning', retryable: false };
    case 404:
      return { title: 'العنصر المطلوب غير موجود أو غير متاح لحسابك.', tone: 'warning', retryable: false };
    case 409:
      return { title: 'لا يمكن تنفيذ الإجراء في الحالة الحالية.', detail: error.message, tone: 'warning', retryable: false };
    case 413:
      return { title: 'حجم البيانات المرسلة أكبر من المسموح.', tone: 'error', retryable: false };
    case 422:
      return { title: 'البيانات المُدخلة غير صالحة. راجع الحقول الموضحة.', detail: error.message, tone: 'warning', retryable: false };
    case 429:
      return {
        title: error.retryAfter
          ? `تم تجاوز عدد المحاولات المسموح بها. حاول مرة أخرى بعد ${error.retryAfter} ثانية.`
          : 'تم تجاوز عدد المحاولات المسموح بها. حاول مرة أخرى بعد قليل.',
        tone: 'warning',
        retryable: true,
      };
    case 503:
      return { title: 'الخدمة غير متاحة مؤقتًا. حاول مرة أخرى بعد قليل.', detail: error.message || requestRef, tone: 'error', retryable: true };
    default:
      if (error.status >= 500) {
        return { title: 'حدث خطأ في الخادم. حاول مرة أخرى، وإن استمر الخطأ أبلغ الدعم.', detail: requestRef, tone: 'error', retryable: true };
      }
      return { title: 'تعذّر تنفيذ الطلب.', detail: error.message, tone: 'error', retryable: false };
  }
}
