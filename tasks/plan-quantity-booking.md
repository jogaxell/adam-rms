# Implementation Plan: Quantity Booking

Source of truth: `SPEC-quantity-booking.md` (approved 2026-10-08). Branch: `feat/quantity-booking` (from `release/all-pr-features`). Task list: `tasks/todo-quantity-booking.md`.

## Overview
Four slices, built bottom-up so each can be checked on its own:
1. **Foundation**: migration (2 columns), asset type setting, and one shared helper for "free cables of a type for these dates" + bind/exchange.
2. **Booking**: book / reduce by quantity (`assign.php`, `unassign.php`, search page).
3. **Picking**: bind on scan (`setStatusBarcode.php` + Barcode Dispatch), and manual "Pick tag…" on the project.
4. **Display + bulk create**: grouped "N× Type (k picked)" on the project, board and PDF, bulk asset creation, Readme/API docs.

## Architecture Decisions
- **Unbound = `assetsAssignments_bound = 0`.** The default is 1, so every existing row and every non-quantity booking is untouched (Q3). Code that doesn't know the column keeps working: an unbound row is a normal assignment.
- **One helper, new file `src/common/libs/bCMS/quantityBooking.php`** (required by the endpoints that use it, not by `head.php`):
  - `quantityFreeAssets($assetTypesId, $project, $preferStorageLocation = null, $preferAssetId = null, $excludeIds = [])` returns free, unblocked, not-ended assets of the type for the project's dates, sorted per FR5: same storage location → preferred asset → lowest tag.
  - `quantityBindScan($project, $asset)` runs the decision table from the spec (free / unbound elsewhere / bound elsewhere / all picked) and returns a result code + data.
  - The finance adjustment for moving an assignment between assets is extracted from `swap.php` into `projectFinance::swapAssignmentAsset()` and reused (swap.php calls it too, same behaviour).
  - The clash query is **copied once** into the helper. The other copies (`assign.php`, `swap.php`, date/status changes, `assets.php`) stay as they are (spec boundary).
- **Concurrency:** bind/exchange and quantity assign run inside `$DBLIB->startTransaction()` … `commit()`/`rollback()` (InnoDB, verified in the base migration). They lock the type's asset rows with `SELECT … FOR UPDATE` before re-checking availability, so two scanners or two bookers can't take the same cable. This is the first use of transactions in the codebase, so T3 verifies that MysqliDb's transaction API works with the `$DBLIB` setup.
- **API stays additive:** `assign.php` gets `quantity`, `unassign.php` gets `quantity`, `setStatusBarcode.php` gets response fields `bound` / `exchangedWith`, `newAssetFromType.php` gets `quantity` + response `assets[]`. Without the new params, every endpoint behaves as before. This is checked per task.
- **Bulk-create labels:** link to the existing `maintenance/barcodePrint.php?ids=…` with the new asset ids. No new print code.

## Dependency Graph
```
T1 migration
 ├─ T2 type setting (UI)
 └─ T3 helper ──┬─ T4 assign quantity ─┬─ T6 search page UI ── [CP-A]
                │  T5 unassign quantity┘
                ├─ T7 bind on scan ── T8 dispatch UI ── [CP-B]
                └─ T10 manual pick
 T9 project data + list display (needs T1; T10 builds on it)
 T11 PDF/board grouping (needs T9)
 T12 bulk create (independent, needs nothing)
 T13 docs + full manual test run ── [CP-C]
```
T12 can run anytime. Everything else goes in numeric order.

## Checkpoints
- **CP-A (after T6):** spec tests 1, 2, 7 (booking, no overbooking, legacy assign/unassign unchanged).
- **CP-B (after T8):** spec tests 3–6 (bind, exchange with storage preference, conflict, "all picked" offer) + a legacy `setStatusBarcode` call.
- **CP-C (after T13):** the full spec test list 1–10 on the dev stack (http://localhost:8090), then review, then merge on the user's go-ahead.

## Risks
| Risk | Mitigation |
|---|---|
| Swapping changes the price when a cable has its own rate override | Reuse swap's finance adjustment; test 3 uses a cable with an override. |
| MysqliDb transactions / `FOR UPDATE` behave unexpectedly | T3 checks this first, with a scratch script against the dev DB. If it fails, fall back to `GET_LOCK('qb-type-{id}')` around the critical section. |
| A date change makes unbound rows clash | The existing date-change check reports them as today (spec test 8). Automatic re-picking is a possible follow-up, **not in scope**. |
| A huge bulk create (500) times out | One transaction, batched inserts; tag generation is checked for N unique tags inside the transaction. |
| Twig templates assume every row has a tag | T9/T11 render unbound rows through an explicit `assetsAssignments_bound` branch; each project tab is checked visually. |
