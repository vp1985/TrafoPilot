# Lexware history review remediation — 2026-10-03

Scope: uncommitted source in `/home/vadmin/worktrees/hwos-lexware-history`, synthetic DEV fixtures and local staged HTTP/Chromium requests. The previous acceptance report is superseded: independent reviews failed closed. Final independent review: passed on the final code after both fail-closed reviewers reported no security concern or logic error. No artifact, TEST/production, cron or live Lexware acceptance is claimed.

The prior RED table overstated coverage. Missing tables/methods and harness setup errors do not prove the reviewed transaction, concurrency, authority, migration or UI requirements. In particular, dunnings have no comprehensive public absence enumeration and must not be removed by absence. History headings alone do not prove populated history visibility. A current-schema row deletion is not a released-schema upgrade test.

## Focused regressions

Each RED below was observed before its implementation; parse/setup failures are excluded. Focused GREEN results were observed afterward. Full verification is recorded separately below.

| Finding | Focused case | Intended RED |
|---|---|---|
| 1 CSRF | `csrf-forced` | Native token checking was not forced before bootstrap |
| 2 transaction owner | `ordinary-rollback` | Partial native creation remained after failure |
| 3 current locked state | `locked-current-view` | Edit committed before native lock was not detected |
| 4 authority | `removed-dunning`, `unsupported-relationship-absence` | Dunning was tombstoned; detail-only owner granted relationship absence authority |
| 5 run fencing | `stale-full` | Older full scan removed a newer refresh observation |
| 6 source coverage | `conflict-source-fields`, `conflict-dependency-remap`, `source-contact-product-fields` | Date change was applied; contact mapping silently relinked an invoice; material contact/product changes updated automatically |
| 7 retry serialization | `retry-lock` | Retry ignored the batch organization lock |
| 8 positive relations | `relationship-positive` | Positive observation left retained relationship removed |
| 9 native associations | `conflict-dependent-invoice` | Resolution accepted dependent native invoice associations |
| 10 pagination | `contradictory-pagination`, `pagination-total-drift` | Contradictory typed metadata and changing page totals were accepted |
| 11 tax | `conflict-tax-resolution`, `conflict-native-dependency-resolution`, `conflict-line-input-resolution` | Resolution accepted unsupported tax/malformed quantity; missing physical dependency reached native write |
| 12 released upgrade | `conflict-upgrade-shipping` | Unchanged released shipment conflicted after schema activation |
| 13 reader visibility | PHP UI | Workflow/projection/conflict warning was absent for reader |
| 14 populated screenshots | Node visual | Populated-history assertion was absent |
| 15 ten-table contract | DEV schema | Contract covered only six module tables |
| 16 transaction/error checks | `transaction-return-values` | Failed begin return was ignored |
| 17 claims | Python acceptance | Report still claimed no implementation blockers |

Additional GREEN checks cover actual shipment batch associations; invoice discount and time links; explicit-resolution latest-state snapshot; supported article absence; positive detail reappearance; immutable file bytes/dedupe; audit failure rollback; full fixture schema activation/backfill; and prohibited stock/bank/payment/bookkeeping table checksums.

Focused runner copies this worktree's modules and tests to temporary DEV paths, validates Compose labels, and runs `HWOS_HISTORY_CASE=CASE` with `HWOS_MODULE_ROOT=/tmp/hwos-second-review-*/modules`. Released fixtures are captured from repository HEAD 0.1 schema and snapshot implementation. The upgrade harness uses connection-local temporary tables to avoid replacing shared DEV tables; descriptor activation installs the new history tables and backfills existing mappings, shipment/current payload and file fixtures twice.

## Policy and verification limits

Absence authority is recorded per enumerated resource type. Detail/relationship GETs positively establish presence but do not grant absence authority. Dunnings are excluded from resource absence inference. A newer non-dry run fences older reconciliation; newer resource observations cannot be overwritten by older batches. Retry shares the organization lock and makes no API request.

Projection owns its transaction under REPEATABLE READ, locks current native/mapping/source dependency state before comparison and rolls back native failures before separately recording conflict. Material consumed source changes for contacts, products and documents, and changed native linkage, require review. Existing mappings and explicit Lexware application validate tax, finite numeric inputs and physical dependencies. Shipment batch and invoice discount/time associations block Lexware resolution; the code does not claim that destructive updates preserve these unsnapshotted associations. Dolibarr choice preserves native state, including accepted unsupported source versions. Metadata-only contact updates retain inactive people.

Released shipment baseline comparison recognizes the 0.1 format and validates added origin links against its recorded line origins. Genuine native edits remain conflicts. Native snapshots remove duplicate numeric DB result keys and volatile header modification timestamps.

Workflow and projection status render separately. Read-authorized users see conflict warnings/links. Browser fixtures include archived/removed resources, inactive relationships, distinct payload/file histories, resolution and prior native state. Screenshots remain private; the driver only navigates the local DEV site.

The 32 MiB file limit, indefinite histories, manual/admin-only synchronization, delegated mapping/retry, read visibility, GET-only allowlisted transport, no notification/pruning and protected-business-table checks remain covered. No commit/push, live sync, TEST/production or core change is authorized or claimed.

## Second fail-closed review remediation

The second review found six additional blockers. Earlier reconciliation, pagination, tax, run-fencing, transaction, migration and reader-visibility regressions remain in the full suite. The following intended RED assertions were observed before their corresponding changes; setup errors (disabled `proc_open`, oversized synthetic voucher numbers and absent `NODE_PATH`) are excluded.

| Finding | Focused regression / exact RED evidence | GREEN evidence |
|---|---|---|
| 1 Recovery/security | `test_lexware_fixture_recovery.php`: `recovery metadata must survive populate without normal JSON`; `no fixture run rows remain` at interruption before run journal update; `native module failure must not suppress exact prior constants restoration`. Python: `populate recovery metadata must be available before normal JSON`; aggregation expected `fixture; custom-mode; helper` but only `HELPER_FAILED` survived; `PHP recovery phase report must survive subprocess failure` caught lost phase output. | 20 real DB fault scenarios, plus lifecycle doubles and Python recovery/aggregation checks. |
| 2 Product persistence | `product-readback`: `persisted selling price, TTC, tax/base and service type must match chosen source`; `swallowed same-price native history failure must abort resolution`. | Direct product/price-history read-back; type, price-log and read-back faults restore native rows, price history and baseline, with no new resolution. |
| 3 Line association | `line-association-readback`: `propal persisted selected product and ordering must match source and retain line identities`; `dependent propal link accepted`. | Quotation/order/invoice A→B replacement and reordering retain line IDs; checked association/read-back faults roll back; dependent links stay visibly conflicted. |
| 4 Person extrafields | `contact-person-extra`: `local contact-person extrafield edit must conflict`; `contact extras must read back unchanged or fail closed`. | Extra rows/data are detected and preserved, included in immutable prior snapshots and locked before reads. A second DB connection commits a change before the lock; resolution records that committed state. Injected extra-data loss rolls back. |
| 5 CSRF HTTP evidence | Python: `HTTP 200 forged-token response must explicitly prove native rejection`. | Generic unchanged-state HTTP bodies are rejected by the assertion helper. Authenticated missing/forged POSTs on index/issues/resource prove native rejection with global CSRF disabled. |
| 6 Mobile/evidence | Node: `private populated mobile resource/history screenshot required`; Chromium: `HISTORY_LAYOUT_OVERFLOW`; Node: `full-page evidence must reset sticky native navigation before capture`. | Scoped resource text wrapping; populated mobile screenshot, indicator visibility/clipping and layout assertions; scroll reset before full-page capture. |

Recovery writes a private, password-free journal before module/user/security mutation. Run insertion carries the already-journaled fixture identity, allowing recovery even before its numeric ID is saved. Populate faults cover CSRF disable, the insert-to-journal interval, run/product creation and populated history. Cleanup separately attempts/reports CSRF, history, user, Lexware module, Core module and helper phases. Tests inject faults before/after each phase, midway through history deletion, after native module restoration and across all phases. Repeat cleanup proves exact prior CSRF values/absence and both module constant rows, with no synthetic rows/users remaining. Native module errors do not suppress raw constant restoration. Python independently attempts fixture recovery, directory-mode restoration, executable/helper cleanup and lock release, aggregating failures and displaying PHP attempted/failed phase names even on subprocess failure; on incomplete fixture recovery it retains the private journal and reports its path.

Product resolution uses the 24.0.0 native `Product::update(..., 'update', true)` type flag and checked `updatePrice()`. Read-back checks selling price, TTC, VAT/base, recoverability flag, type and native price history before accepting the baseline. The native price method's swallowed history-write failure is detected even when the previous stored price already matches the source.

Document lines use native `CommonObject::setValueFrom()` for `fk_product`, followed by per-line association/order read-back. Line identities and extras are retained. Linked native documents and order shipment-origin lines block explicit Lexware resolution, in addition to the prior invoice discount/time and shipment-batch guards. Contact-person snapshots include `socpeople_extrafields`; a released snapshot lacking the field is compatible only when current person extras are empty. Existing person optionals are loaded and their dependent data is read back unchanged; failure remains visible and rolls back.

## Final verification

Verified final results:

- `scripts/test-dev.sh`: exit 0; 21 Python tests, 6 Node tests, all 11 PHP test files, 57 isolated history cases and 20 fixture-recovery fault scenarios passed. Log: `/tmp/hwos-second-full-final.log`.
- Authenticated staged HTTP/Chromium: exit 0, using `/hwos-second-review/hwoslexware`. Cleanup reports all six attempted phases successfully. All six missing/forged-token POSTs prove native rejection with global CSRF disabled; retained JSON/file downloads match exact bytes; delegated mapping forms and reader conflict visibility pass; unauthorized actions preserve mirror checksums. Log: `/tmp/hwos-second-http-final-06.log`. The temporary staged DEV directory was removed after verification.
- Seven screenshots in `/tmp/hwos-second-review-visual-final-06`: `overview-desktop.png`, `resources-desktop.png`, `resource-desktop.png`, `native-conflict.png`, `issues-desktop.png`, `overview-mobile.png`, `resource-mobile.png`. Directory mode 0700; every PNG mode 0600. The populated mobile resource screenshot was visually inspected after the scroll reset.
- Final DEV integrity probe: 532 protected mirror records, valid payload checksums, no duplicate identities, Lexware inactive and no temporary HTTP users or UI-history runs. Log: `/tmp/hwos-second-integrity-final.log`. Two identified synthetic fixtures left by early RED harness runs were specifically removed; the final fault harness and HTTP runner clean up their own fixtures.
- Syntax: 36 PHP files, 8 Python AST files, 2 Node files and 1 shell script passed; `git diff --check` passed. Log: `/tmp/hwos-second-syntax-final.log`.

The desktop evidence covers overview, resources, populated resource history, native conflict tab and issues. The issues screenshot deliberately uses a read-authorized user without mapping rights; missing resolution controls are expected. Admin and delegated mapping forms on issues/resource are covered by PHP rendered-UI tests; delegated mapping forms are also checked over authenticated HTTP. Mobile evidence covers overview and populated resource/history at 390×844; this is not a general Dolibarr mobile acceptance claim. Checks require conflict, removed/archived and history indicators to be visible and unclipped, and resource content to fit the viewport. Details are expanded and capture starts at the top.

All six second-review findings have implementation/regression evidence above. Final independent review passed on the final code with no security concerns or logic errors. No commit, push, original-checkout edit, live Lexware call/write, cron enablement, TEST/production change or artifact promotion is claimed.

## Files changed for this remediation

Pre-existing worktree changes were preserved. This remediation edited or added these exact paths:

```text
docs/lexware-history-acceptance-2026-10-03.md
modules/hwoslexware/README.md
modules/hwoslexware/class/LexwareProjection.php
modules/hwoslexware/class/LexwareStore.php
modules/hwoslexware/class/LexwareSync.php
modules/hwoslexware/core/modules/modHwosLexware.class.php
modules/hwoslexware/lib/bootstrap.php
modules/hwoslexware/object.php
modules/hwoslexware/resource.php
modules/hwoslexware/payload.php
scripts/lexware-ui-dev.py
scripts/lexware-ui-fixture.php
scripts/lexware-visual-dev.cjs
scripts/test-dev.sh
tests/php/test_lexware_dev.php
tests/php/test_lexware_fixture_lifecycle.php
tests/php/test_lexware_history.php
tests/php/test_lexware_ui.php
tests/test_lexware_visual.cjs
tests/test_lexware_acceptance.py
tests/fixtures/lexware-0.1/schema.sql
tests/fixtures/lexware-0.1/snapshot.php
```

## Files changed in the second review pass

Earlier worktree changes were retained. This pass changed these 14 paths:

```text
docs/lexware-history-acceptance-2026-10-03.md
modules/hwoslexware/README.md
modules/hwoslexware/class/LexwareProjection.php
modules/hwoslexware/resource.php
scripts/lexware-ui-dev.py
scripts/lexware-ui-fixture.php
scripts/lexware-http-assertions.py
scripts/lexware-visual-dev.cjs
tests/php/test_lexware_fixture_lifecycle.php
tests/php/test_lexware_fixture_recovery.php
tests/php/test_lexware_history.php
tests/php/test_lexware_ui.php
tests/test_lexware_dev_helpers.py
tests/test_lexware_visual.cjs
```

## Final concurrency and case-intent corrections

Independent approval passed on this exact final code. This revision is uncommitted source in the isolated history worktree. No original-checkout change, commit, push, TEST/production installation, cron activation, live Lexware request or write occurred.

- Manual contact replacement locks the resource and native target, then locks the exact mapping object key/range before deciding occupancy. The native target row serializes absent-target claims. Mapping inserts use plain INSERT; existing baselines update only the same entity/resource/object owner. Exact ownership, JSON, snapshot checksum, source checksum and policy read back before success/status/issue/audit writes. Transaction failures roll back the replacement, including the previous mapping.
- Every resolution form submits its immutable issue ID. Both handlers retain POST, native CSRF and rights checks. Under resource/issue locks, resolution validates current open case ID, entity/resource and issue/source checksum before native locking, snapshot or writes. Changed open conflict details receive a new case ID instead of reusing the old identity.
- The disabled worker marks superseded pending runs transactionally, audits once and selects the latest eligible run. Batch supersession triggers reconciliation and bounded reselection; five attempts defer further work instead of busy-looping. Optional injected transport permits deterministic local tests without loading credentials or making live requests.
- Module documentation now describes local history visual evidence without a live connection test, and limits separate workflow/projection claims to detail/native views.

Observed intended RED assertions:

- `mapping-collision`: `cross-resource collision was accepted` (initial malformed test JSON was corrected and excluded from RED evidence).
- `mapping-concurrent`: `simultaneous replace loser must fail while winner owns target lock`, when target locking was removed. Two independent PHP/DB connections overlap replacement approvals; a one-second session lock timeout makes the losing request deterministic. No server configuration was changed.
- `conflict-case-Lexware` and `conflict-case-Dolibarr`: `stale case A accepted for B` with case validation absent. Both test identical source checksums and unchanged native/mapping/B/resolutions/audit after rejection.
- `cron-superseded`: missing pending-run selection, followed by missing local-transport injection. GREEN also exercises a new run inserted between selection and batch fencing, and asserts exactly two local profile calls and completion of the next eligible run.
- Authenticated staged HTTP with case validation absent failed the stale-form assertion. GREEN exercised both resource and issues handlers, both choices, explicit successful B resolution, native CSRF with global CSRF disabled, and reader/permission/history behavior.

Final evidence:

- Focused cases: `mapping-collision`, `mapping-concurrent`, `conflict-case-Lexware`, `conflict-case-Dolibarr`, `cron-superseded` pass.
- Full suite: `scripts/test-dev.sh`; final log `/tmp/lx-full-final-clean.log`. CLI subprocess capability is enabled only for the synthetic history test invocation, not the webserver or persisted PHP configuration.
- Authenticated staged HTTP/Chromium: exit 0; `/tmp/lx-http-visual-final.log`, staging URL `/hwos-final-fix/hwoslexware`. Seven PNGs in `/tmp/hwos-final-intent-visual-green` are mode 0600 in a 0700 directory. The populated mobile resource view was inspected. All six fixture recovery phases completed. Playwright's existing module path was supplied via `NODE_PATH`; the earlier missing module path is excluded from functional failure evidence.
- PHP/Python/Bash/Node syntax and `git diff --check` pass. Temporary DEV staging and focused-test helper are removed after verification. No unresolved implementation blocker is identified; independent approval is still required.

Exact files modified for these corrections (earlier uncommitted history changes remain preserved):

```text
modules/hwoslexware/README.md
modules/hwoslexware/class/LexwareJobs.php
modules/hwoslexware/class/LexwareProjection.php
modules/hwoslexware/class/LexwareStore.php
modules/hwoslexware/issues.php
modules/hwoslexware/resource.php
modules/hwoslexware/lib/bootstrap.php
scripts/lexware-ui-dev.py
scripts/lexware-ui-fixture.php
scripts/test-dev.sh
tests/php/test_lexware_history.php
docs/lexware-history-acceptance-2026-10-03.md
```


## Independent rerun remediation: worker transition and synthetic residue

This section supersedes the earlier final-suite/zero-residue claims. Independent review later passed on the final code. Work stayed in the isolated worktree and DEV staging; there was no commit/push, original-checkout edit, TEST/production change, cron activation, live Lexware request/write or promotion.

The existing strict `cron-superseded` assertion was retained, including exactly two local profile calls and completion of the eligible successor. Diagnostics now include `jobs->error` as well as result/calls/output/eligible. The supplied independent failure did not contain its diagnostic values; the original pending-successor scenario passed on the initial isolated rerun. A separate overlapping diagnostic invocation returned `result=-1,calls=0`, before transport; the organization-wide batch lock can reject overlapping workers. That observation is distinct from the deterministically reproduced transition defects below and is not claimed as proof of the independent failure's cause.

Deterministic REDs exposed two real worker defects: `completed supersession must defer without starting an unintended run`, then `selection reconciliation must not start a run when newer run already completed`. The old worker treated an empty selection after supersession as a fresh cron start, even when the current newer run had already completed. Selection also ran redundantly twice after a rejected batch. The worker now retains the reconciliation result, marks pending predecessors transactionally/audits once, resumes the eligible current pending run, and defers when reconciliation finds no eligible successor. Reconciliation also runs after the fifth rejected batch, so the last rejected pending run is not left unmarked. Five batches bound each invocation. Deferral happens before loading the environment transport.

The local tests insert successors during the profile verification between selection and the batch fence, covering pending and already-completed successors. They also cover supersession already visible at selection, exact run counts (no unintended cron start), repeated reconciliation without duplicate audit, and two consecutive worker invocations under continuous supersession (exactly five profile calls per invocation). Every inserted run ID is tracked immediately, before assertions.

Cleanup RED: an injected assertion before run tracking left a row and failed `HISTORY_CLEANUP_RESIDUE` under the previous array-based cleanup. The permanent helper discovers all native fixtures and module rows in the current random test entity, including untracked inserts and displaced mappings. It rejects entity 1 and every entity outside the test range. It removes native dependencies, module history/resources/runs/audits, disabled fixture cron rows, activation/schema constants and synthetic module metadata. The failure regression creates an untracked run, mapped native customer, resource/history/audit, disabled cron and constant, then throws; finally cleanup and repeated cleanup leave nothing. History finally restores the real DB handle and rolls back any unfinished fault transaction before cleanup. Existing cron/constants cleanup is retained through this shared helper.

The first invariant-enabled full run failed and is excluded from acceptance evidence: it caught one disabled cron row and two schema constants left by the DEV projection/recovery tests (`/tmp/lx-repeat-full-1.log`). Their finally paths now use the same protected cleanup. `scripts/test-dev.sh` checks exact `history-test` context, module-owned cron/constants in the declared random range, and the exact generated HTTP fixture-user login pattern after all PHP tests. It does not classify unrelated old/pending runs as fixtures.

The one-off helper is outside the repository: `/tmp/lx-protected-cleanup.php`, mode 0600 on host and DEV. It identifies entities through HWOS module rows/cron/constants in the test range or exact `history-test` context, refuses out-of-range history entities and unrelated constants/cron/users, runs transactionally, compares entity 1 row fingerprints/counts before commit, and verifies all entity-bearing rows in the selected synthetic entities are gone. It prints counts only. No fixture users were present; unexpected users cause refusal rather than deletion. Initial cleanup evidence: `/tmp/lx-cleanup-counts.log`.

Exact initial cleanup counts (159 owned synthetic entities → 0):

| Table | Before | After |
| --- | ---: | ---: |
| `llx_actioncomm` | 439 | 0 |
| `llx_commande` | 1 | 0 |
| `llx_const` | 193 | 0 |
| `llx_cronjob` | 78 | 0 |
| `llx_expedition` | 1 | 0 |
| `llx_extrafields` | 906 | 0 |
| `llx_facture` | 1 | 0 |
| `llx_hwoscore_audit_event` | 9447 | 0 |
| `llx_hwoslexware_file` | 1 | 0 |
| `llx_hwoslexware_file_version` | 1 | 0 |
| `llx_hwoslexware_issue` | 1 | 0 |
| `llx_hwoslexware_mapping` | 5 | 0 |
| `llx_hwoslexware_payload_version` | 15 | 0 |
| `llx_hwoslexware_relation` | 0 | 0 |
| `llx_hwoslexware_resolution` | 0 | 0 |
| `llx_hwoslexware_resource` | 15 | 0 |
| `llx_hwoslexware_run` | 9 | 0 |
| `llx_hwoslexware_state_event` | 0 | 0 |
| `llx_menu` | 2 | 0 |
| `llx_product` | 1 | 0 |
| `llx_product_price` | 1 | 0 |
| `llx_rights_def` | 40 | 0 |
| `llx_societe` | 1 | 0 |
| `llx_user` | 0 | 0 |
| `llx_user_rights` | 958 | 0 |

After the failed invariant run, a second protected cleanup removed its two synthetic entities: actioncomm 20→0, constants 2→0, cron 1→0, extrafield definitions 12→0, audit 145→0, user rights 14→0; all module history/resource/run rows and users were already 0→0. Evidence: `/tmp/lx-cleanup-followup-counts.log`. Both cleanups preserved every entity 1 row fingerprint/count and cron 9 status 0. Entity 1's relevant unchanged counts: constants 296, cron rows 8, runs 14, resources 532, payload versions 532, mappings 3, files 1, file versions 1, issues/relations/resolutions/state events 0, audit rows 6317. Native counts remain customers 4, contacts 1, products/prices 6 each, quotations 3, orders 1, invoices 1, shipments 0. The final integrity check compares every entity 1 table count against the initial pre-cleanup baseline.

Final verification on the final code:

- Focused GREEN evidence covers cron supersession, concurrent mapping ownership, immutable conflict-case binding, assertion-failure cleanup, untracked native/module fixtures, repeated cleanup, entity-1 refusal and all pre-journal publication/baseline failures.
- Two sequential final `PYTHONDONTWRITEBYTECODE=1 bash scripts/test-dev.sh` runs passed independently with pristine output. Each passes 22 Python tests, 6 Node tests, all 12 PHP files, 62 isolated history cases, seven initial-recovery cases and 20 fixture-recovery scenarios.
- Every final run ended with zero across all 23 ownership counters: test runs, resources, mappings, issues, files, relations, all four history tables, synthetic native objects, action records, audit rows, fixture cron rows, fixture constants and fixture users.
- Entity 1 remained byte/count-identical to its protected baseline; cron row 9 remained disabled. `git diff --check` passed. Temporary DEV staging and recovery artifacts were removed.
- Final independent security/data-integrity review: `passed: true`, `security_concerns: []`, `logic_errors: []`.
- Final independent specification/migration/UI review: `passed: true`, `security_concerns: []`, `logic_errors: []`.

This reviewed implementation is ready for commit, immutable artifact packaging and TEST promotion. It is not a new live-Lexware proof, does not enable cron and does not authorize production.
