// Shared settings for the k6 scripts in tests/performance (see docs/performance/plan.md).
// Everything comes from environment variables (k6 -e NAME=value). The defaults target a
// local server and the local demo accounts, whose password is public and works only
// against a database seeded with APP_ENV=local.

import http from 'k6/http';
import { check, fail } from 'k6';

export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
export const API = `${BASE_URL}/api/v1`;

const ADMIN = { email: __ENV.ADMIN_EMAIL || 'test@example.com', password: __ENV.ADMIN_PASSWORD || 'password' };
const MEMBER = { email: __ENV.MEMBER_EMAIL || 'factory-a@example.test', password: __ENV.MEMBER_PASSWORD || 'password' };

/**
 * Refuses any target other than this machine unless the caller confirms it is a test
 * system: load must never be sent to production (master prompt, Phase 10).
 */
export function assertSafeTarget() {
  const host = BASE_URL.replace(/^https?:\/\//, '').split(/[:/]/)[0];
  const isLocal = ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(host);

  if (!isLocal && __ENV.TARGET_IS_TEST_SYSTEM !== 'yes') {
    fail(`Refusing to run against ${BASE_URL}. Set TARGET_IS_TEST_SYSTEM=yes only for a staging or test system.`);
  }
}

export function jsonHeaders(token) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  return { headers };
}

function logIn(account, deviceName) {
  const response = http.post(`${API}/auth/login`, JSON.stringify({ ...account, device_name: deviceName }), jsonHeaders());

  const loggedIn = check(response, {
    'login returns a token': (r) => {
      try {
        return r.status === 200 && typeof r.json('data.access_token') === 'string';
      } catch (notJson) {
        return false;
      }
    },
  });

  if (!loggedIn) {
    fail(`Login failed for ${account.email} with status ${response.status}. Seed the database (php artisan db:seed) and check the credentials.`);
  }

  return response.json('data');
}

/**
 * Logs in once per account (in setup, so the login limiter is not exercised) and returns
 * the tokens and the IDs the scenarios need.
 */
export function logInTestAccounts() {
  const admin = logIn(ADMIN, 'k6-admin');
  const member = logIn(MEMBER, 'k6-member');
  const ownFactoryId = member.user.organization ? member.user.organization.id : null;

  if (ownFactoryId === null) {
    fail(`${MEMBER.email} must be a factory member.`);
  }

  const factories = http.get(`${API}/factories?per_page=100`, jsonHeaders(admin.access_token)).json('data');
  const otherFactory = factories.find((factory) => factory.id !== ownFactoryId);

  if (!otherFactory) {
    fail('Needs at least two factories (php artisan db:seed creates two in APP_ENV=local).');
  }

  return {
    adminToken: admin.access_token,
    memberToken: member.access_token,
    ownFactoryId,
    otherFactoryId: otherFactory.id,
  };
}

export function logOut(token) {
  http.post(`${API}/auth/logout`, null, jsonHeaders(token));
}
