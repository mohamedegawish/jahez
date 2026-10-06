// Run with `npm test`. Eligibility, plans and permissions are decided by the API (jahez_api ADR-025):
// the web client keeps nothing of them in browser storage and holds no hard-coded roadmap.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const SRC = join(import.meta.dirname, '..', 'src');

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? sourceFiles(path) : /\.(ts|tsx)$/.test(name) ? [path] : [];
  });
}

const files = sourceFiles(SRC).map((path) => ({ path: relative(SRC, path).split(sep).join('/'), text: readFileSync(path, 'utf8') }));

test('only the token store touches browser storage', () => {
  // Code only: comments that mention storage do not count.
  const code = (text: string) => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\s\/\/.*$/gm, '');
  const users = files.filter((file) => /\b(localStorage|sessionStorage|indexedDB)\b/.test(code(file.text))).map((file) => file.path);
  assert.deepEqual(users, ['auth/tokenStore.ts']);
});

test('roadmap and eligibility screens read their data from the API, never from a fixture', () => {
  const screens = files.filter((file) => /^(pages\/factory\/FactoryRoadmap|pages\/admin\/TransformationPlan|components\/roadmap|components\/roadmap-admin|components\/admin\/ReadinessLevelServices)/.test(file.path));
  assert.ok(screens.length >= 4, `expected the roadmap screens, found ${screens.map((file) => file.path).join(', ')}`);
  for (const file of screens) {
    assert.doesNotMatch(file.text, /from ['"].*(mock|fixture|demo)/i, file.path);
    assert.doesNotMatch(file.text, /\bMOCK_|mockData|sampleStages/, file.path);
  }
  const fetchers = screens.filter((file) => /\bapi\.(transformationPlans|readinessLevels|serviceEligibility)\./.test(file.text));
  assert.ok(fetchers.length >= 3, 'the roadmap screens call the API');
});
