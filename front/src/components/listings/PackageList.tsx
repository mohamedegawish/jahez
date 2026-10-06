import React from 'react';
import { Tag, Users } from 'lucide-react';
import type { ProviderRequestSelection, ServiceListingPackage } from '../../api';
import { formatMoneyOf } from '../../lib/format';
import { PERIOD_LABEL, packagePrice, usersLabel } from '../../lib/packages';

/**
 * A provider's packages and prices for a service (jahez_api ADR-027): EGP, informational. The provider
 * still answers each request with its own offer, so an empty list reads «السعر في عرض المزود».
 */
export const PackageList: React.FC<{ packages: ServiceListingPackage[]; compact?: boolean }> = ({ packages, compact = false }) => {
  if (packages.length === 0) {
    return <p className="text-[11px] text-[#98A2B3]">لا توجد باقات معلنة؛ يحدد المزود السعر في عرضه.</p>;
  }

  return (
    <ul className="space-y-1.5" data-testid="listing-packages">
      {(compact ? packages.slice(0, 2) : packages).map((pkg) => (
        <li key={pkg.id} className="p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[11px]">
          <div className="flex items-center justify-between gap-2">
            <span className="font-bold text-[#172033] flex items-center gap-1 min-w-0 truncate">
              <Tag className="w-3 h-3 text-[#5146A5] shrink-0" /> {pkg.name_ar}
            </span>
            {usersLabel(pkg.users_count) && (
              <span className="text-[#667085] flex items-center gap-1 shrink-0">
                <Users className="w-3 h-3" /> {usersLabel(pkg.users_count)}
              </span>
            )}
          </div>
          <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-1 text-[#344054]">
            {pkg.monthly_price !== null && <span>{formatMoneyOf(pkg.monthly_price)} / {PERIOD_LABEL.monthly}</span>}
            {pkg.annual_price !== null && <span>{formatMoneyOf(pkg.annual_price)} / {PERIOD_LABEL.annual}</span>}
          </div>
        </li>
      ))}
      {compact && packages.length > 2 && <li className="text-[10px] text-[#98A2B3]">و{packages.length - 2} باقة أخرى</li>}
    </ul>
  );
};

/** What the factory chose from the provider's listing in its cart, as kept on the thread (as listed then). */
export const SelectionSummary: React.FC<{ selection: ProviderRequestSelection | null | undefined }> = ({ selection }) => {
  if (!selection || (selection.package === null && selection.billing_period === null && selection.users_count === null)) return null;
  const price = selection.package && selection.billing_period ? packagePrice(selection.package, selection.billing_period) : null;

  return (
    <div className="p-2.5 rounded-lg bg-[#EEEAFE]/50 border border-[#DDD5FD] text-[11px] text-[#344054] space-y-0.5" data-testid="thread-selection">
      <div className="font-bold text-[#5146A5]">اختيار المصنع من السلة</div>
      <div>
        {selection.package ? `الباقة: ${selection.package.name_ar}` : 'بدون باقة محددة'}
        {selection.billing_period && ` · ${selection.billing_period === 'monthly' ? 'اشتراك شهري' : 'اشتراك سنوي'}`}
        {price !== null && ` · ${formatMoneyOf(price)} (السعر المعلن وقت الطلب)`}
      </div>
      {selection.users_count !== null && <div>عدد المستخدمين المطلوب: {selection.users_count}</div>}
    </div>
  );
};
