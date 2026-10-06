// Run with `npm test`. Display and editing rules of transformation plans (jahez_api ADR-025). States
// and eligibility come from the API; these check that every server state has a label and that the
// editor builds the payload and spots invalid dependencies before sending.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  ITEM_STATE,
  PLAN_STATUS,
  REQUEST_PROGRESS,
  STAGE_STATUS,
  draftFromVersion,
  draftProblems,
  emptyItem,
  emptyStage,
  findCycle,
  groupStageItems,
  progressLabel,
  toDraftPayload,
  waitingReason,
} from '../src/lib/roadmap.ts';
import type { DraftModel } from '../src/lib/roadmap.ts';
import type { PlanItem, PlanVersionView } from '../src/api/types.ts';

function item(itemId: number, code: string, overrides: Partial<PlanItem> = {}): PlanItem {
  return {
    id: itemId * 10,
    item_id: itemId,
    position: 1,
    service: { id: itemId, code, name_ar: `خدمة ${code}`, category: null },
    provider: null,
    instructions_ar: null,
    planned_start_date: null,
    planned_end_date: null,
    execution_status: 'not_started',
    state: 'not_started',
    started_at: null,
    completed_at: null,
    status_changed_at: null,
    depends_on: [],
    waiting_for: [],
    dependents: [],
    parallel_with: [],
    service_available: true,
    request: null,
    requests_count: 0,
    can_request: true,
    ...overrides,
  };
}

/** Stage 1: A and B; stage 2: C after A, D; stage 3: E after C and D. */
function model(): DraftModel {
  const stage1 = { ...emptyStage('التأسيس'), items: [emptyItem('a'), emptyItem('b')] };
  const stage2 = { ...emptyStage('التكامل'), items: [{ ...emptyItem('c'), depends_on: ['a'] }, emptyItem('d')] };
  const stage3 = { ...emptyStage('التوسع'), items: [{ ...emptyItem('e'), depends_on: ['c', 'd'] }] };
  return { title: 'خطة', summary_ar: ' ', change_note: '', stages: [stage1, stage2, stage3] };
}

test('every state the server sends has an Arabic label', () => {
  for (const state of ['waiting_prerequisites', 'not_started', 'awaiting_approval', 'ready', 'in_progress', 'on_hold', 'completed', 'cancelled'] as const) {
    assert.ok(ITEM_STATE[state].label.length > 0, state);
  }
  for (const status of ['draft', 'published', 'suspended', 'closed'] as const) assert.ok(PLAN_STATUS[status].label);
  for (const status of ['not_started', 'in_progress', 'completed', 'cancelled'] as const) assert.ok(STAGE_STATUS[status].label);
  for (const progress of ['open', 'negotiating', 'no_active_provider', 'awaiting_imc_review', 'agreed', 'imc_approved', 'imc_rejected', 'cancelled'] as const) {
    assert.ok(REQUEST_PROGRESS[progress].label, progress);
  }
});

test('progress labels count completed services out of those not cancelled, like the server', () => {
  assert.equal(progressLabel({ total: 5, completed: 2, in_progress: 1, on_hold: 0, cancelled: 1, percent: 50 }), '2 من 4 خدمات مكتملة');
  assert.equal(progressLabel({ total: 0, completed: 0, in_progress: 0, on_hold: 0, cancelled: 0, percent: null }), 'لا خدمات بعد');
  assert.equal(progressLabel({ total: 2, completed: 0, in_progress: 0, on_hold: 0, cancelled: 2, percent: null }), 'كل الخدمات ملغاة');
});

test('a stage shows items that can run together apart from those waiting for another item of the stage', () => {
  const a = item(1, 'a');
  const b = item(2, 'b', { depends_on: [{ item_id: 1, service_name_ar: 'خدمة a', execution_status: 'not_started' }] });
  const c = item(3, 'c', { depends_on: [{ item_id: 99, service_name_ar: 'من مرحلة سابقة', execution_status: 'completed' }] });

  const groups = groupStageItems([a, b, c]);
  assert.deepEqual(groups.parallel.map((entry) => entry.item_id), [1, 3]);
  assert.deepEqual(groups.after.map((entry) => entry.item_id), [2]);
});

test('the waiting reason names the services an item waits for, and nothing internal', () => {
  const waiting = item(3, 'c', {
    state: 'waiting_prerequisites',
    waiting_for: [{ item_id: 1, service_name_ar: 'نظام ERP', execution_status: 'in_progress' }],
    internal_notes: 'سري',
  });
  assert.equal(waitingReason(waiting), 'تبدأ بعد اكتمال: نظام ERP');
  assert.match(waitingReason(item(4, 'd', { service_available: false })) ?? '', /غير متاحة/);
  assert.equal(waitingReason(item(5, 'e', { state: 'ready' })), null);
});

test('the draft payload keeps the order, drops blank text and repeated dependencies', () => {
  const draft = model();
  draft.stages[1].items[0].depends_on = ['a', 'a'];
  draft.stages[0].items[0].service_provider_id = 7;

  const payload = toDraftPayload(draft, 3);
  assert.equal(payload.based_on_revision, 3);
  assert.equal(payload.summary_ar, null);
  assert.deepEqual(payload.stages.map((stage) => stage.name_ar), ['التأسيس', 'التكامل', 'التوسع']);
  assert.deepEqual(payload.stages[0].items.map((entry) => entry.service), ['a', 'b']);
  assert.deepEqual(payload.stages[1].items[0].depends_on, ['a']);
  assert.equal(payload.stages[0].items[0].service_provider_id, 7);
  assert.equal(payload.stages[0].objective_ar, null);
});

test('the editor spots cycles, self-dependencies, missing and duplicated services', () => {
  assert.equal(findCycle(model()), null);
  assert.deepEqual(draftProblems(model()), []);

  const cyclic = model();
  cyclic.stages[0].items[0].depends_on = ['e'];
  assert.ok(findCycle(cyclic));
  assert.ok(draftProblems(cyclic).some((problem) => problem.startsWith('التبعيات تكوّن حلقة')));

  const broken = model();
  broken.stages[0].items[1].depends_on = ['b', 'zz'];
  broken.stages[2].items.push(emptyItem('a'));
  broken.stages.push(emptyStage(''));
  const problems = draftProblems(broken);
  assert.ok(problems.some((problem) => problem.includes('على نفسها')));
  assert.ok(problems.some((problem) => problem.includes('ليست في الخطة')));
  assert.ok(problems.some((problem) => problem.includes('مكررة')));
  assert.ok(problems.some((problem) => problem.includes('بلا اسم')));
  assert.ok(problems.some((problem) => problem.includes('بلا خدمات')));
});

test('a version from the API becomes an editable draft with its dependencies by service code', () => {
  const version: PlanVersionView = {
    id: 1,
    version: 2,
    status: 'draft',
    title: 'خطة',
    summary_ar: null,
    change_note: 'تعديل',
    published_at: null,
    progress: { total: 2, completed: 0, in_progress: 0, on_hold: 0, cancelled: 0, percent: 0 },
    stages: [
      {
        id: 1,
        number: 1,
        name_ar: 'مرحلة',
        objective_ar: null,
        description_ar: null,
        factory_instructions_ar: null,
        internal_notes: 'ملاحظة',
        planned_start_date: '2026-11-01',
        planned_end_date: null,
        status: 'not_started',
        progress: { total: 2, completed: 0, in_progress: 0, on_hold: 0, cancelled: 0, percent: 0 },
        items: [
          item(1, 'a', { provider: { id: 5, name: 'مزود', has_logo: false } }),
          item(2, 'b', { depends_on: [{ item_id: 1, service_name_ar: 'خدمة a', execution_status: 'not_started' }] }),
        ],
      },
    ],
  };

  const draft = draftFromVersion(version);
  assert.equal(draft.change_note, 'تعديل');
  assert.equal(draft.stages[0].internal_notes, 'ملاحظة');
  assert.equal(draft.stages[0].planned_start_date, '2026-11-01');
  assert.equal(draft.stages[0].items[0].service_provider_id, 5);
  assert.deepEqual(draft.stages[0].items[1].depends_on, ['a']);
});
