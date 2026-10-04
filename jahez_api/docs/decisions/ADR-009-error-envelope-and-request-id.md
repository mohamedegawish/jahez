# ADR-009: One JSON error envelope and a request ID on every response

- **Status:** Accepted (2026-10-02, Phase 1)
- **Decided by:** Engineering (PROPOSED in Phase 0, implemented in Phase 1). Open to owner review.

## Context
In production, Laravel's default JSON for an HTTP exception returns the exception's own message. For a missing route-bound model this is `No query results for model [App\Models\…] 5`, which reveals internal class names. API errors also had no stable machine-readable code and no way to correlate a client report with server logs.

## Decision
- `App\Http\Responses\ApiExceptionRenderer` is registered in `bootstrap/app.php` through `$exceptions->render(...)`. It renders **every** exception under `api` and `api/*` as `{message, code, [errors], [debug], request_id}`, whatever the `Accept` header. Web routes keep Laravel's default HTML pages.
- Exceptions that already carry a response (`HttpResponseException`, or a `ValidationException` with a custom response) are passed through unchanged. This was added after the Phase 1 code review.
- 404, 405 and 429 always use fixed messages. Other 4xx statuses keep the application's own message (`abort(409, '…')`, policy deny messages). 5xx returns `Server error.` with no detail unless `APP_DEBUG=true`.
- `App\Http\Middleware\AssignRequestId` is **prepended** to the global middleware stack, so it wraps everything, including maintenance-mode responses. It accepts a client `X-Request-Id` that matches `[A-Za-z0-9._-]{8,128}` and otherwise generates a UUID. It stores the ID in `Context` (so it appears in log entries and queued jobs) and returns it in the `X-Request-Id` response header.

## Consequences
- Controllers never build error JSON by hand. They throw exceptions or call `abort()`.
- A client can quote `request_id` from any error, and operators can find the matching log entries.
- A client can choose its own request ID, so request IDs are for correlation only and must never be trusted for security decisions.
- The contract is in [api-conventions.md](../api-conventions.md) and covered by `tests/Feature/Api/ErrorResponseTest.php` and `RequestIdTest.php`.
