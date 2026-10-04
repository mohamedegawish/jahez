// Smoke test: one virtual user walks every read path once per iteration and checks the
// responses. It proves the scripts and the target work; it measures nothing.
//   k6 run tests/performance/smoke.js -e BASE_URL=http://127.0.0.1:8765

import http from 'k6/http';
import { check, group } from 'k6';
import { API, assertSafeTarget, jsonHeaders, logInTestAccounts, logOut } from './lib/config.js';

export const options = {
  vus: 1,
  iterations: Number(__ENV.ITERATIONS || 5),
  thresholds: {
    // Correctness only. Response-time targets wait for OQ-35.
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
  },
};

export function setup() {
  assertSafeTarget();

  return logInTestAccounts();
}

export default function (data) {
  group('health', () => {
    const response = http.get(`${API}/health`, jsonHeaders());
    check(response, {
      'health 200': (r) => r.status === 200,
      'security headers present': (r) => r.headers['X-Content-Type-Options'] === 'nosniff',
    });
  });

  group('member reads', () => {
    check(http.get(`${API}/me`, jsonHeaders(data.memberToken)), { 'me 200': (r) => r.status === 200 });
    check(http.get(`${API}/factories/${data.ownFactoryId}`, jsonHeaders(data.memberToken)), { 'own factory 200': (r) => r.status === 200 });

    const otherFactory = http.get(`${API}/factories/${data.otherFactoryId}`, {
      ...jsonHeaders(data.memberToken),
      responseCallback: http.expectedStatuses(404),
    });
    check(otherFactory, { 'other factory 404': (r) => r.status === 404 });
  });

  group('administrator reads', () => {
    check(http.get(`${API}/factories?per_page=15`, jsonHeaders(data.adminToken)), { 'factory list 200': (r) => r.status === 200 });
    check(http.get(`${API}/users?per_page=15`, jsonHeaders(data.adminToken)), { 'user list 200': (r) => r.status === 200 });
    check(http.get(`${API}/audit-logs?per_page=50`, jsonHeaders(data.adminToken)), { 'audit log 200': (r) => r.status === 200 });
  });
}

export function teardown(data) {
  logOut(data.adminToken);
  logOut(data.memberToken);
}
