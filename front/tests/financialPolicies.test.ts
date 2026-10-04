// Run with `npm test` (node --test; Node strips the TypeScript types). These cover the browser-side
// checks and Arabic messages of the financial and contract policies (jahez_api ADR-023). The
// server repeats every check; these tests make sure the forms never assume a value and that the
// server's Arabic explanation of a blocked operation reaches the user.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildParameters,
  businessToday,
  describeParameters,
  emptyForm,
  formFromParameters,
  isPercent,
  periodErrors,
} from '../src/lib/financialPolicies.ts';
import { ApiError, describeError, parseErrorBody } from '../src/api/errors.ts';

test('empty forms assume no rate, tax, issuer, due date or yes/no rule', () => {
  assert.deepEqual(emptyForm('revenue_share'), { rate_percent: '' });
  assert.deepEqual(emptyForm('tax'), { prices_include_tax: null, taxes: [], fees: [] });
  assert.equal(emptyForm('invoicing').issuer, '');
  assert.equal(emptyForm('invoicing').manual_payments_allowed, null);
  assert.equal(emptyForm('payment_terms').due_days, '');
  assert.equal(emptyForm('payment_terms').partial_payments_allowed, null);
  assert.equal(emptyForm('contract_template').include_imc, null);
});

test('an empty form cannot be saved, and every error is in Arabic on the API field path', () => {
  for (const kind of ['revenue_share', 'tax', 'invoicing', 'payment_terms', 'contract_template'] as const) {
    const result = buildParameters(kind, emptyForm(kind));
    assert.equal(result.ok, false, kind);
    if (!result.ok) {
      for (const [field, message] of Object.entries(result.errors)) {
        assert.match(field, /^parameters\./, `${kind}: ${field}`);
        assert.match(message, /[؀-ۿ]/, `${kind}: ${field} is not Arabic`);
      }
    }
  }
});

test('percentages accept 0 to 100 with at most two decimals, as strings', () => {
  for (const ok of ['0', '14', '12.5', '12.50', '100']) assert.equal(isPercent(ok), true, ok);
  for (const bad of ['', '100.01', '12.125', '-1', '1e2', 'abc', '101']) assert.equal(isPercent(bad), false, bad);

  const result = buildParameters('revenue_share', { rate_percent: '12.125' });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.errors['parameters.rate_percent'], 'أدخل نسبة من 0 إلى 100 بحد أقصى منزلتين عشريتين.');
});

test('a valid revenue share builds the exact parameters the API expects, without converting to a number', () => {
  const result = buildParameters('revenue_share', { rate_percent: ' 12.5 ' });
  assert.deepEqual(result, { ok: true, parameters: { method: 'percentage', rate_percent: '12.5', base: 'subtotal_before_tax' } });
});

test('taxes: the inclusive-pricing question must be answered, and rates may not exceed 100% together', () => {
  const unanswered = buildParameters('tax', { prices_include_tax: null, taxes: [], fees: [] });
  assert.equal(unanswered.ok, false);
  if (!unanswered.ok) assert.equal(unanswered.errors['parameters.prices_include_tax'], 'اختر نعم أو لا؛ لا تُفترض أي قيمة.');

  const tooMuch = buildParameters('tax', {
    prices_include_tax: false,
    taxes: [
      { code: 'a', name_ar: 'أ', rate_percent: '60' },
      { code: 'b', name_ar: 'ب', rate_percent: '40.01' },
    ],
    fees: [],
  });
  assert.equal(tooMuch.ok, false);
  if (!tooMuch.ok) assert.equal(tooMuch.errors['parameters.taxes'], 'مجموع نسب الضرائب لا يتجاوز 100%.');

  const none = buildParameters('tax', { prices_include_tax: false, taxes: [], fees: [] });
  assert.deepEqual(none, { ok: true, parameters: { prices_include_tax: false, taxes: [], fees: [] } });
});

test('fees need a calculation method and an amount or rate that matches it', () => {
  const result = buildParameters('tax', {
    prices_include_tax: true,
    taxes: [],
    fees: [{ code: 'x', name_ar: 'رسم', calculation: 'fixed', amount: '10.005', rate_percent: '', taxable: null }],
  });
  assert.equal(result.ok, false);
  if (!result.ok) {
    assert.ok(result.errors['parameters.fees.0.amount']);
    assert.ok(result.errors['parameters.fees.0.taxable']);
  }
});

test('contract templates need the knowledge-transfer minimum of DOC §6 and at least one clause', () => {
  const form = { title_ar: 'عقد', include_imc: false, duration_months: '', knowledge_transfer_min_trainees: '1', clauses: [] };
  const result = buildParameters('contract_template', form);
  assert.equal(result.ok, false);
  if (!result.ok) {
    assert.equal(result.errors['parameters.knowledge_transfer_min_trainees'], 'مهندسان على الأقل (المصدر: DOC §6).');
    assert.equal(result.errors['parameters.clauses'], 'أضف بندًا واحدًا على الأقل.');
  }

  const valid = buildParameters('contract_template', { ...form, knowledge_transfer_min_trainees: '2', clauses: [{ heading_ar: 'نطاق', body_ar: 'نص' }] });
  assert.equal(valid.ok, true);
  if (valid.ok) assert.deepEqual((valid.parameters as { parties: string[]; duration_months: null }).parties, ['factory', 'service_provider']);
});

test('a form filled from an approved version round-trips to the same parameters', () => {
  const parameters = {
    issuer: 'imc' as const, payer: 'factory' as const, currency: 'EGP' as const, number_prefix: 'JZ', number_padding: 6,
    invoice_types: ['agreement_service' as const], manual_payments_allowed: true, requires_revenue_share: false,
  };
  assert.deepEqual(buildParameters('invoicing', formFromParameters('invoicing', parameters)), { ok: true, parameters });
});

test('effective periods never start in the past and never end before they start', () => {
  assert.deepEqual(periodErrors('2026-10-05', '', '2026-10-05'), {});
  assert.equal(periodErrors('2026-10-04', '', '2026-10-05').effective_from, 'لا يبدأ أي إصدار في الماضي؛ لا يُطبَّق شيء بأثر رجعي.');
  assert.equal(periodErrors('2026-10-10', '2026-10-09', '2026-10-05').effective_to, 'تاريخ الانتهاء بعد تاريخ البدء أو مساوٍ له.');
  assert.equal(periodErrors('', '', '2026-10-05').effective_from, 'أدخل تاريخ البدء.');
});

test('the business day is read in Cairo, not UTC', () => {
  assert.equal(businessToday(new Date('2026-10-04T22:30:00Z')), '2026-10-05');
  assert.equal(businessToday(new Date('2026-10-04T20:00:00Z')), '2026-10-04');
});

test('read-only descriptions say that a contract template is never binding', () => {
  const rows = describeParameters('contract_template', { title_ar: 'عقد', parties: ['factory', 'service_provider', 'imc'], duration_months: null, knowledge_transfer_min_trainees: 3, clauses: [] });
  assert.ok(rows.some((row) => row.value.includes('غير ملزمة')));
  assert.ok(rows.some((row) => row.value === 'غير محددة'));
});

test('the server\'s Arabic reason for a blocked operation becomes the headline', () => {
  const body = parseErrorBody({
    message: 'No approved tax policy applies on 2026-10-05, so it is not possible to issue this invoice.',
    code: 'policy_not_configured',
    decision_needed: 'OQ-16',
    reason_ar: 'لا توجد سياسة «الضرائب والرسوم» معتمدة وسارية.',
    missing_policies: ['tax', 7],
  });
  assert.deepEqual(body.missing_policies, ['tax']);

  const error = new ApiError({ status: 409, code: 'policy_not_configured', message: body.message ?? '', decisionNeeded: body.decision_needed, reasonAr: body.reason_ar, missingPolicies: body.missing_policies });
  const described = describeError(error);
  assert.equal(described.title, 'لا توجد سياسة «الضرائب والرسوم» معتمدة وسارية.');
  assert.equal(described.tone, 'info');
  assert.match(described.detail ?? '', /OQ-16/);

  const withoutReason = describeError(new ApiError({ status: 409, code: 'policy_not_configured', message: 'x', decisionNeeded: 'OQ-15' }));
  assert.match(withoutReason.title, /لم يعتمدها مركز تحديث الصناعة/);
});
