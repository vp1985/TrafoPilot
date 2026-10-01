# AGENTS.md

## Scope

Build HWOS directly in Dolibarr as external modules under `modules/`. Never patch Dolibarr core.

## Rules

- Use strict test-driven development: add one failing test, run it, implement the smallest change, rerun the focused and full suites.
- Reuse Dolibarr customers, suppliers, contacts, products, services, proposals, orders, invoices, warehouses, stock, users, permissions, and documents.
- Prefer Extrafields, hooks, triggers, tabs, menus, and Dolibarr domain classes over custom tables.
- Use custom tables only for domains absent from Dolibarr core.
- Every feature module depends on `modHwosCore`.
- Keep permission IDs and module IDs stable after release.
- Migrations must be idempotent and must not destroy business data during module deactivation.
- Critical writes require explicit permission checks, database transactions, idempotency keys, and audit events.
- Never expose secrets or copy production data into DEV/TEST without explicit authorization and anonymization.

## Promotion

Develop in DEV, package a versioned immutable artifact, install that artifact in TEST, and promote the identical accepted artifact to production only after explicit approval.
