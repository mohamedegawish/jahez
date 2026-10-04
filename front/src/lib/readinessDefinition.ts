import type { ReadinessCategoryCode, ReadinessDefinitionPayload, ReadinessQuestionnaireVersion } from '../api/types';

/**
 * The structure every questionnaire version keeps (jahez_api ADR-018 addendum 2, owner decision): the
 * source document's five pillars, two questions per pillar and four choices per question worth 1, 2, 3
 * and 4 points, so every version scores 10–40. The API applies the same checks when a draft is saved
 * and again when it is published; these give the administrator the answer before sending.
 */
export const QUESTIONNAIRE_SHAPE = {
  pillars: 5,
  questionsPerPillar: 2,
  choicesPerQuestion: 4,
  points: [1, 2, 3, 4],
  maxRecommendations: 30,
} as const;

export type DefinitionPayload = ReadinessDefinitionPayload;
export type DefinitionCategory = DefinitionPayload['categories'][number];

/** The editable definition of a version, as `PUT /readiness-questionnaires/{id}` takes it, with its roadmap lines. */
export function toDefinitionPayload(version: ReadinessQuestionnaireVersion): DefinitionPayload {
  const definition = version.definition;
  if (!definition) {
    return { title_ar: version.title_ar, title_en: version.title_en, pillars: [], categories: [] };
  }
  return {
    title_ar: version.title_ar,
    title_en: version.title_en,
    pillars: definition.pillars.map((pillar) => ({
      code: pillar.code,
      name_ar: pillar.name_ar,
      name_en: pillar.name_en,
      questions: pillar.questions.map((question) => ({
        code: question.code,
        text_ar: question.text_ar,
        choices: question.choices.map((choice) => ({ code: choice.code, label_ar: choice.label_ar, text_ar: choice.text_ar, points: choice.points })),
      })),
    })),
    categories: definition.categories.map((category) => ({
      code: category.code,
      name_ar: category.name_ar,
      name_en: category.name_en,
      description_ar: category.description_ar ?? '',
      min_score: category.min_score,
      max_score: category.max_score,
      focus_ar: category.focus_ar ?? '',
      steps_ar: category.steps_ar ?? '',
      recommendations: (category.roadmap?.recommendations ?? []).map((line) => ({
        text_ar: line.text_ar,
        services: line.services.map((service) => service.code),
      })),
    })),
  };
}

/** The lowest and highest total a complete set of answers can reach. */
export function scoreRange(definition: DefinitionPayload): { min: number; max: number } {
  let min = 0;
  let max = 0;
  for (const pillar of definition.pillars) {
    for (const question of pillar.questions) {
      const points = question.choices.map((choice) => Number(choice.points) || 0);
      if (points.length > 0) {
        min += Math.min(...points);
        max += Math.max(...points);
      }
    }
  }
  return { min, max };
}

/** Whether the category ranges cover every possible total exactly once, as the API requires. */
export function rangesCoverScores(definition: DefinitionPayload): boolean {
  const { min, max } = scoreRange(definition);
  const sorted = [...definition.categories].sort((a, b) => a.min_score - b.min_score);
  let expected = min;
  for (const category of sorted) {
    if (category.min_score !== expected || category.max_score < category.min_score) return false;
    expected = category.max_score + 1;
  }
  return sorted.length > 0 && expected - 1 === max;
}

/** The category whose range contains the total, if exactly one does. */
export function categoryForScore(definition: DefinitionPayload, total: number): DefinitionCategory | null {
  const matches = definition.categories.filter((category) => category.min_score <= total && total <= category.max_score);
  return matches.length === 1 ? matches[0] : null;
}

const sameSet = (values: number[], expected: readonly number[]) =>
  values.length === expected.length && [...values].sort((a, b) => a - b).every((value, index) => value === expected[index]);

/**
 * Why the definition cannot be published, in Arabic, or an empty list. The server's check is the one
 * that counts; this one mirrors it so the administrator sees the problem before saving.
 */
export function definitionProblems(definition: DefinitionPayload): string[] {
  const problems: string[] = [];
  if (!definition.title_ar.trim()) problems.push('عنوان الإصدار بالعربية مطلوب.');
  if (definition.pillars.length !== QUESTIONNAIRE_SHAPE.pillars) {
    problems.push(`يجب أن يضم الإصدار ${QUESTIONNAIRE_SHAPE.pillars} محاور.`);
  }

  let number = 0;
  for (const pillar of definition.pillars) {
    if (!pillar.name_ar.trim()) problems.push(`اسم المحور «${pillar.code}» بالعربية مطلوب.`);
    if (pillar.questions.length !== QUESTIONNAIRE_SHAPE.questionsPerPillar) {
      problems.push(`محور «${pillar.name_ar || pillar.code}» يجب أن يضم سؤالين.`);
    }
    for (const question of pillar.questions) {
      number++;
      if (!question.text_ar.trim()) problems.push(`نص السؤال ${number} مطلوب.`);
      if (question.choices.length !== QUESTIONNAIRE_SHAPE.choicesPerQuestion) {
        problems.push(`السؤال ${number} يجب أن يضم أربعة اختيارات.`);
        continue;
      }
      if (!sameSet(question.choices.map((choice) => Number(choice.points)), QUESTIONNAIRE_SHAPE.points)) {
        problems.push(`اختيارات السؤال ${number} يجب أن تساوي 1 و2 و3 و4 نقاط، كل قيمة مرة واحدة.`);
      }
      const labels = question.choices.map((choice) => choice.label_ar.trim());
      if (labels.includes('') || new Set(labels).size !== labels.length) {
        problems.push(`رموز اختيارات السؤال ${number} يجب أن تكون مملوءة ومختلفة.`);
      }
      if (question.choices.some((choice) => !choice.text_ar.trim())) problems.push(`نص أحد اختيارات السؤال ${number} فارغ.`);
    }
  }

  const range = scoreRange(definition);
  if (!rangesCoverScores(definition)) {
    problems.push(`حدود المستويات يجب أن تغطي كل الدرجات من ${range.min} إلى ${range.max} مرة واحدة دون فجوات أو تداخل.`);
  }
  for (const category of definition.categories) {
    if (!category.name_ar.trim()) problems.push(`اسم المستوى «${category.code}» بالعربية مطلوب.`);
    if (category.recommendations !== undefined) {
      const lines = category.recommendations.filter((line) => line.text_ar.trim() !== '');
      if (lines.length === 0) problems.push(`المستوى «${category.name_ar}» يحتاج توصية واحدة على الأقل.`);
      if (lines.length !== category.recommendations.length) problems.push(`في توصيات المستوى «${category.name_ar}» سطر فارغ.`);
      if (category.recommendations.length > QUESTIONNAIRE_SHAPE.maxRecommendations) {
        problems.push(`المستوى «${category.name_ar}» يقبل ${QUESTIONNAIRE_SHAPE.maxRecommendations} توصية على الأكثر.`);
      }
    }
  }
  return problems;
}

export type DefinitionSection = 'title' | 'pillars' | 'questions' | 'choices' | 'levels' | 'roadmap';

export interface DefinitionChange {
  section: DefinitionSection;
  item: string;
  before: string;
  after: string;
}

export const SECTION_LABELS: Record<DefinitionSection, string> = {
  title: 'العنوان',
  pillars: 'محاور التقييم',
  questions: 'الأسئلة',
  choices: 'الاختيارات',
  levels: 'مستويات الجاهزية',
  roadmap: 'التوصيات وخارطة الطريق',
};

const LEVEL_FIELDS: { key: keyof DefinitionCategory; label: string }[] = [
  { key: 'name_ar', label: 'الاسم' },
  { key: 'name_en', label: 'الاسم بالإنجليزية' },
  { key: 'description_ar', label: 'الوصف' },
  { key: 'min_score', label: 'أدنى درجة' },
  { key: 'max_score', label: 'أعلى درجة' },
  { key: 'focus_ar', label: 'التركيز' },
  { key: 'steps_ar', label: 'الخطوات' },
];

/**
 * What a draft changes compared with the published version it was copied from, matched by code so a
 * reordering reads as a move and a rewording as a change. Shown before publishing.
 */
export function diffDefinitions(before: DefinitionPayload, after: DefinitionPayload): DefinitionChange[] {
  const changes: DefinitionChange[] = [];
  const push = (section: DefinitionSection, item: string, from: unknown, to: unknown) => {
    const a = String(from ?? '');
    const b = String(to ?? '');
    if (a !== b) changes.push({ section, item, before: a, after: b });
  };

  push('title', 'عنوان الإصدار', before.title_ar, after.title_ar);

  const beforePillars = new Map(before.pillars.map((pillar, index) => [pillar.code, { pillar, index }]));
  const beforeQuestions = new Map<string, { text: string; number: number; choices: DefinitionPayload['pillars'][number]['questions'][number]['choices'] }>();
  let number = 0;
  for (const pillar of before.pillars) {
    for (const question of pillar.questions) beforeQuestions.set(question.code, { text: question.text_ar, number: ++number, choices: question.choices });
  }

  number = 0;
  after.pillars.forEach((pillar, index) => {
    const previous = beforePillars.get(pillar.code);
    if (!previous) {
      changes.push({ section: 'pillars', item: pillar.name_ar, before: '', after: 'محور جديد' });
    } else {
      push('pillars', `اسم المحور «${previous.pillar.name_ar}»`, previous.pillar.name_ar, pillar.name_ar);
      push('pillars', `ترتيب المحور «${pillar.name_ar}»`, previous.index + 1, index + 1);
    }
    for (const question of pillar.questions) {
      number++;
      const old = beforeQuestions.get(question.code);
      if (!old) {
        changes.push({ section: 'questions', item: `السؤال ${number}`, before: '', after: question.text_ar });
        continue;
      }
      push('questions', `نص السؤال ${number}`, old.text, question.text_ar);
      push('questions', `رقم السؤال «${question.code}»`, old.number, number);
      const oldChoices = new Map(old.choices.map((choice, position) => [choice.code, { choice, position }]));
      question.choices.forEach((choice, position) => {
        const was = oldChoices.get(choice.code);
        if (!was) {
          changes.push({ section: 'choices', item: `السؤال ${number}`, before: '', after: `${choice.label_ar}) ${choice.text_ar}` });
          return;
        }
        push('choices', `رمز اختيار في السؤال ${number}`, was.choice.label_ar, choice.label_ar);
        push('choices', `نص الاختيار ${choice.label_ar} في السؤال ${number}`, was.choice.text_ar, choice.text_ar);
        push('choices', `نقاط الاختيار ${choice.label_ar} في السؤال ${number}`, was.choice.points, choice.points);
        push('choices', `موضع الاختيار ${choice.label_ar} في السؤال ${number}`, was.position + 1, position + 1);
      });
    }
  });

  const beforeCategories = new Map(before.categories.map((category) => [category.code, category]));
  for (const category of after.categories) {
    const old = beforeCategories.get(category.code);
    if (!old) continue;
    for (const field of LEVEL_FIELDS) {
      push('levels', `${field.label}: ${old.name_ar}`, old[field.key] as string | number, category[field.key] as string | number);
    }
    if (category.recommendations !== undefined) {
      const oldLines = (old.recommendations ?? []).map(describeLine);
      const newLines = category.recommendations.map(describeLine);
      newLines.filter((line) => !oldLines.includes(line)).forEach((line) => changes.push({ section: 'roadmap', item: category.name_ar, before: '', after: line }));
      oldLines.filter((line) => !newLines.includes(line)).forEach((line) => changes.push({ section: 'roadmap', item: category.name_ar, before: line, after: '' }));
      if (oldLines.length === newLines.length && oldLines.every((line) => newLines.includes(line)) && oldLines.join('\n') !== newLines.join('\n')) {
        changes.push({ section: 'roadmap', item: category.name_ar, before: 'الترتيب السابق', after: 'ترتيب جديد للتوصيات' });
      }
    }
  }
  return changes;
}

function describeLine(line: { text_ar: string; services: string[] }): string {
  return line.services.length > 0 ? `${line.text_ar} [${[...line.services].sort().join('، ')}]` : line.text_ar;
}

/** Points chosen in a preview → the total and the category the draft's ranges give it. */
export function previewResult(definition: DefinitionPayload, points: number[]): { total: number; category: ReadinessCategoryCode | null } {
  const total = points.reduce((sum, value) => sum + value, 0);
  return { total, category: categoryForScore(definition, total)?.code ?? null };
}
