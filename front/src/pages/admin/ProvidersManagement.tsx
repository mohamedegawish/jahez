import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Building2, Plus, ShieldCheck } from 'lucide-react';
import { api } from '../../api';
import type { ServiceProvider } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useCatalogServices, useSectors } from '../../hooks/useReference';
import type { ApprovalDecision } from '../../lib/provider';
import { ApprovalDecisionModal } from '../../components/admin/ApprovalDecisionModal';
import { ProviderAdminCard } from '../../components/admin/ProviderAdminCard';
import { ProviderFormModal } from '../../components/admin/ProviderFormModal';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { approvalLabel } from '../../lib/labels';

const FILTER_KEYS = ['approval_status', 'sector', 'service'] as const;
const STATUSES = ['pending', 'changes_requested', 'approved', 'rejected', 'suspended'] as const;
const SORTS = [
  { value: '', label: 'ترتيب التسجيل' },
  { value: 'newest', label: 'الأحدث تسجيلًا' },
  { value: 'oldest', label: 'الأقدم تسجيلًا' },
  { value: 'name', label: 'الاسم (أ–ي)' },
  { value: 'recently_decided', label: 'آخر قرار اعتماد' },
];
const selectClass =
  'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * IMC list of service providers as cards (ADR-021): the server filters, sorts and paginates; each card
 * shows the account's approval status and how many of its services IMC approved. Decisions use the same
 * approval endpoint and rules as the review page; legal and contact details stay on that page.
 */
export const ProvidersManagement: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const sectors = useSectors();
  const services = useCatalogServices();
  const { hasPermission } = useAuth();
  const canDecide = hasPermission('service_providers.approve');
  const [creating, setCreating] = useState(false);
  const [deciding, setDeciding] = useState<{ provider: ServiceProvider; decision: ApprovalDecision } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const query = useApiQuery(
    (signal) => api.serviceProviders.list({ ...toApiQuery(list), signal }),
    [list.page, list.perPage, list.search, list.sort, list.filters.approval_status, list.filters.sector, list.filters.service],
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">مزودو الخدمات والحلول الصناعية</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            دليل الشركات المسجلة في المنظومة وحالة اعتماد كل منها لدى مركز تحديث الصناعة.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" icon={ShieldCheck} onClick={() => navigate('/admin/approvals/providers')}>
            شاشة مراجعة واعتماد المزودين
          </Button>
          <Button variant="primary" size="sm" icon={Plus} onClick={() => setCreating(true)}>
            إضافة مزود
          </Button>
        </div>
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم الشركة..." className="w-full" />
          <select
            aria-label="حالة الاعتماد"
            value={list.filters.approval_status}
            onChange={(e) => list.setFilter('approval_status', e.target.value)}
            className={selectClass}
          >
            <option value="">كافة حالات الاعتماد</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {approvalLabel(status)}
              </option>
            ))}
          </select>
          <select aria-label="القطاع" value={list.filters.sector} onChange={(e) => list.setFilter('sector', e.target.value)} className={selectClass}>
            <option value="">كافة القطاعات</option>
            {(sectors.data ?? []).map((sector) => (
              <option key={sector.code} value={sector.code}>
                {sector.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="الخدمة" value={list.filters.service} onChange={(e) => list.setFilter('service', e.target.value)} className={selectClass}>
            <option value="">كافة الخدمات</option>
            {(services.data ?? []).map((service) => (
              <option key={service.code} value={service.code}>
                {service.name_ar}
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
          <span aria-live="polite">{query.data ? `${query.data.meta.total} مزود خدمة` : ''}</span>
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
              icon={Building2}
              title={list.hasActiveFilters ? 'لم يتم العثور على مزودين مطابقين' : 'لا يوجد مزودو خدمات مسجلون بعد'}
              description={list.hasActiveFilters ? 'جرب تعديل البحث أو الفلاتر.' : 'أضف أول مزود خدمة إلى المنظومة.'}
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : 'إضافة مزود'}
              onAction={list.hasActiveFilters ? list.reset : () => setCreating(true)}
            />
          )
        }
      >
        {(page) => (
          <>
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3" data-testid="provider-cards">
              {page.data.map((provider) => (
                <ProviderAdminCard
                  key={provider.id}
                  provider={provider}
                  canDecide={canDecide}
                  onDecide={(decision) => {
                    setNotice(null);
                    setDeciding({ provider, decision });
                  }}
                />
              ))}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </>
        )}
      </QueryBoundary>

      {deciding && (
        <ApprovalDecisionModal<ServiceProvider>
          subject="provider"
          decision={deciding.decision}
          subtitle={`المزود: ${deciding.provider.name}`}
          submit={(payload) => api.serviceProviders.decide(deciding.provider.id, payload)}
          onClose={() => setDeciding(null)}
          onDone={() => {
            setDeciding(null);
            setNotice('تم تسجيل القرار وإبلاغ المزود.');
            query.refetch();
          }}
        />
      )}

      {creating && (
        <ProviderFormModal
          onClose={() => setCreating(false)}
          onSaved={(created) => {
            setCreating(false);
            query.refetch();
            navigate(`/admin/approvals/providers/${created.id}`);
          }}
        />
      )}
    </div>
  );
};
