// Minimal sequential runner for THIS collection only: it supports the subset of Postman
// scripting the collection uses (pm.test, pm.expect chains, pm.environment, pm.response).
// Prefer Newman when it is available; see postman/README.md.
// Usage: node postman/run-collection.mjs <collection.json> <environment.json> <base_url> [delay_ms]
// delay_ms (optional) waits between requests, so a full run stays under the API's default
// 60 requests per minute per account (about 400 ms for the whole collection).
// An ES module (.mjs), so it runs the same with or without a package.json.
import fs from 'node:fs';
import path from 'node:path';
const [collectionFile, environmentFile, baseUrl, delayArgument] = process.argv.slice(2);
const delayMs = Number.parseInt(delayArgument ?? '0', 10) || 0;
const collection = JSON.parse(fs.readFileSync(collectionFile, 'utf8'));
const env = Object.fromEntries(JSON.parse(fs.readFileSync(environmentFile, 'utf8')).values.map((v) => [v.key, v.value]));
env.base_url = baseUrl;

const substitute = (text) =>
  String(text ?? '').replace(/\{\{([$a-z_]+)\}\}/gi, (_, name) => (name === '$timestamp' ? String(Math.floor(Date.now() / 1000)) : String(env[name] ?? '')));

function expect(actual, message) {
  const flags = { negate: false, any: false, all: false };
  const check = (passes, description) => {
    if (flags.negate ? passes : !passes) throw new Error(`${message ? message + ': ' : ''}${flags.negate ? 'NOT ' : ''}${description}`);
  };
  const api = {
    get to() { return api; }, get be() { return api; }, get have() { return api; }, get that() { return api; }, get is() { return api; },
    get all() { flags.all = true; return api; },
    get and() { flags.negate = false; return api; },
    get not() { flags.negate = !flags.negate; return api; },
    get any() { flags.any = true; return api; },
    get empty() {
      const isEmpty = actual == null || (typeof actual === 'string' || Array.isArray(actual) ? actual.length === 0 : Object.keys(actual).length === 0);
      check(isEmpty, `expected ${JSON.stringify(actual)} to be empty`);
      return api;
    },
    a(type) {
      const actualType = Array.isArray(actual) ? 'array' : actual === null ? 'null' : typeof actual;
      check(actualType === type, `expected type ${type}, got ${actualType}`);
      return api;
    },
    an(type) { return api.a(type); },
    eql(value) { check(JSON.stringify(actual) === JSON.stringify(value), `expected ${JSON.stringify(actual)} to eql ${JSON.stringify(value)}`); return api; },
    include(value) { check(actual != null && actual.includes(value), `expected ${JSON.stringify(actual)} to include ${JSON.stringify(value)}`); return api; },
    match(pattern) { check(pattern.test(actual), `expected ${actual} to match ${pattern}`); return api; },
    members(values) { check(Array.isArray(actual) && actual.length === values.length && values.every((v) => actual.includes(v)), `expected members ${values}`); return api; },
    lengthOf(n) { check(actual != null && actual.length === n, `expected length ${n}, got ${actual?.length}`); return api; },
    property(name) { check(actual != null && Object.prototype.hasOwnProperty.call(actual, name), `expected property ${name}`); return api; },
    above(n) { check(actual > n, `expected ${actual} to be above ${n}`); return api; },
    least(n) { check(actual >= n, `expected ${actual} to be at least ${n}`); return api; },
    oneOf(values) { check(values.includes(actual), `expected ${JSON.stringify(actual)} to be one of ${JSON.stringify(values)}`); return api; },
    keys(...keys) {
      const list = keys.flat();
      const present = list.filter((k) => actual != null && Object.prototype.hasOwnProperty.call(actual, k));
      const exact = flags.all ? actual != null && Object.keys(actual).length === list.length : true;
      check(flags.any ? present.length > 0 : present.length === list.length && exact, `expected ${flags.any ? 'any of' : flags.all ? 'exactly' : 'all of'} keys ${list}`);
      return api;
    },
  };
  return api;
}

(async () => {
  let passed = 0;
  let failed = 0;
  for (const folder of collection.item) {
    console.log(`\n${folder.name}`);
    for (const item of folder.item) {
      const { request } = item;
      const headers = Object.fromEntries(request.header.map((h) => [h.key, substitute(h.value)]));
      let body;
      if (request.body?.mode === 'formdata') {
        // Multipart: text fields and files (a file field's `src` is relative to the collection).
        body = new FormData();
        for (const field of request.body.formdata ?? []) {
          if (field.disabled) continue;
          if (field.type === 'file') {
            const file = path.resolve(path.dirname(collectionFile), String(field.src));
            body.append(field.key, new Blob([fs.readFileSync(file)], { type: field.contentType ?? 'application/octet-stream' }), path.basename(file));
          } else {
            body.append(field.key, substitute(field.value));
          }
        }
        delete headers['Content-Type'];
      } else if (request.body?.raw !== undefined) {
        body = substitute(request.body.raw);
      }
      const response = await fetch(substitute(request.url.raw), { method: request.method, headers, body });
      const text = await response.text();
      if (delayMs > 0) await new Promise((resolve) => setTimeout(resolve, delayMs));
      const pm = {
        // Postman keeps environment values as strings.
        environment: { set: (k, v) => { env[k] = v == null ? v : String(v); }, get: (k) => env[k] },
        expect,
        response: {
          code: response.status,
          json: () => JSON.parse(text),
          headers: { get: (name) => response.headers.get(name) },
          to: { have: { status: (code) => expect(response.status, 'status').to.eql(code) } },
        },
        test: (name, fn) => {
          try { fn(); passed++; console.log(`  ✓ ${item.name} — ${name}`); }
          catch (error) { failed++; console.log(`  ✗ ${item.name} — ${name}: ${error.message}`); }
        },
      };
      const scripts = [...(collection.event ?? []), ...(item.event ?? [])].map((e) => e.script.exec.join('\n'));
      for (const script of scripts) new Function('pm', script)(pm);
    }
  }
  console.log(`\n${passed} assertions passed, ${failed} failed`);
  process.exit(failed === 0 ? 0 : 1);
})();
