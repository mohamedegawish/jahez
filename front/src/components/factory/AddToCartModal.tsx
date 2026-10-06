import React, { useState } from 'react';
import { ShoppingCart } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { BillingPeriod, ServiceCart, ServiceListing } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { formatMoneyOf } from '../../lib/format';
import { fieldErrorClass } from '../../lib/forms';
import { PERIOD_LABEL, packagePrice, usersLabel } from '../../lib/packages';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';

interface AddToCartModalProps {
  listing: ServiceListing;
  onClose: () => void;
  onAdded: (cart: ServiceCart) => void;
}

/**
 * Put one provider's offer of a service in the member's cart (jahez_api ADR-027): optionally a package,
 * the billing period it has a price for, and the number of users needed. Nothing is paid; the request is
 * sent from the cart, and the provider answers with its own offer.
 */
export const AddToCartModal: React.FC<AddToCartModalProps> = ({ listing, onClose, onAdded }) => {
  const packages = listing.packages;
  const [packageId, setPackageId] = useState<number | null>(packages[0]?.id ?? null);
  const chosen = packages.find((pkg) => pkg.id === packageId) ?? null;
  const periods = (['monthly', 'annual'] as BillingPeriod[]).filter((period) => chosen !== null && packagePrice(chosen, period) !== null);
  const [period, setPeriod] = useState<BillingPeriod | null>(periods[0] ?? null);
  const [users, setUsers] = useState('');
  const effectivePeriod = period !== null && periods.includes(period) ? period : (periods[0] ?? null);

  const add = useApiMutation(() =>
    api.cart.add({
      service: listing.service?.code ?? '',
      provider_id: listing.provider?.id ?? 0,
      package_id: packageId,
      billing_period: effectivePeriod,
      users_count: users.trim() === '' ? null : Number(users),
    }),
  );

  const errors = (field: string) => fieldMessages(add.error, field);
  const hasFieldErrors = ['service', 'provider_id', 'package_id', 'billing_period', 'users_count'].some((field) => errors(field).length > 0);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="إضافة إلى سلة الطلبات"
      subtitle={`${listing.service?.name_ar ?? ''} — ${listing.provider?.name ?? ''}`}
      maxWidth="lg"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={add.pending}>
            إلغاء
          </Button>
          <Button
            variant="primary"
            size="sm"
            icon={ShoppingCart}
            isLoading={add.pending}
            data-action="add-to-cart"
            onClick={async () => {
              const result = await add.run();
              if (result.ok) onAdded(result.data);
            }}
          >
            إضافة إلى السلة
          </Button>
        </div>
      }
    >
      <div className="space-y-4 text-xs">
        {add.error !== null && !hasFieldErrors && <ApiErrorState compact error={add.error} />}
        <FieldError messages={[...errors('service'), ...errors('provider_id')]} />

        <fieldset>
          <legend className="font-bold text-[#172033] mb-1.5">الباقة</legend>
          <div className="space-y-2">
            {packages.map((pkg) => (
              <label
                key={pkg.id}
                className={`flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer ${packageId === pkg.id ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0]'}`}
              >
                <input type="radio" name="package" className="mt-0.5 accent-[#5146A5]" checked={packageId === pkg.id} onChange={() => setPackageId(pkg.id)} />
                <span className="flex-1">
                  <span className="font-bold text-[#172033] block">{pkg.name_ar}</span>
                  <span className="text-[11px] text-[#667085] block">
                    {[
                      pkg.monthly_price !== null ? `${formatMoneyOf(pkg.monthly_price)} / ${PERIOD_LABEL.monthly}` : null,
                      pkg.annual_price !== null ? `${formatMoneyOf(pkg.annual_price)} / ${PERIOD_LABEL.annual}` : null,
                      usersLabel(pkg.users_count),
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                </span>
              </label>
            ))}
            <label className={`flex items-center gap-2.5 p-3 rounded-xl border cursor-pointer ${packageId === null ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0]'}`}>
              <input type="radio" name="package" className="accent-[#5146A5]" checked={packageId === null} onChange={() => setPackageId(null)} />
              <span className="text-[#344054]">بدون باقة — يحدد المزود السعر في عرضه</span>
            </label>
          </div>
          <FieldError messages={errors('package_id')} />
        </fieldset>

        {periods.length > 0 && (
          <fieldset>
            <legend className="font-bold text-[#172033] mb-1.5">مدة الاشتراك</legend>
            <div className="flex gap-2">
              {periods.map((option) => (
                <label key={option} className={`flex-1 flex items-center justify-between gap-2 p-2.5 rounded-xl border cursor-pointer ${effectivePeriod === option ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0]'}`}>
                  <span className="flex items-center gap-2">
                    <input type="radio" name="period" className="accent-[#5146A5]" checked={effectivePeriod === option} onChange={() => setPeriod(option)} />
                    {option === 'monthly' ? 'شهري' : 'سنوي'}
                  </span>
                  <span className="font-bold text-[#5146A5]">{chosen ? formatMoneyOf(packagePrice(chosen, option)) : ''}</span>
                </label>
              ))}
            </div>
            <FieldError messages={errors('billing_period')} />
          </fieldset>
        )}

        <div>
          <label htmlFor="cart-users" className="font-bold text-[#172033] block mb-1">عدد المستخدمين المطلوب (اختياري)</label>
          <input
            id="cart-users"
            type="number"
            min={1}
            dir="ltr"
            value={users}
            onChange={(e) => setUsers(e.target.value)}
            placeholder={chosen?.users_count ? String(chosen.users_count) : ''}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(errors('users_count').length > 0)}`}
          />
          <FieldError messages={errors('users_count')} />
        </div>

        <p className="text-[11px] text-[#98A2B3]">الأسعار معلنة من المزود وللاسترشاد؛ لا يتم أي دفع عبر المنصة، والسعر النهائي في عرض المزود.</p>
      </div>
    </Modal>
  );
};
