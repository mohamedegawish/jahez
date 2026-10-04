# ADR-006: Roles, named permissions and organization ownership

- **Status:** Accepted (2026-10-02, Phase 3), as decisions **D3** and **D4**
- **Decided by:** Project owner accepted the recommended defaults ("ok, complete"). Revisit when [OQ-21](../open-questions.md#oq-21) (IMC department roles) is answered.

## Decision
1. **Three roles** (`App\Enums\Role`), stored in `users.role`:
   - `imc_admin`: an IMC administrator, with no organization.
   - `factory_member`: belongs to exactly one factory (`users.factory_id`).
   - `provider_member`: belongs to exactly one service provider (`users.service_provider_id`).
2. **Named permissions** (`App\Enums\Permission`, for example `factories.view_any` and `users.create`). Policies check permissions, never role names. `Role::permissions()` is the single mapping: today `imc_admin` holds all of them and members hold none. Splitting IMC administration into the DOC §7 departments later means adding roles and changing that mapping, not the policies.
3. **Organization ownership** comes from the authenticated user's own `factory_id` / `service_provider_id`, never from request input. Members can see their own organization and its members. Anything else is **404** (`Response::denyAsNotFound()`), so other tenants' records are not revealed to exist. An action on something they can see but may not change is **403**.
4. **Integrity in the database:** a CHECK constraint `users_role_organization_check` makes the organization link match the role (admin: none; factory member: factory only; provider member: provider only). Organization FKs use `ON DELETE RESTRICT`, so an organization that still has members cannot be deleted.
5. **No package.** A permission package (for example spatie/laravel-permission) would add a dependency and runtime role tables that nothing needs yet. The enum approach is small, typed and fully tested.
6. **Organizations hold only `name` and sectors** (D4), until the profile fields are confirmed ([OQ-19](../open-questions.md#oq-19), [OQ-20](../open-questions.md#oq-20)).

## Consequences
- Role and organization are never mass-assignable. They are set explicitly only when an administrator creates an account, and they cannot be changed afterwards in this phase.
- The User relation to its factory is `industrialFactory()`, not `factory()`, because `HasFactory` already defines the static `factory()` method.
- The CHECK constraint needs MySQL ≥ 8.0.16 or MariaDB ≥ 10.2. It is verified on MariaDB 10.4 and must be re-verified on the production MySQL version ([ADR-004](ADR-004-database-mysql.md)).
- The full permission matrix is tested at the policy level in `tests/Feature/Policies/`. The endpoint tests cover the HTTP wiring.
