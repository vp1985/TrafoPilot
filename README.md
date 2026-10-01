# HWOS Dolibarr

HWOS business functions implemented as external Dolibarr modules. Dolibarr core remains unmodified.

## Environments

- DEV: source modules are mounted read-only from this repository into the Dolibarr DEV containers.
- TEST: receives versioned release artifacts only.
- Production: receives the exact artifact accepted in TEST after explicit approval.

## Repository layout

- `modules/` — one directory per external Dolibarr module
- `tests/` — module contract and integration tests
- `scripts/` — development and release automation
- `docs/` — architecture and operational decisions
- `releases/` — generated, checksummed release artifacts

## Initial module

`hwoscore` provides stable shared permissions, schema versioning, and the append-only audit-event storage foundation for later HWOS modules.

## Development checks

Run from the repository root on `dolibarr-dev-test`:

```bash
scripts/test-dev.sh
```

The test runner executes against DEV only. It must never target TEST or production.
