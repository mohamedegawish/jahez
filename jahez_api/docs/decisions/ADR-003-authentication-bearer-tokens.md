# ADR-003: Bearer-token authentication with Sanctum

- **Status:** Accepted (2026-10-02, Phase 3), as decision **D2**
- **Decided by:** Project owner accepted the recommended default ("ok, complete"). Revisit when [OQ-22](../open-questions.md#oq-22) (which clients use the API) is answered.

## Context
The API clients are not yet known. A first-party web SPA, mobile apps and third-party integrations are all possible. Sanctum supports both SPA cookie sessions and personal access tokens.

## Decision
- **Personal access tokens only.** `config/sanctum.php` sets `guard => []`, which disables session and cookie authentication. Every client sends `Authorization: Bearer <token>`, so the API stays stateless and CSRF does not apply.
- `POST /api/v1/auth/login` issues a token named after the client's `device_name`. `POST /api/v1/auth/logout` revokes only the token used for that request.
- **Lifetime: 8 hours** (`SANCTUM_EXPIRATION=480`). It is enforced twice: by Sanctum's `expiration` and by the token's own `expires_at`. A blank, `null` or `0` value falls back to 480, so a misconfiguration cannot issue already-expired tokens (this was found in code review and is tested).
- **Active-account check on every request:** `Sanctum::authenticateAccessTokensUsing()` rejects tokens whose owner is deactivated. Deactivation also deletes the tokens, so this is a second line of defence.
- **Revocation:** logout (current token), password reset (all tokens) and deactivation (all tokens).
- **Pruning:** `sanctum:prune-expired --hours=24` is scheduled daily in `routes/console.php`. This needs the scheduler running in production.
- Token abilities are not used (every token gets `*`). Authorization is decided by policies and permissions ([ADR-006](ADR-006-roles-and-permissions.md)).

## Consequences
- There is no refresh token. Clients log in again after 8 hours.
- A first-party SPA keeps its token in client storage. If OQ-22 confirms a same-site SPA, switching to Sanctum's cookie mode (and enabling CSRF and credentialed CORS) is a contained change in the config and the auth controller.
