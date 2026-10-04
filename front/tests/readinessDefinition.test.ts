// Run with `npm test` (node --test; Node strips the TypeScript types). These cover the browser-side
// checks of the readiness questionnaire editor (jahez_api ADR-018 addendum 2). The API repeats every
// check when a draft is saved and when it is published.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  categoryForScore,
  definitionProblems,
  diffDefinitions,
  previewResult,
  rangesCoverScores,
  scoreRange,
} from '../src/lib/readinessDefinition.ts';
import type { DefinitionPayload } from '../src/lib/readinessDefinition.ts';

/** A definition with the source document's shape: 5 pillars × 2 questions × 4 choices (1–4 points). */
function sourceShaped(): DefinitionPayload {
  const labels = ['أ', 'ب', 'ج', 'د'];
  let number = 0;
  return {
    title_ar: 'إطار تقييم مستوى الجاهزية الرقمية',
    title_en: null,
    pillars: ['strategy', 'processes', 'technology', 'culture', 'customers'].map((code) => ({
      code,
      name_ar: `محور ${code}`,
      name_en: null,
      questions: [1, 2].map(() => {
        number++;
        return {
          code: `q${number}`,
          text_ar: `السؤال ${number}`,
          choices: ['a', 'b', 'c', 'd'].map((choice, index) => ({ code: choice, label_ar: labels[index], text_ar: `اختيار ${choice}`, points: index + 1 })),
        };
      }),
    })),
    categories: [
      { code: 'b4_automation', name_ar: 'ما قبل الأتمتة', name_en: 'B4 Automation', description_ar: 'وصف', min_score: 10, max_score: 17, focus_ar: 'تركيز', steps_ar: 'خطوات', recommendations: [{ text_ar: 'توصية', services: ['erp_business_applications.01'] }] },
      { code: 'basic', name_ar: 'مبتدئ', name_en: 'Basic', description_ar: 'وصف', min_score: 18, max_score: 25, focus_ar: 'تركيز', steps_ar: 'خطوات', recommendations: [{ text_ar: 'توصية', services: [] }] },
      { code: 'advanced', name_ar: 'متقدم', name_en: 'Advanced', description_ar: 'وصف', min_score: 26, max_score: 33, focus_ar: 'تركيز', steps_ar: 'خطوات', recommendations: [{ text_ar: 'توصية', services: [] }] },
      { code: 'smart', name_ar: 'ذكي ومبتكر', name_en: 'Smart', description_ar: 'وصف', min_score: 34, max_score: 40, focus_ar: 'تركيز', steps_ar: 'خطوات', recommendations: [{ text_ar: 'توصية', services: [] }] },
    ],
  };
}

test('the source shape scores 10 to 40 and has no problem', () => {
  const definition = sourceShaped();
  assert.deepEqual(scoreRange(definition), { min: 10, max: 40 });
  assert.equal(rangesCoverScores(definition), true);
  assert.deepEqual(definitionProblems(definition), []);
});

test('every band boundary classifies as the source document states', () => {
  const definition = sourceShaped();
  const expected: [number, string][] = [
    [10, 'b4_automation'], [17, 'b4_automation'], [18, 'basic'], [25, 'basic'],
    [26, 'advanced'], [33, 'advanced'], [34, 'smart'], [40, 'smart'],
  ];
  for (const [total, code] of expected) assert.equal(categoryForScore(definition, total)?.code, code, `total ${total}`);
  assert.equal(categoryForScore(definition, 9), null);
  assert.equal(categoryForScore(definition, 41), null);
  assert.deepEqual(previewResult(definition, Array(10).fill(2)), { total: 20, category: 'basic' });
});

test('a gap, an overlap or a short top band is refused', () => {
  for (const tamper of [
    (d: DefinitionPayload) => { d.categories[1].min_score = 19; },
    (d: DefinitionPayload) => { d.categories[1].min_score = 17; },
    (d: DefinitionPayload) => { d.categories[3].max_score = 39; },
  ]) {
    const definition = sourceShaped();
    tamper(definition);
    assert.equal(rangesCoverScores(definition), false);
    assert.ok(definitionProblems(definition).some((problem) => problem.includes('حدود المستويات')));
  }
});

test('the shape is locked: points 1–4 once each, four labelled choices, two questions per pillar', () => {
  const cases: [string, (d: DefinitionPayload) => void][] = [
    ['اختيارات السؤال 1 يجب أن تساوي', (d) => { d.pillars[0].questions[0].choices[1].points = 1; }],
    ['رموز اختيارات السؤال 1', (d) => { d.pillars[0].questions[0].choices[1].label_ar = 'أ'; }],
    ['السؤال 1 يجب أن يضم أربعة اختيارات', (d) => { d.pillars[0].questions[0].choices.pop(); }],
    ['يجب أن يضم سؤالين', (d) => { d.pillars[0].questions.pop(); }],
    ['يجب أن يضم الإصدار 5 محاور', (d) => { d.pillars.pop(); }],
    ['يحتاج توصية واحدة على الأقل', (d) => { d.categories[0].recommendations = []; }],
  ];
  for (const [message, tamper] of cases) {
    const definition = sourceShaped();
    tamper(definition);
    assert.ok(definitionProblems(definition).some((problem) => problem.includes(message)), message);
  }
});

test('reordered choices with new points per choice keep the scale valid', () => {
  const definition = sourceShaped();
  definition.pillars[0].questions[0].choices.reverse();
  assert.deepEqual(definitionProblems(definition), []);
});

test('the change review lists rewordings, moves, bounds and roadmap lines, and nothing for an unchanged copy', () => {
  const before = sourceShaped();
  assert.deepEqual(diffDefinitions(before, sourceShaped()), []);

  const after = sourceShaped();
  after.pillars[0].questions[0].text_ar = 'صياغة جديدة';
  after.pillars[0].questions.reverse();
  after.categories[0].max_score = 16;
  after.categories[1].min_score = 17;
  after.categories[0].recommendations = [{ text_ar: 'توصية جديدة', services: [] }];
  const changes = diffDefinitions(before, after);

  assert.ok(changes.some((c) => c.section === 'questions' && c.after === 'صياغة جديدة'));
  assert.ok(changes.some((c) => c.section === 'questions' && c.item.startsWith('رقم السؤال')));
  assert.ok(changes.some((c) => c.section === 'levels' && c.before === '17' && c.after === '16'));
  assert.ok(changes.some((c) => c.section === 'roadmap' && c.after === 'توصية جديدة'));
  assert.ok(changes.some((c) => c.section === 'roadmap' && c.before.startsWith('توصية [')));
});
