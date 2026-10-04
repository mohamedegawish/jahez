// Staged read load: virtual users repeat a mix of the read endpoints. The mix and the
// stages are provisional technical choices, not a measured usage profile (OQ-35), and
// the script sets no response-time thresholds. Run it only against a test system whose
// API_RATE_LIMIT_PER_MINUTE is raised for the test; otherwise the 60/min per-user limit
// answers most requests with 429 (counted separately below).
//   k6 run tests/performance/read-load.js -e BASE_URL=... -e STAGES=30s:10,1m:10,30s:0

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { API, assertSafeTarget, jsonHeaders, logInTestAccounts, logOut } from './lib/config.js';

const throttled = new Counter('throttled_responses');

/**
 * Parses "30s:10,1m:50,30s:0" into k6 stages (duration:target VUs).
 */
function stagesFrom(text) {
  return text.split(',').map((stage) => {
    const [duration, target] = stage.split(':');

    return { duration, target: Number(target) };
  });
}

export const options = {
  scenarios: {
    reads: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: stagesFrom(__ENV.STAGES || '10s:5,20s:5,10s:0'),
      gracefulRampDown: '5s',
    },
  },
  summaryTrendStats: ['min', 'med', 'avg', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    // Correctness only: every response must be the expected one. No latency targets (OQ-35).
    checks: ['rate==1'],
  },
};

/**
 * The provisional mix, as cumulative weights: [upper bound, request].
 */
const MIX = [
  [0.5, (data) => ['me', http.get(`${API}/me`, jsonHeaders(data.memberToken)), 200]],
  [0.7, (data) => ['own factory', http.get(`${API}/factories/${data.ownFactoryId}`, jsonHeaders(data.memberToken)), 200]],
  [0.85, (data) => ['factory list', http.get(`${API}/factories?per_page=15`, jsonHeaders(data.adminToken)), 200]],
  [0.95, (data) => ['user list', http.get(`${API}/users?per_page=15`, jsonHeaders(data.adminToken)), 200]],
  [1.0, (data) => ['audit log', http.get(`${API}/audit-logs?per_page=50`, jsonHeaders(data.adminToken)), 200]],
];

export function setup() {
  assertSafeTarget();

  return logInTestAccounts();
}

export default function (data) {
  const roll = Math.random();
  const [, request] = MIX.find(([upperBound]) => roll < upperBound);
  const [name, response, expectedStatus] = request(data);

  if (response.status === 429) {
    throttled.add(1);
  }

  check(response, { [`${name} ${expectedStatus}`]: (r) => r.status === expectedStatus });
  sleep(Number(__ENV.THINK_TIME_SECONDS || 0.5));
}

export function teardown(data) {
  logOut(data.adminToken);
  logOut(data.memberToken);
}
