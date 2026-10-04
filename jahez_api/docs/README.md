# Documentation Index

## Suggested reading order

1. [../README.md](../README.md): what the project is and how to run it
2. [implementation-plan.md](implementation-plan.md): phases, current status, approvals, risks
3. [open-questions.md](open-questions.md): owner decisions that block or shape work
4. [requirements-traceability.md](requirements-traceability.md): source requirement → module → endpoint → test
5. [architecture.md](architecture.md) and [data-model.md](data-model.md)
6. [api-conventions.md](api-conventions.md) and [api-endpoints.md](api-endpoints.md): conventions and every endpoint
7. [roles-permissions.md](roles-permissions.md) and [workflows.md](workflows.md): who may do what, and the state machines
8. [security/threat-model.md](security/threat-model.md), [security/security-test-matrix.md](security/security-test-matrix.md) and [security/findings.md](security/findings.md)
9. [testing/strategy.md](testing/strategy.md): how to test, and the quality gates
10. [deployment.md](deployment.md): production settings and the `app:check-production` go-live gate
11. [performance/plan.md](performance/plan.md): performance scripts and what a real measurement needs

## Reference

| Document | Contents |
| --- | --- |
| [decisions/](decisions/) | Architecture Decision Records (see the table below) |
| [phases/](phases/) | Per-phase logs with commands run and their real results |
| [engineering-skills.md](engineering-skills.md) | Agent skills and tools actually used |
| [troubleshooting.md](troubleshooting.md) | Problems reproduced and solved in this project |
| [glossary.md](glossary.md) | Arabic/English terminology and reference-data codes |
| [../postman/README.md](../postman/README.md) | Postman collection and how to run it |
| [../AGENTS.md](../AGENTS.md) | Instructions for AI coding agents (generated; edit `.ai/guidelines/jahez.md`) |

## Decisions

| ADR | Title | Status |
| --- | --- | --- |
| [ADR-001](decisions/ADR-001-version-control.md) | Version control with git | Accepted |
| [ADR-002](decisions/ADR-002-api-versioning-and-sanctum.md) | URL-versioned API; Sanctum installed | Accepted |
| [ADR-003](decisions/ADR-003-authentication-bearer-tokens.md) | Bearer-token authentication with Sanctum | Accepted (D2; revisit with [OQ-22](open-questions.md#oq-22)) |
| [ADR-004](decisions/ADR-004-database-mysql.md) | MySQL as the database engine | Accepted (production version open) |
| [ADR-005](decisions/ADR-005-static-analysis.md) | Larastan level 8 | Accepted |
| [ADR-006](decisions/ADR-006-roles-and-permissions.md) | Roles, named permissions, organization ownership | Accepted (D3, D4) |
| [ADR-007](decisions/ADR-007-agent-guidelines.md) | Agent rules in `.ai/guidelines/` | Accepted |
| ADR-008 | `app/Actions` for transactional operations | Pending: not needed so far (Phase 6 keeps its transactions in controllers and model methods) |
| [ADR-009](decisions/ADR-009-error-envelope-and-request-id.md) | Error envelope and request ID | Accepted |
| [ADR-010](decisions/ADR-010-reference-data.md) | Reference data from the source document | Accepted (language part interim, [OQ-23](open-questions.md#oq-23)) |
| [ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md) | Account provisioning, passwords, abuse controls | Accepted (D1; values provisional, [OQ-33](open-questions.md#oq-33)) |
| [ADR-012](decisions/ADR-012-audit-log.md) | Append-only audit log | Accepted (retention open, [OQ-25](open-questions.md#oq-25)) |
| [ADR-013](decisions/ADR-013-api-only.md) | API-only application | Accepted (answers [OQ-34](open-questions.md#oq-34)) |
| [ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md) | Service catalog, provider profiles, IMC approval, eligibility | Accepted (owner decisions 2026-10-03; [OQ-36](open-questions.md#oq-36), [OQ-37](open-questions.md#oq-37) open) |
| [ADR-015](decisions/ADR-015-marketplace-requests.md) | Marketplace request model | Accepted model (answers [OQ-03](open-questions.md#oq-03)); states PROPOSED ([OQ-38](open-questions.md#oq-38)–[OQ-40](open-questions.md#oq-40)) |
| [ADR-016](decisions/ADR-016-manual-factory-classification.md) | Manual factory classification | **Superseded** by ADR-018 (records kept read-only) |
| [ADR-017](decisions/ADR-017-agreements-contracts-billing.md) | Agreements, contract drafts, billing and payment boundaries | Accepted structures; every commercial and legal rule open ([OQ-15](open-questions.md#oq-15)–[OQ-17](open-questions.md#oq-17)) |
| [ADR-018](decisions/ADR-018-digital-readiness-assessment.md) | Digital readiness assessment and score-based classification | Accepted (owner-supplied framework, 2026-10-03; [OQ-41](open-questions.md#oq-41), [OQ-42](open-questions.md#oq-42) open) |

## Planned documents

`workflows.md` exists since P6. `deployment.md` exists since P9 and is verified on a real host in P11. `performance/plan.md` exists since the P10 preparation; real results wait for staging.
