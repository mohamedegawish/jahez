// Arabic labels for backend enum values. This is static UI configuration: the VALUES come from
// the API (jahez_api/app/Enums/*), only their display text lives here. An unknown value is shown
// as-is rather than hidden, so a new backend state is visible instead of silently blank.

import type {
  ContractStatus,
  InvoiceIssuer,
  InvoiceStatus,
  OfferState,
  PaymentStatus,
  ProviderApprovalStatus,
  ProviderRequestStatus,
  Role,
  ServiceListingStatus,
  ServiceRequestStatus,
} from '../api/types';

const pick = <K extends string>(map: Record<K, string>) => (value: K | string): string =>
  (map as Record<string, string>)[value] ?? value;

export const roleLabel = pick<Role>({
  imc_admin: 'إدارة مركز تحديث الصناعة',
  factory_member: 'منشأة صناعية',
  provider_member: 'مزود خدمة',
});

export const approvalLabel = pick<ProviderApprovalStatus>({
  pending: 'قيد المراجعة',
  approved: 'معتمد',
  rejected: 'مرفوض',
  suspended: 'موقوف',
  changes_requested: 'مطلوب استكمال بيانات',
});

/** IMC review of one listed service (ADR-021). */
export const listingStatusLabel = pick<ServiceListingStatus>({
  pending: 'بانتظار الاعتماد',
  approved: 'معتمدة',
  rejected: 'مرفوضة',
  suspended: 'موقوفة',
});

export const serviceRequestStatusLabel = pick<ServiceRequestStatus>({
  open: 'مفتوح',
  awarded: 'تمت الترسية',
  cancelled: 'ملغي',
});

export const providerRequestStatusLabel = pick<ProviderRequestStatus>({
  pending: 'بانتظار رد المزود',
  accepted: 'قيد التفاوض',
  declined: 'رفضه المزود',
  withdrawn: 'سحبه المصنع',
  agreed: 'تم الاتفاق',
  closed: 'مغلق',
});

export const offerStateLabel = pick<OfferState>({
  current: 'ساري',
  expired: 'انتهت صلاحيته',
  lapsed: 'سقط بانتهاء التفاوض',
  superseded: 'استُبدل بنسخة أحدث',
  accepted: 'مقبول',
});

export const contractStatusLabel = pick<ContractStatus>({
  draft: 'مسودة (غير ملزمة)',
  cancelled: 'ملغاة',
});

export const invoiceStatusLabel = pick<InvoiceStatus>({
  draft: 'مسودة',
  issued: 'صادرة',
  partially_paid: 'مدفوعة جزئيًا',
  paid: 'مدفوعة',
  refunded: 'مستردة',
  cancelled: 'ملغاة',
});

export const paymentStatusLabel = pick<PaymentStatus>({
  pending: 'قيد المعالجة',
  succeeded: 'ناجحة',
  failed: 'فاشلة',
  cancelled: 'ملغاة',
  refunded: 'مستردة',
});

export const invoiceIssuerLabel = pick<InvoiceIssuer>({
  imc: 'مركز تحديث الصناعة',
  service_provider: 'مزود الخدمة',
});

/** What each open question (docs/open-questions.md) is waiting for, in plain Arabic. */
const DECISIONS: Record<string, string> = {
  'OQ-10': 'بيانات الفحص الفني للبنية التحتية والأمن السيبراني',
  'OQ-13': 'مقياس تقييم مزودي الخدمة ودرجة النجاح',
  'OQ-15': 'قاعدة اقتسام الإيراد',
  'OQ-16': 'التدفقات المالية: الفواتير والضريبة وبوابة الدفع',
  'OQ-17': 'الإطار القانوني للعقود والتوقيع الإلكتروني',
  'OQ-18': 'نموذج التسجيل والمستندات المطلوبة',
  'OQ-19': 'حقول ملف المنشأة',
  'OQ-21': 'أدوار الإدارة الداخلية في المركز',
  'OQ-26': 'قنوات الإشعارات وأحداثها',
  'OQ-27': 'التقارير ومؤشرات الأداء',
  'OQ-37': 'إظهار بيانات التواصل لمزودي الخدمة',
  'OQ-39': 'مدى اطلاع إدارة المركز على المفاوضات',
  'OQ-43': 'ما يترتب على رفض المركز لاتفاقية',
  'OQ-47': 'أولوية السياسات المالية عند تعدد النطاقات',
  'OQ-48': 'التاريخ الذي تُحدَّد به حصة الوزارة المطبقة',
  'OQ-49': 'من يُمنح صلاحيات إعداد السياسات المالية واعتمادها',
  'OQ-50': 'التعديلات بأثر رجعي والإشعارات الدائنة',
};

/** `"OQ-15, OQ-16"` → readable list; unknown codes are kept verbatim. */
export function decisionLabel(decisionNeeded: string | null | undefined): string {
  if (!decisionNeeded) return '';
  return decisionNeeded
    .split(/[,،]\s*/)
    .map((code) => code.trim())
    .filter(Boolean)
    .map((code) => (DECISIONS[code] ? `${DECISIONS[code]} (${code})` : code))
    .join('، ');
}
