import React, { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CheckCircle2, Compass, Send, ShoppingCart, Trash2 } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { BillingPeriod, ServiceCart, ServiceCartCheckoutEntry, ServiceCartItem, ServiceRequest } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { formatMoneyOf } from '../../lib/format';
import { fieldErrorClass } from '../../lib/forms';
import { FactoryApprovalNotice } from '../../components/factory/FactoryApprovalNotice';
import { PERIOD_LABEL, packagePrice, usersLabel } from '../../lib/packages';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { FieldError } from '../../components/ui/FieldError';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';

const UNAVAILABLE_TEXT: Record<string, string> = {
  service_not_available: 'لم تعد الخدمة متاحة لمستوى جاهزية منشأتكم.',
  provider_not_eligible: 'لا يقدم المزود هذه الخدمة لمنشأتكم حاليًا (قد تكون باقاته وأسعاره قيد مراجعة المركز). احذفوه قبل الإرسال.',
};

const inputClass = 'w-full p-2 rounded-lg border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF]';

interface Draft {
  include: boolean;
  title: string;
  need: string;
  requirements: string;
}

interface ServiceGroup {
  code: string;
  name_ar: string;
  items: ServiceCartItem[];
}

function groupByService(cart: ServiceCart): ServiceGroup[] {
  const groups = new Map<string, ServiceGroup>();
  for (const item of cart.items) {
    const group = groups.get(item.service.code) ?? { code: item.service.code, name_ar: item.service.name_ar, items: [] };
    group.items.push(item);
    groups.set(item.service.code, group);
  }
  return [...groups.values()];
}

/**
 * The factory member's cart (jahez_api ADR-027): provider offers chosen from the services page, with a
 * package, a billing period and a number of users. Sending creates one service request per service, to
 * every provider in the cart for it; each provider sees the choice made for it and answers with its own
 * offer. The totals are estimates from the listed prices: nothing is paid through the platform.
 */
export const FactoryCart: React.FC = () => {
  const navigate = useNavigate();
  const cart = useApiQuery((signal) => api.cart.get(signal), []);
  const factory = useMyFactory();
  const blocked = factory.data !== undefined && !factory.data.approval.may_send_requests;
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [sent, setSent] = useState<ServiceRequest[] | null>(null);

  const change = useApiMutation((itemId: number, payload: { package_id?: number | null; billing_period?: BillingPeriod | null; users_count?: number | null }) =>
    api.cart.update(itemId, payload),
  );
  const remove = useApiMutation((itemId: number) => api.cart.remove(itemId));
  const clear = useApiMutation(() => api.cart.clear());
  const checkout = useApiMutation((entries: ServiceCartCheckoutEntry[]) => api.cart.checkout(entries));

  const groups = useMemo(() => (cart.data ? groupByService(cart.data) : []), [cart.data]);
  const draftOf = (group: ServiceGroup): Draft =>
    drafts[group.code] ?? { include: group.items.every((item) => item.available), title: `طلب ${group.name_ar}`, need: '', requirements: '' };
  const setDraft = (group: ServiceGroup, patch: Partial<Draft>) => setDrafts((current) => ({ ...current, [group.code]: { ...draftOf(group), ...patch } }));

  const included = groups.filter((group) => draftOf(group).include);
  const entries: ServiceCartCheckoutEntry[] = included.map((group) => {
    const draft = draftOf(group);
    return {
      service: group.code,
      title: draft.title.trim(),
      need: draft.need.trim(),
      ...(draft.requirements.trim() ? { requirements: draft.requirements.trim() } : {}),
    };
  });
  const ready = entries.length > 0 && entries.every((entry) => entry.title !== '' && entry.need !== '');

  const changeItem = async (item: ServiceCartItem, payload: { package_id?: number | null; billing_period?: BillingPeriod | null; users_count?: number | null }) => {
    const result = await change.run(item.id, payload);
    if (result.ok) cart.refetch();
  };

  const choosePackage = (item: ServiceCartItem, value: string) => {
    const packageId = value === '' ? null : Number(value);
    const pkg = item.packages.find((candidate) => candidate.id === packageId) ?? null;
    const keep = item.billing_period !== null && pkg !== null && packagePrice(pkg, item.billing_period) !== null;
    const period: BillingPeriod | null = keep ? item.billing_period : pkg?.monthly_price != null ? 'monthly' : pkg?.annual_price != null ? 'annual' : null;
    void changeItem(item, { package_id: packageId, billing_period: period });
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight flex items-center gap-2">
            <ShoppingCart className="w-6 h-6 text-[#5146A5]" /> سلة الطلبات
          </h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            اجمعوا عروض المزودين للخدمات التي تحتاجونها، ثم أرسلوا طلبًا واحدًا لكل خدمة إلى المزودين الذين اخترتموهم. لا يتم أي دفع عبر المنصة.
          </p>
        </div>
        <Button variant="outline" size="sm" icon={Compass} onClick={() => navigate('/factory/services')}>
          متابعة تصفح الخدمات
        </Button>
      </div>

      {factory.data && <FactoryApprovalNotice factory={factory.data} />}

      {sent && sent.length > 0 && (
        <div role="status" className="p-4 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs text-[#1D7E4C] space-y-1.5" data-testid="cart-sent">
          <div className="font-bold flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4" /> أُرسلت {sent.length === 1 ? 'الطلب' : `${sent.length} طلبات`} إلى المزودين.
          </div>
          <ul className="list-disc pr-5">
            {sent.map((request) => (
              <li key={request.id}>
                <Link to={`/factory/requests/${request.id}`} className="underline font-semibold">
                  {request.title}
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}

      {[change.error, remove.error, clear.error].map((error, index) => error !== null && <ApiErrorState key={index} compact error={error} />)}

      <QueryBoundary
        query={cart}
        loading={<CardSkeleton />}
        isEmpty={(data) => data.items.length === 0}
        empty={
          <EmptyState
            title="السلة فارغة"
            description="أضيفوا عروض المزودين من صفحة الخدمات بزر «أضف للسلة»، مع الباقة ومدة الاشتراك وعدد المستخدمين."
            actionText="تصفح الخدمات"
            onAction={() => navigate('/factory/services')}
          />
        }
      >
        {(data) => (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div className="lg:col-span-2 space-y-5">
              {groups.map((group) => {
                const draft = draftOf(group);
                const index = included.indexOf(group);
                const error = (field: string) => (index === -1 ? [] : fieldMessages(checkout.error, `requests.${index}.${field}`));

                return (
                  <Card key={group.code} title={group.name_ar} subtitle={`${group.items.length} مزود`} accent="blue">
                    <div className="space-y-3 text-xs" data-cart-service={group.code}>
                      {group.items.map((item) => (
                        <div key={item.id} className={`p-3 rounded-xl border ${item.available ? 'border-[#E6EAF0] bg-white' : 'border-[#FDE5BE] bg-[#FEF5E7]/60'}`} data-cart-item={item.id}>
                          <div className="flex items-center justify-between gap-2">
                            <span className="font-bold text-[#172033]">{item.provider.name}</span>
                            <Button
                              variant="ghost"
                              size="sm"
                              icon={Trash2}
                              aria-label={`حذف ${item.provider.name} من السلة`}
                              onClick={async () => {
                                const result = await remove.run(item.id);
                                if (result.ok) cart.refetch();
                              }}
                            >
                              حذف
                            </Button>
                          </div>
                          {!item.available && item.unavailable_reason && <p className="text-[#A66F0B] mt-1">{UNAVAILABLE_TEXT[item.unavailable_reason]}</p>}
                          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2">
                            <label className="block">
                              <span className="text-[#667085] block mb-1">الباقة</span>
                              <select className={inputClass} value={item.package?.id ?? ''} onChange={(e) => choosePackage(item, e.target.value)} disabled={change.pending}>
                                <option value="">بدون باقة (السعر في العرض)</option>
                                {item.packages.map((pkg) => (
                                  <option key={pkg.id} value={pkg.id}>
                                    {pkg.name_ar}
                                    {usersLabel(pkg.users_count) ? ` — ${usersLabel(pkg.users_count)}` : ''}
                                  </option>
                                ))}
                              </select>
                            </label>
                            <label className="block">
                              <span className="text-[#667085] block mb-1">مدة الاشتراك</span>
                              <select
                                className={inputClass}
                                value={item.billing_period ?? ''}
                                disabled={change.pending || item.package === null}
                                onChange={(e) => void changeItem(item, { billing_period: e.target.value === '' ? null : (e.target.value as BillingPeriod) })}
                              >
                                <option value="">—</option>
                                {item.package &&
                                  (['monthly', 'annual'] as BillingPeriod[])
                                    .filter((period) => packagePrice(item.package!, period) !== null)
                                    .map((period) => (
                                      <option key={period} value={period}>
                                        {period === 'monthly' ? 'شهري' : 'سنوي'} — {formatMoneyOf(packagePrice(item.package!, period))}
                                      </option>
                                    ))}
                              </select>
                            </label>
                            <label className="block">
                              <span className="text-[#667085] block mb-1">عدد المستخدمين</span>
                              <input
                                type="number"
                                min={1}
                                dir="ltr"
                                className={inputClass}
                                defaultValue={item.users_count ?? ''}
                                key={`${item.id}-${item.users_count ?? ''}`}
                                onBlur={(e) => {
                                  const value = e.target.value.trim() === '' ? null : Number(e.target.value);
                                  if (value !== item.users_count) void changeItem(item, { users_count: value });
                                }}
                              />
                            </label>
                          </div>
                          <div className="mt-2 text-[#344054]">
                            {item.price !== null && item.billing_period
                              ? `السعر المعلن: ${formatMoneyOf(item.price)} / ${PERIOD_LABEL[item.billing_period]}`
                              : 'يحدد المزود السعر في عرضه.'}
                          </div>
                        </div>
                      ))}

                      <div className="pt-3 border-t border-[#E6EAF0] space-y-2">
                        <label className="flex items-center gap-2 font-bold text-[#172033]">
                          <input type="checkbox" className="accent-[#5146A5]" checked={draft.include} onChange={(e) => setDraft(group, { include: e.target.checked })} data-action="include-service" />
                          إرسال طلب هذه الخدمة الآن
                        </label>
                        {draft.include && (
                          <>
                            <FieldError messages={error('service')} />
                            <div>
                              <label className="block font-semibold text-[#344054] mb-1" htmlFor={`title-${group.code}`}>عنوان الطلب</label>
                              <input id={`title-${group.code}`} className={`${inputClass} ${fieldErrorClass(error('title').length > 0)}`} maxLength={200} value={draft.title} onChange={(e) => setDraft(group, { title: e.target.value })} />
                              <FieldError messages={error('title')} />
                            </div>
                            <div>
                              <label className="block font-semibold text-[#344054] mb-1" htmlFor={`need-${group.code}`}>وصف الاحتياج</label>
                              <textarea id={`need-${group.code}`} rows={3} className={`${inputClass} ${fieldErrorClass(error('need').length > 0)}`} maxLength={5000} value={draft.need} onChange={(e) => setDraft(group, { need: e.target.value })} />
                              <FieldError messages={error('need')} />
                            </div>
                            <div>
                              <label className="block font-semibold text-[#344054] mb-1" htmlFor={`req-${group.code}`}>متطلبات إضافية (اختياري)</label>
                              <textarea id={`req-${group.code}`} rows={2} className={inputClass} maxLength={5000} value={draft.requirements} onChange={(e) => setDraft(group, { requirements: e.target.value })} />
                            </div>
                          </>
                        )}
                      </div>
                    </div>
                  </Card>
                );
              })}
            </div>

            <Card title="ملخص السلة" accent="purple">
              <div className="space-y-3 text-xs" data-testid="cart-summary">
                <div className="flex justify-between"><span className="text-[#667085]">الخدمات</span><strong>{data.summary.services_count}</strong></div>
                <div className="flex justify-between"><span className="text-[#667085]">عروض المزودين</span><strong>{data.summary.items_count}</strong></div>
                <div className="flex justify-between"><span className="text-[#667085]">الإجمالي الشهري التقديري</span><strong>{formatMoneyOf(data.summary.estimated_monthly_total, data.summary.currency)}</strong></div>
                <div className="flex justify-between"><span className="text-[#667085]">الإجمالي السنوي التقديري</span><strong>{formatMoneyOf(data.summary.estimated_annual_total, data.summary.currency)}</strong></div>
                <p className="text-[11px] text-[#98A2B3] leading-relaxed">
                  الإجمالي تقديري من الأسعار المعلنة للعروض المتاحة، وليس عرض سعر ولا فاتورة. لا يتم أي دفع عبر المنصة؛ يرد كل مزود بعرضه على الطلب.
                </p>
                {checkout.error !== null && fieldMessages(checkout.error, 'requests').length > 0 && <FieldError messages={fieldMessages(checkout.error, 'requests')} />}
                {checkout.error !== null && <ApiErrorState compact error={checkout.error} />}
                <Button
                  variant="primary"
                  size="md"
                  icon={Send}
                  className="w-full"
                  isLoading={checkout.pending}
                  disabled={blocked || !ready}
                  data-action="checkout"
                  onClick={async () => {
                    const result = await checkout.run(entries);
                    if (result.ok) {
                      setSent(result.data);
                      setDrafts({});
                      cart.refetch();
                    }
                  }}
                >
                  إرسال {entries.length === 1 ? 'الطلب' : `${entries.length} طلبات`}
                </Button>
                {!ready && entries.length > 0 && <p className="text-[11px] text-[#A66F0B]">اكتبوا عنوان الطلب ووصف الاحتياج لكل خدمة محددة للإرسال.</p>}
                <Button
                  variant="ghost"
                  size="sm"
                  icon={Trash2}
                  className="w-full"
                  isLoading={clear.pending}
                  onClick={async () => {
                    const result = await clear.run();
                    if (result.ok) cart.refetch();
                  }}
                >
                  تفريغ السلة
                </Button>
              </div>
            </Card>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};
