import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Factory as FactoryIcon, Plus } from 'lucide-react';
import { api } from '../../api';
import type { Factory, FactoryApprovalStatus, FactorySummary } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { nameOf, useFactorySizes, useSectors } from '../../hooks/useReference';
import { formatDate } from '../../lib/format';
import type { ApprovalDecision } from '../../lib/provider';
import { ApprovalDecisionModal } from '../../components/admin/ApprovalDecisionModal';
import { FactoryCard } from '../../components/admin/FactoryCard';
import { FactoryFormModal } from '../../components/admin/FactoryFormModal';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton, TableSkeleton } from '../../components/ui/LoadingState';
import { Modal } from '../../components/ui/Modal';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';
import { SearchInput } from '../../components/ui/SearchInput';

const FILTER_KEYS = ['sector', 'size', 'approval_status', 'readiness'] as const;
const selectClass =
  'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

const APPROVAL_FILTERS: { value: FactoryApprovalStatus; label: string }[] = [
  { value: 'pending', label: 'بانتظار المراجعة' },
  { value: 'changes_requested', label: 'مطلوب استكمال بيانات' },
  { value: 'approved', label: 'معتمدة' },
  { value: 'rejected', label: 'مرفوضة' },
  { value: 'suspended', label: 'موقوفة' },
];

const READINESS_FILTERS = [
  { value: 'none', label: 'لم تُكمل التقييم' },
  { value: 'b4_automation', label: 'ما قبل الأتمتة' },
  { value: 'basic', label: 'مبتدئ' },
  { value: 'advanced', label: 'متقدم' },
  { value: 'smart', label: 'ذكي ومبتكر' },
];

const SORTS = [
  { value: '', label: 'ترتيب التسجيل' },
  { value: 'newest', label: 'الأحدث تسجيلًا' },
  { value: 'oldest', label: 'الأقدم تسجيلًا' },
  { value: 'name', label: 'الاسم (أ–ي)' },
  { value: 'recently_decided', label: 'آخر قرار اعتماد' },
];

/**
 * IMC list of factories as cards (ADR-021): the server filters, sorts and paginates; each card shows the
 * account's approval status and, separately, its readiness level and score. Decisions use the same
 * approval endpoint and rules as the review page; legal details and documents stay on that page.
 */
export const FactoriesManagement: React.FC = () => {
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const sectors = useSectors();
  const sizes = useFactorySizes();
  const { hasPermission } = useAuth();
  const canDecide = hasPermission('factories.approve');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [form, setForm] = useState<{ factory: Factory | null } | null>(null);
  const [deciding, setDeciding] = useState<{ factory: FactorySummary; decision: ApprovalDecision } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const query = useApiQuery(
    (signal) => api.factories.list({ ...toApiQuery(list), signal }),
    [list.page, list.perPage, list.search, list.sort, list.filters.sector, list.filters.size, list.filters.approval_status, list.filters.readiness],
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">إدارة المنشآت والمصانع</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            المنشآت الصناعية المسجلة في منظومة جاهز: حالة اعتماد الحساب، ومستوى الجاهزية الرقمية كما حسبه الخادم، وهما مستقلان.
          </p>
        </div>
        <Button variant="primary" size="sm" icon={Plus} onClick={() => setForm({ factory: null })}>
          إضافة منشأة
        </Button>
      </div>

      <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs text-[#667085] flex flex-wrap items-center gap-2">
        الوثائق وطلبات تعديل البيانات القانونية وسجل القرارات في صفحات المراجعة:
        <Link to="/admin/approvals/factories" className="font-semibold text-[#5146A5] hover:underline">اعتماد المنشآت</Link>
        ·
        <Link to="/admin/change-requests?type=factory" className="font-semibold text-[#5146A5] hover:underline">تعديلات البيانات الحساسة</Link>
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المنشأة..." className="w-full" />
          <select aria-label="القطاع الصناعي" value={list.filters.sector} onChange={(e) => list.setFilter('sector', e.target.value)} className={selectClass}>
            <option value="">كافة القطاعات الصناعية</option>
            {(sectors.data ?? []).map((sector) => (
              <option key={sector.code} value={sector.code}>
                {sector.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="حجم المنشأة" value={list.filters.size} onChange={(e) => list.setFilter('size', e.target.value)} className={selectClass}>
            <option value="">كافة الأحجام</option>
            {(sizes.data ?? []).map((size) => (
              <option key={size.code} value={size.code}>
                {size.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="حالة الاعتماد" value={list.filters.approval_status} onChange={(e) => list.setFilter('approval_status', e.target.value)} className={selectClass}>
            <option value="">كل حالات الاعتماد</option>
            {APPROVAL_FILTERS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
          <select aria-label="مستوى الجاهزية" value={list.filters.readiness} onChange={(e) => list.setFilter('readiness', e.target.value)} className={selectClass}>
            <option value="">كل مستويات الجاهزية</option>
            {READINESS_FILTERS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={selectClass}>
            {SORTS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
        </div>
        <div className="mt-3 flex items-center justify-between gap-3 text-xs text-[#667085]">
          <span aria-live="polite">{query.data ? `${query.data.meta.total} منشأة` : ''}</span>
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط الفلاتر
          </Button>
        </div>
      </Card>

      {notice && (
        <p role="status" className="p-3 rounded-xl bg-[#E7F8EE] text-xs font-semibold text-[#1D7E4C]">
          {notice}
        </p>
      )}

      <QueryBoundary
        query={query}
        loading={
          <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">
            {[0, 1, 2].map((key) => (
              <CardSkeleton key={key} />
            ))}
          </div>
        }
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState
              title="هذه الصفحة فارغة"
              description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة."
              actionText="العودة إلى الصفحة الأولى"
              onAction={() => list.setPage(1)}
            />
          ) : (
            <EmptyState
              icon={FactoryIcon}
              title={list.hasActiveFilters ? 'لم يتم العثور على منشآت مطابقة' : 'لا توجد منشآت مسجلة بعد'}
              description={
                list.hasActiveFilters ? 'جرب تعديل البحث أو الفلاتر لعرض المنشآت المسجلة.' : 'أضف أول منشأة صناعية إلى المنظومة.'
              }
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : 'إضافة منشأة'}
              onAction={list.hasActiveFilters ? list.reset : () => setForm({ factory: null })}
            />
          )
        }
      >
        {(page) => (
          <>
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3" data-testid="factory-cards">
              {page.data.map((factory) => (
                <FactoryCard
                  key={factory.id}
                  factory={factory}
                  sizeName={factory.size ? nameOf(sizes.data, factory.size) : null}
                  canDecide={canDecide}
                  onDetails={() => setSelectedId(factory.id)}
                  onDecide={(decision) => {
                    setNotice(null);
                    setDeciding({ factory, decision });
                  }}
                />
              ))}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </>
        )}
      </QueryBoundary>

      {selectedId !== null && (
        <FactoryDetailModal
          key={selectedId}
          factoryId={selectedId}
          onClose={() => setSelectedId(null)}
          onEdit={(factory) => setForm({ factory })}
        />
      )}

      {deciding && (
        <ApprovalDecisionModal<Factory>
          subject="factory"
          decision={deciding.decision}
          subtitle={`المنشأة: ${deciding.factory.name}`}
          submit={(payload) => api.factories.decide(deciding.factory.id, payload)}
          onClose={() => setDeciding(null)}
          onDone={() => {
            setDeciding(null);
            setNotice('تم تسجيل القرار وإبلاغ المنشأة. لم يتغير مستوى جاهزيتها.');
            query.refetch();
          }}
        />
      )}

      {form && (
        <FactoryFormModal
          factory={form.factory}
          onClose={() => setForm(null)}
          onSaved={(saved) => {
            setForm(null);
            query.refetch();
            if (selectedId === null && form.factory === null) setSelectedId(saved.id);
          }}
        />
      )}
    </div>
  );
};

const FactoryDetailModal: React.FC<{ factoryId: number; onClose: () => void; onEdit: (factory: Factory) => void }> = ({
  factoryId,
  onClose,
  onEdit,
}) => {
  const factory = useApiQuery((signal) => api.factories.get(factoryId, signal), [factoryId]);
  const history = useApiQuery((signal) => api.readiness.list(factoryId, { per_page: 5, signal }), [factoryId]);
  const legacy = useApiQuery((signal) => api.factories.legacyAssessments(factoryId, signal), [factoryId]);
  const sizes = useFactorySizes();

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={factory.data?.name ?? 'ملف المنشأة'}
      subtitle={`منشأة رقم ${factoryId}`}
      maxWidth="4xl"
      footer={
        <div className="flex items-center justify-between w-full">
          <span className="text-xs text-[#667085]">تاريخ التسجيل: {formatDate(factory.data?.created_at)}</span>
          <div className="flex items-center gap-2">
            {factory.data && (
              <Button variant="secondary" size="sm" onClick={() => onEdit(factory.data as Factory)}>
                تعديل البيانات
              </Button>
            )}
            <Button variant="outline" size="sm" onClick={onClose}>
              إغلاق النافذة
            </Button>
          </div>
        </div>
      }
    >
      <QueryBoundary query={factory} loading={<TableSkeleton rows={3} cols={3} />}>
        {(f) => (
          <div className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="p-4 rounded-xl bg-white border border-[#E6EAF0]">
                <span className="text-xs text-[#667085]">فئة الجاهزية الحالية</span>
                <div className="mt-1 flex items-center justify-between">
                  <ReadinessBadge category={f.current_readiness?.category} />
                  <span className="font-extrabold text-lg text-[#5146A5]">{f.current_readiness ? f.current_readiness.total_score : '—'}</span>
                </div>
              </div>
              <div className="p-4 rounded-xl bg-white border border-[#E6EAF0]">
                <span className="text-xs text-[#667085]">الحجم المعلن</span>
                <div className="mt-1 text-lg font-bold text-[#172033]">{f.size ? nameOf(sizes.data, f.size) : 'غير محدد'}</div>
              </div>
              <div className="p-4 rounded-xl bg-white border border-[#E6EAF0]">
                <span className="text-xs text-[#667085]">القطاعات</span>
                <div className="mt-1 flex flex-wrap gap-1">
                  {(f.sectors ?? []).length === 0 ? (
                    <span className="text-sm text-[#98A2B3]">غير محددة</span>
                  ) : (
                    (f.sectors ?? []).map((sector) => (
                      <span key={sector.code} className="px-2 py-0.5 rounded-full bg-[#DFF3FF] text-[#0A6EB0] text-[11px] font-semibold">
                        {sector.name_ar}
                      </span>
                    ))
                  )}
                </div>
              </div>
            </div>

            <div className="p-4 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs">
              <h5 className="font-bold text-[#172033] mb-2">سجل تقييمات الجاهزية الرقمية</h5>
              <QueryBoundary
                query={history}
                loading={<TableSkeleton rows={2} cols={3} />}
                isEmpty={(page) => page.data.length === 0}
                empty={<p className="text-[#667085]">لم تُجرِ المنشأة أي تقييم للجاهزية الرقمية بعد.</p>}
              >
                {(page) => (
                  <>
                    <ul className="space-y-2">
                      {page.data.map((item) => (
                        <li key={item.id} className="p-2.5 rounded-lg bg-white border border-[#E6EAF0] flex items-center justify-between">
                          <span className="text-[#667085]">{formatDate(item.completed_at)}</span>
                          <span className="font-bold text-[#172033]">{item.total_score}</span>
                          <ReadinessBadge category={item.category} size="sm" />
                        </li>
                      ))}
                    </ul>
                    {page.meta.total > page.data.length && (
                      <p className="text-[11px] text-[#98A2B3] mt-2">يُعرض أحدث {page.data.length} من {page.meta.total} تقييمات.</p>
                    )}
                  </>
                )}
              </QueryBoundary>
            </div>

            {legacy.status === 'success' && (legacy.data ?? []).length > 0 && (
              <div className="p-4 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs">
                <h5 className="font-bold text-[#172033] mb-2">تصنيفات يدوية سابقة (للاطلاع فقط)</h5>
                <ul className="space-y-2">
                  {(legacy.data ?? []).map((item) => (
                    <li key={item.id} className="p-2.5 rounded-lg bg-white border border-[#E6EAF0] flex items-center justify-between">
                      <span className="text-[#667085]">{formatDate(item.assessed_on)}</span>
                      <span className="font-semibold text-[#172033]">{item.maturity_tier?.name_ar ?? '—'}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </QueryBoundary>
    </Modal>
  );
};
