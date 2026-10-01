# HWOS Core

Shared foundation for all HWOS Dolibarr modules.

## Version

`0.1.0`

## Module identifier

- Module ID: `700100`
- Rights class: `hwoscore`
- Activation constant: `MAIN_MODULE_HWOSCORE`

## Permissions

- `700101` — read shared HWOS information
- `700102` — administer HWOS settings

## Schema

Activation creates `llx_hwoscore_audit_event`. Deactivation removes module registration and permissions but intentionally preserves the table and its audit records.

The table is infrastructure for later audited domain commands. No generic write UI or public endpoint is included in this initial version.
