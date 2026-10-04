import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { Offer } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { today } from '../../lib/format';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';

/** A price is a decimal string with at most two places, exactly as the API stores it (never a float). */
const AMOUNT = /^\d{1,12}(\.\d{1,2})?$/;

interface OfferFormProps {
  threadId: number;
  /** The newest version the provider has seen; the new version is based on it (null for the first). */
  latest: Offer | null;
  onSubmitted: (offer: Offer) => void;
  /** A stale-version conflict means someone/something added a newer version: reload the list. */
  onConflict: () => void;
}

/**
 * The provider's offer form. Offers are append-only versions: submitting creates version n+1 and
 * never edits an earlier one. The price is sent as {amount: "250000.00", currency: "EGP"}.
 */
export const OfferForm: React.FC<OfferFormProps> = ({ threadId, latest, onSubmitted, onConflict }) => {
  const [scope, setScope] = useState(latest?.scope ?? '');
  const [deliverables, setDeliverables] = useState(latest?.deliverables ?? '');
  const [days, setDays] = useState(latest ? String(latest.duration_days) : '');
  const [validUntil, setValidUntil] = useState('');
  const [amount, setAmount] = useState(latest?.price.amount ?? '');

  const submit = useApiMutation(() =>
    api.providerRequests.offers.create(threadId, {
      based_on_version: latest?.version ?? null,
      scope: scope.trim(),
      deliverables: deliverables.trim(),
      duration_days: Number(days),
      ...(validUntil ? { valid_until: validUntil } : {}),
      price: { amount: amount.trim(), currency: 'EGP' },
    }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await submit.run();
    if (result.ok) onSubmitted(result.data);
    else if (result.error && typeof result.error === 'object' && 'status' in result.error && (result.error as { status: number }).status === 409) onConflict();
  };

  const errors = {
    scope: fieldMessages(submit.error, 'scope'),
    deliverables: fieldMessages(submit.error, 'deliverables'),
    days: fieldMessages(submit.error, 'duration_days'),
    validUntil: fieldMessages(submit.error, 'valid_until'),
    price: fieldMessages(submit.error, 'price'),
  };
  const hasFieldErrors = Object.values(errors).some((list) => list.length > 0) || fieldMessages(submit.error, 'based_on_version').length > 0;
  const valid = scope.trim() !== '' && deliverables.trim() !== '' && Number(days) >= 1 && AMOUNT.test(amount.trim());

  const box = 'w-full p-2 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] focus:outline-none focus:border-[#6EC8FF]';

  return (
    <form onSubmit={handleSubmit} className="space-y-3 text-xs" noValidate data-testid="offer-form">
      <p className="text-[11px] text-[#667085] leading-relaxed">
        {latest ? `ستُضاف نسخة جديدة (${latest.version + 1}) مبنية على آخر نسخة؛ لا تُعدَّل النسخ السابقة.` : 'هذا أول عرض لكم على هذا الطلب (النسخة 1).'}
      </p>
      {submit.error !== null && !hasFieldErrors && <ApiErrorState compact error={submit.error} />}

      <div>
        <label htmlFor="offer-scope" className="text-[11px] font-bold text-[#667085] block mb-1">نطاق العمل:</label>
        <textarea id="offer-scope" rows={3} maxLength={5000} value={scope} onChange={(e) => setScope(e.target.value)} className={`${box} ${fieldErrorClass(errors.scope.length > 0)}`} />
        <FieldError messages={errors.scope} />
      </div>
      <div>
        <label htmlFor="offer-deliverables" className="text-[11px] font-bold text-[#667085] block mb-1">المخرجات:</label>
        <textarea id="offer-deliverables" rows={3} maxLength={5000} value={deliverables} onChange={(e) => setDeliverables(e.target.value)} className={`${box} ${fieldErrorClass(errors.deliverables.length > 0)}`} />
        <FieldError messages={errors.deliverables} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label htmlFor="offer-days" className="text-[11px] font-bold text-[#667085] block mb-1">مدة التنفيذ (أيام):</label>
          <input id="offer-days" type="number" min={1} max={3650} value={days} onChange={(e) => setDays(e.target.value)} className={`${box} ${fieldErrorClass(errors.days.length > 0)}`} dir="ltr" />
          <FieldError messages={errors.days} />
        </div>
        <div>
          <label htmlFor="offer-valid" className="text-[11px] font-bold text-[#667085] block mb-1">صالح حتى (اختياري):</label>
          <input id="offer-valid" type="date" min={today()} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} className={`${box} ${fieldErrorClass(errors.validUntil.length > 0)}`} dir="ltr" />
          <FieldError messages={errors.validUntil} />
        </div>
      </div>
      <div>
        <label htmlFor="offer-amount" className="text-[11px] font-bold text-[#667085] block mb-1">السعر الإجمالي (ج.م):</label>
        <input
          id="offer-amount"
          type="text"
          inputMode="decimal"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
          placeholder="250000.00"
          className={`${box} font-bold ${fieldErrorClass(errors.price.length > 0 || (amount !== '' && !AMOUNT.test(amount.trim())))}`}
          dir="ltr"
        />
        {amount !== '' && !AMOUNT.test(amount.trim()) && <p className="mt-1 text-[11px] text-[#B82B3B]">أدخل رقمًا بخانتين عشريتين كحد أقصى، مثل 250000.50</p>}
        <FieldError messages={errors.price} />
      </div>

      <Button type="submit" variant="primary" size="md" className="w-full" isLoading={submit.pending} disabled={!valid}>
        {latest ? `إرسال النسخة ${latest.version + 1}` : 'إرسال العرض'}
      </Button>
    </form>
  );
};
