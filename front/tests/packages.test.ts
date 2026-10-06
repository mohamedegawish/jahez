// Run with `npm test`. Display rules of listing packages (jahez_api ADR-027): a package may state a
// monthly price, an annual price or both, and a number of users; prices stay decimal strings.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PERIOD_LABEL, packagePrice, usersLabel } from '../src/lib/packages.ts';

const annualOnly = { id: 1, name_ar: 'سنوية', monthly_price: null, annual_price: '12000.00', users_count: 25 };

test('a package gives the price of a period only when it states one', () => {
  assert.equal(packagePrice(annualOnly, 'annual'), '12000.00');
  assert.equal(packagePrice(annualOnly, 'monthly'), null);
});

test('the users label is left out when the package states no number', () => {
  assert.equal(usersLabel(null), null);
  assert.equal(usersLabel(undefined), null);
  assert.equal(usersLabel(1), 'مستخدم واحد');
  assert.equal(usersLabel(25), 'حتى 25 مستخدم');
});

test('both billing periods have a label', () => {
  assert.deepEqual(Object.keys(PERIOD_LABEL).sort(), ['annual', 'monthly']);
});
