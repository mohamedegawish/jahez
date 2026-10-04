# API Conventions

Contract for clients and for engineers adding endpoints. Sections marked **(in force)** are implemented and tested. Sections marked **(PROPOSED)** apply when the first endpoint that needs them is built, and may be refined then.

## 1. Base URL and versioning (in force)

- All endpoints live under **`/api/v1`**. Example: `GET /api/v1/health`.
- Breaking changes (removed or renamed fields, changed types or semantics) require a new version (`/api/v2`). Adding optional fields or new endpoints within `v1` is allowed, so clients must ignore fields they don't recognise.
- `tests/Feature/Api/ApiVersioningTest.php` fails if any route under `api/` is outside `api/v1/`.
- Route names follow `api.v1.<resource>.<action>`.

## 1a. Authentication (in force since Phase 3)

- Obtain a token with `POST /api/v1/auth/login`, then send `Authorization: Bearer <token>` on every other request ([ADR-003](decisions/ADR-003-authentication-bearer-tokens.md)).
- Tokens expire after `SANCTUM_EXPIRATION` minutes (default 480). On a 401, log in again. There are no refresh tokens.
- Cookies and sessions are not used, so CSRF tokens are not needed.
- The full endpoint list is in [api-endpoints.md](api-endpoints.md). Who may do what is in [roles-permissions.md](roles-permissions.md).

## 2. Requests

- Send `Accept: application/json`. API errors are JSON even without it.
- Send request bodies as `Content-Type: application/json`.
- **(PROPOSED)** URL paths use plural kebab-case nouns (`/service-providers/{id}`). JSON field names use `snake_case`.
- **(in force since Phase 9)** Record IDs in paths are whole numbers. Any other value, such as `5abc`, gets 404, because MySQL alone would match it to record 5. Every API route parameter must declare its format (`->where(...)`); `tests/Feature/Api/ApiVersioningTest.php` enforces this.
- **(PROPOSED)** Timestamps are ISO 8601 in UTC (`2026-10-02T17:23:19Z`). Dates without a time are `YYYY-MM-DD` (`assessed_on`).
- **(in force since Phase 6)** Money is an object with a decimal **string** amount and a currency code: `"price": {"amount": "250000.00", "currency": "EGP"}`. Amounts have at most two decimal places and are stored as `DECIMAL(14,2)`, never as floats. Send the amount as a string to avoid binary rounding in clients. Only `EGP` is accepted (owner decision 2026-10-03). Offer prices are informational: no invoice or payment exists (Phase 7 BLOCKED).

## 3. Successful responses

- **(in force)** A body sits under a top-level `data` key:
  ```json
  { "data": { "status": "ok", "checks": { "database": "ok" } } }
  ```
- **(PROPOSED)** Resources are serialised through Laravel API Resources with an explicit field list. Models are never returned directly.
- **(in force since Phase 3)** Lists are paginated with Laravel's `links` and `meta` keys. `page` ≥ 1; `per_page` from 1 to **100**, default **15** (422 outside the range). Lists are ordered by `id`. **(Since Phase 9)** The `links` keep the request's query parameters (`per_page`, filters).
- **(in force since Phase 8)** The audit log uses **cursor pagination**: newest first, `per_page` default 50, and the next page through `meta.next_cursor` or `links.next`. A cursor the API did not issue is rejected with 422.
- **(in force since Phase 4)** New lists filter with `filter[field]=…` and sort with `sort=field` or `sort=-field` (descending). Both are allow-listed per endpoint: an unknown filter key, sort key or filter value gets 422. Values are bound as query parameters, never interpolated; a `search` term is matched literally (`%` and `_` are escaped). The audit log (Phase 8) keeps its v1 plain parameters (`event=…`, `actor_user_id=…`).
- **(in force since Phase 4)** Bounded reference data (the service catalog: 7 categories, 42 services) is returned whole, not paginated.
- **(in force since Phase 7 boundaries)** Starting a payment requires an `Idempotency-Key` header (8–100 characters from `A-Za-z0-9_-`). Repeating the request with the same key returns the same payment (200) instead of starting another one; a new attempt needs a new key.
- **(in force since Phase 6)** Status changes are explicit action endpoints (`POST …/accept`, `…/decline`, `…/withdraw`, `…/cancel`, `…/offers/{id}/accept`). Clients never send a `status` field; a status sent in a body is ignored. An action the current status does not allow gets **409** `conflict` with a message naming the current status.
- **(in force)** Created resources return **201** with the resource body. Successful actions with no body return **204**. Accepted asynchronous work returns **202**.

## 4. Errors (in force)

Every error under `/api` uses this envelope:

```json
{
  "message": "Human-readable summary.",
  "code": "machine_readable_code",
  "request_id": "c0a8012e-5b2f-4c3e-9a71-0f6d8f4b9e21"
}
```

- `errors` (field → messages) appears only on validation failures.
- `debug` (exception class, message, file, line, trace) appears **only** when `APP_DEBUG=true`. It must never appear in production.

| Status | `code` | `message` |
| --- | --- | --- |
| 400 | `bad_request` | From the exception |
| 401 | `unauthenticated` | `Unauthenticated.` |
| 403 | `forbidden` | The authorization message (for example a policy deny message) |
| 404 | `not_found` | Always `Resource not found.` (never names the model or route) |
| 405 | `method_not_allowed` | Always `Method not allowed.`; the `Allow` header lists valid methods |
| 409 | `conflict` | The application's message (for example a workflow transition the current status does not allow, or a stale offer version); or `The request conflicts with an existing record.` when a database unique key is violated (for example two simultaneous creations with the same email) |
| 409 | `policy_not_configured` | The operation needs a business rule that is not decided or not approved; the body adds `decision_needed` (the open question, for example `OQ-16`) and, when known, `reason_ar` (an Arabic explanation for the user) and `missing_policies` (the financial policy kinds missing, ADR-023). Not retryable until the policy is approved. |
| 413 | `payload_too_large` | From the exception |
| 415 | `unsupported_media_type` | From the exception |
| 419 | `csrf_token_mismatch` | From the exception (SPA cookie mode only) |
| 422 | `validation_failed` | First validation message (plus `errors`) |
| 429 | `too_many_requests` | Always `Too many requests.`; see `Retry-After` |
| 503 | `service_unavailable` | From the exception |
| other 4xx | `client_error` | From the exception |
| 500 / other 5xx | `server_error` | `Server error.` |

Validation example:

```json
{
  "message": "The name field is required.",
  "code": "validation_failed",
  "errors": { "name": ["The name field is required."] },
  "request_id": "…"
}
```

**Tenant isolation:** a record that exists but belongs to another organization is reported as **404**, not 403, so its existence is not revealed.

**Exceptions with their own response:** an `HttpResponseException`, or a `ValidationException` constructed with a custom response, is returned unchanged rather than wrapped in the envelope. Use this only when an endpoint deliberately needs a different shape, and document that shape.

## 5. Headers (in force)

| Header | Direction | Meaning |
| --- | --- | --- |
| `X-Request-Id` | request (optional) and response | Correlation ID. A client value matching `[A-Za-z0-9._-]{8,128}` is echoed; otherwise a UUID is generated. It also appears in error bodies as `request_id` and in server logs. Use it for correlation only; never trust it for security. |
| `X-RateLimit-Limit`, `X-RateLimit-Remaining` | response | Rate-limit state on throttled routes |
| `Retry-After` | response (429) | Seconds until requests are accepted again |
| `Allow` | response (405) | Methods the route accepts |
| `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` | response (all) | Baseline browser protections (since Phase 9) |
| `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Cache-Control: no-store, private` | response (`/api/*`) | API responses are data, never rendered pages, and are never cached |
| `Strict-Transport-Security: max-age=31536000; includeSubDomains` | response (HTTPS only) | Sent when the request is HTTPS, as seen through `TRUSTED_PROXIES` |

## 6. Rate limiting (in force)

- The default limiter (`api`) allows `API_RATE_LIMIT_PER_MINUTE` requests per minute (default **60**), keyed by the authenticated user, or by client IP for guests. This number is a **provisional technical default**, not a measured capacity.
- The client IP comes from `X-Forwarded-For` **only** when the caller is listed in `TRUSTED_PROXIES`. Behind a load balancer, set `TRUSTED_PROXIES` to its addresses; otherwise every guest shares the proxy's IP and its limit. When it is empty (the default), forwarded headers are ignored, so clients cannot spoof them to dodge limits.
- `GET /api/v1/health` is exempt, so monitoring probes cannot exhaust it.
- **(in force since Phase 3)** Login: 5 failures per minute per email and IP, and 20 per 15 minutes per account. Forgot and reset password: 5 per minute per IP ([ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md)). An email counts as the same account only when it equals the stored address apart from letter case.
- **(in force since Phase 6)** Negotiation messages: 30 per minute per user. Offer versions: 10 per minute per user. Both are technical defaults, not business rules, and apply on top of the default limiter.

## 7. CORS (in force)

- Cross-origin browser access is **denied by default**. Allowed origins are listed exactly in `CORS_ALLOWED_ORIGINS` (comma-separated). Wildcards are not used. CORS applies to `api/*` only; there are no other browser-facing routes ([ADR-013](decisions/ADR-013-api-only.md)).
- `X-Request-Id`, `Retry-After` and the rate-limit headers are exposed to browser JavaScript.
- Credentialed CORS (`supports_credentials`) stays off until the authentication mode is decided ([OQ-22](open-questions.md#oq-22)).

## 8. Health endpoints (in force)

| Endpoint | Type | Response |
| --- | --- | --- |
| `GET /up` | Liveness (framework); the only route outside `/api` | 200 if the application boots |
| `GET /api/v1/health` | Readiness | 200 `{"data":{"status":"ok","checks":{"database":"ok"}}}`, or 503 with `"status":"unavailable"` and `"database":"failed"`. Failure details are logged, never returned. |

## 9. Language

API messages are currently in English. Arabic and bilingual support is pending [OQ-23](open-questions.md#oq-23).
