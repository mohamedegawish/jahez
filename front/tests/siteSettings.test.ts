// Run with `npm test` (node --test; Node strips the TypeScript types). Covers the
// landing-content helpers: language resolution with built-in fallback, the nested
// PATCH payload shape the API validates, the settings-page labels, and the logo URL.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildSiteSettingsPayload,
  labelFor,
  logoSrc,
  resolveSetting,
} from '../src/site/siteSettings.ts';
import type { SiteSettingsMap } from '../src/api/types.ts';

const map: SiteSettingsMap = {
  'hero.title_ar': { type: 'text', ar: 'Saved Arabic', en: 'Saved English', image_url: null },
  'hero.kicker': { type: 'text', ar: null, en: null, image_url: null },
  'hero.cta_login': { type: 'text', ar: '', en: null, image_url: null },
  'brand.logo': { type: 'image', ar: null, en: null, image_url: '/public/site-settings/logo?v=123' },
};

test('resolveSetting prefers the saved value in the current language', () => {
  assert.equal(resolveSetting(map, 'hero.title_ar', 'ar', 'Fallback'), 'Saved Arabic');
  assert.equal(resolveSetting(map, 'hero.title_ar', 'en', 'Fallback'), 'Saved English');
});

test('resolveSetting falls back when nothing is saved', () => {
  assert.equal(resolveSetting(map, 'hero.kicker', 'ar', 'Fallback'), 'Fallback');
  assert.equal(resolveSetting(map, 'hero.kicker', 'en', 'Fallback'), 'Fallback');
  assert.equal(resolveSetting(map, 'missing.key', 'ar', 'Fallback'), 'Fallback');
  assert.equal(resolveSetting(undefined, 'hero.title_ar', 'ar', 'Fallback'), 'Fallback');
});

test('resolveSetting treats an empty saved value as missing', () => {
  assert.equal(resolveSetting(map, 'hero.cta_login', 'ar', 'Fallback'), 'Fallback');
});

test('buildSiteSettingsPayload nests by section and nulls empty strings', () => {
  assert.deepEqual(
    buildSiteSettingsPayload({
      'hero.title_ar': { ar: 'A', en: '' },
      'about.p1': { ar: '', en: '' },
      'brand.logo': { ar: 'x', en: '' },
      nosuchkey: { ar: 'x', en: '' },
    }),
    {
      settings: {
        hero: { title_ar: { ar: 'A', en: null } },
        about: { p1: { ar: null, en: null } },
        brand: { logo: { ar: 'x', en: null } },
      },
    },
  );
});

test('labelFor labels every key shape without throwing', () => {
  const keys = [
    'brand.logo_alt',
    'header.nav_home',
    'footer.line',
    'hero.kicker',
    'hero.subtitle_authority',
    'hero.portal_factory_title',
    'join.card_login_desc',
    'value.title',
    'categories.cat1_title',
    'categories.cat7_desc',
    'categories.browse_cta',
    'categories.assess_hint',
    'about.p1',
    'about.badge2',
    'about.fact3_value',
    'about.fact3_label',
    'services.svc4_title',
    'steps.step2_cta',
    'steps.intro',
  ];
  for (const key of keys) {
    const label = labelFor(key);
    assert.ok(label.length > 0, `empty label for ${key}`);
    assert.ok(!label.includes('.'), `raw key leaked into label for ${key}`);
  }
  // Grouped labels carry the item number.
  assert.ok(labelFor('categories.cat3_title').includes('3'));
  assert.ok(labelFor('steps.step2_cta').includes('2'));
  // Unknown keys degrade to the raw field part, never crash.
  assert.equal(labelFor('mystery.field'), 'field');
});

test('logoSrc is null until a logo is uploaded', () => {
  assert.equal(logoSrc(undefined, 'https://api.test'), null);
  assert.equal(logoSrc({}, 'https://api.test'), null);
  const src = logoSrc(map, 'https://api.test');
  assert.equal(src, 'https://api.test/public/site-settings/logo?v=123');
});
