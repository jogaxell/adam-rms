# Todo: Quantity Booking

Plan: `tasks/plan-quantity-booking.md` · Spec: `SPEC-quantity-booking.md`

## Slice 1 — Foundation
- [x] **T1: Migration**
  - Acceptance: `assetTypes.assetTypes_quantityBooking` tinyint default 0, and `assetsAssignments.assetsAssignments_bound` tinyint default 1. Reversible `change()`.
  - Verify: `php vendor/bin/phinx migrate`, then `rollback`, then `migrate` on the dev stack; existing rows have bound = 1.
  - Done 2026-10-08: migrate/rollback/migrate clean; all 65 existing assignments bound = 1.
  - Files: `db/migrations/*_QuantityBooking.php`
- [x] **T2: Asset type setting**
  - Acceptance: a checkbox "Book by quantity" in the asset type edit form, saved by `editAssetType.php` (and on creation in `newAssetType.php`), and shown as a badge on the asset type page. Turning it on doesn't change existing assignments.
  - Verify: toggle it in the UI, check the DB value, reload the form.
  - Done 2026-10-08: the switch + badge render off/on; a save via `editAssetType.php` switches 0→1→0 with the rest of the type row and all finance caches unchanged.
  - Files: `src/api/assets/editAssetType.php`, `src/api/assets/newAssetType.php`, the asset type form twig, `src/asset.twig`
- [x] **T3: Shared helper**
  - Acceptance: `quantityBooking.php` with `quantityFreeAssets()` (FR5 order) and `quantityBindScan()` (spec decision table). The finance move is extracted from `swap.php` and swap uses it unchanged. Transaction + `FOR UPDATE` confirmed to work (or the `GET_LOCK` fallback is in place).
  - Verify: `php -l`; a scratch script in the scratchpad against the dev DB lists free cables in the right order; swap a cable that has a rate override and check the finance totals are the same as before the refactor.
  - Files: `src/common/libs/bCMS/quantityBooking.php`, `src/common/libs/bCMS/projectFinance.php` (`swapAssignmentAsset()`), `src/api/projects/assets/swap.php`. The endpoints that need the helper `require_once` it, like `projectFinance.php`, so `head.php` is untouched.
  - Done 2026-10-08: a scratch script (scratchpad `qb_test.php`, own data, cleaned up) passed 21 checks, covering every row of the decision table incl. the storage-location preference, finance deltas with a rate override and NOREPLACEMENT rollback. A second process holding the type lock made a bind wait 4.6 s.

## Slice 2 — Booking
- [x] **T4: `assign.php` quantity**
  - Acceptance: `assetTypes_id` + `quantity` on a quantity type books up to N free cables (helper order) as `bound = 0`, inside a transaction. The response adds `assigned` (int). If fewer are free → books what is free and reports it. A per-tag assign on a quantity type → `bound = 1`. Without `quantity` → unchanged.
  - Verify: spec tests 1, 2; a legacy call with `assetTypes_id` only still books all of the type.
  - Files: `src/api/projects/assets/assign.php`
  - Done 2026-10-08: over HTTP, A booked 10 (tray 1 first, subtotal 70.00 incl. the 5.00 rate override). Overlapping B asked for 3 and got 2, then 1 more was refused. Quantity 0 and quantity on a normal type are refused. Legacy all-of-type booked 12 bound and removed them again, with the same response shape as before.
- [x] **T5: `unassign.php` quantity**
  - Acceptance: `assetTypes_id` + `projects_id` + `quantity` removes N assignments, unbound first, never bound ones beyond what's needed (and bound ones only when explicitly asked by assignment id). Without `quantity` → unchanged.
  - Verify: book 10, pick 3, reduce by 8 → 7 unbound removed + 1 refused/reported; the finance totals match.
  - Files: `src/api/projects/assets/unassign.php`
  - Done 2026-10-08: remove 3 → 3 newest unbound. With 5 picked, remove 8 → only the 2 unbound go (`removed: 2`), then remove 1 → "all picked" error. Legacy remove-by-asset still works; finance correct after each step. Group-watch notifications are skipped for unbound rows (`assetAssignmentSelector` now also selects `assetsAssignments_bound`).
- [x] **T6: Search page UI**
  - Acceptance: quantity types show a number input (max = available) + Add / Remove, and "N booked" when a project is selected. Per-tag buttons are moved into the details dialog. Other types are unchanged.
  - Verify: book/reduce from the search page; available counts update; no console errors.
  - Files: `src/assets.php`, `src/assets.twig`
  - Done 2026-10-08: in Chrome, Book 2 → toast "Booked 2 on QB Test B" and the results redraw (3→5 booked, 5→3 free); Remove 3 → 2 booked, 6 free, no console errors. Per-tag buttons stay in the details dialog unchanged.
- [x] **CP-A**: spec tests 1, 2, 7 (all passed 2026-10-08, see T4–T6).

## Slice 3 — Picking
- [x] **T7: Bind on scan**
  - Acceptance: `setStatusBarcode.php` calls `quantityBindScan()` before its `NOTASSIGNED` check, for quantity types only. Every row of the decision table works: exchange is silent and audit-logged on both projects (`BIND-ASSET`, `EXCHANGE-ASSET`), and the replacement prefers the scanned cable's storage location. The response adds `bound` / `exchangedWith`. Status and location then proceed as today.
  - Verify: spec tests 3–6; a legacy request on a non-quantity type gives an identical response.
  - Files: `src/api/projects/assets/setStatusBarcode.php`, `quantityBooking.php`
  - Also `setStatusByTag.php` (Quick Dispatch by typed tag), through the shared `quantityBindForProject()`. A quantity scan validates the status before picking.
  - Done 2026-10-08: over HTTP, free QB-09 → `bound:true`. B's placeholder QB-04 → exchanged, and B got QB-02 (same tray, and the cable A released). Rescan → `bound:false`. A cable picked for B → CONFLICT, nothing changed. Own placeholder → bound in place. All picked → NOTASSIGNED with `allPicked:true`. Unknown status → STATUSNOTFOUND, nothing picked. Quick Dispatch QB-11 → picked for B. A legacy scan of a normal asset only gains `bound:false, exchangedWith:[]`.
- [x] **T8: Barcode Dispatch UI**
  - Acceptance: the log line shows "picked" / "(exchanged with Project B)". The board shows unbound rows as a "to pick: N" counter per type, not as draggable cards. After a bind, the board is marked stale like for new assets.
  - Verify: scan through a booking of 5 on the 320×450 viewport.
  - Files: `src/project/project_assetsBoard.twig`
  - Also: Location Dispatch leaves placeholders out (they have no physical asset to locate), and the "Add asset?" prompt says when every booked one is already picked.
  - Done 2026-10-08: in Chrome, project C showed "3× to pick" (dashed, not draggable: sortable `items` limited to cards). Scanning QB-07 logged "picked → Pending pick" and showed the reload badge; after closing, the board had the QB-07 card + "2× to pick". No console errors.
- [x] **CP-B**: spec tests 3–6 + a legacy `setStatusBarcode` call (all passed 2026-10-08, see T7–T8).
- [x] **T9: Project data + asset list**
  - Acceptance: `data.php` adds per-type `totals.quantityBooking`, `totals.count`, `totals.picked` (additive). The asset list shows "N× Type (k picked)", bound rows with tags, unbound rows as "not picked yet" without a tag.
  - Verify: the project page with a mixed booking; mobile-shaped `data.php` output only gains fields.
  - Files: `src/api/projects/data.php`, `src/project/project_assets.twig`
  - Done 2026-10-08: `data.php` totals gain `quantityBooking`, `count` and `picked` (main and sub-business). In Chrome the list read "2x QB Test Cable · 1 of 2 picked", the unpicked row "not picked yet" (no tag, storage location or scan shown) and the picked row "QB-11 Körche". After a manual pick it read "2 of 2 picked". No console errors.
- [x] **T10: Manual "Pick tag…"**
  - Acceptance: on an unbound row, enter or select a tag → new `src/api/projects/assets/bind.php` (permission `ASSIGN_AND_UNASSIGN`) runs the same `quantityBindScan()` logic.
  - Verify: pick a free tag, a tag that is a placeholder elsewhere (exchange), and a tag that is bound elsewhere (error).
  - Files: `src/api/projects/assets/bind.php`, `src/project/project_assets.twig`
  - The tag field also accepts a barcode value. As with a scan, the first unpicked placeholder of the type moves, not necessarily the clicked row (they're interchangeable). Error messages are HTML-escaped, since the prompt shows them as HTML; Quick Dispatch's pick errors are escaped the same way.
  - Done 2026-10-08: over HTTP, free tag → picked; B's placeholder → exchanged (A +20.00 and B −24.00 with the rate override). Then ALREADYBOUND, unknown tag (NOTFOUND), wrong type (WRONGTYPE), empty tag, a tag picked for B (CONFLICT) and all picked (ALLPICKED). In Chrome: Pick tag… → QB-09 → reload shows it picked.

## Slice 4 — Display, bulk create, docs
- [x] **T11: PDF / invoice grouping**
  - Acceptance: quantity types appear as one line "N× Type" with the total price. Bound tags are listed as today if the template lists tags; unbound rows show no tag.
  - Verify: generate the project PDF + invoice for a mixed booking.
  - Files: `src/project/pdf.twig` (+ invoice twig if separate)
  - The PDF already prints one "N× Type" line with the type total. Tags only appear with "show all", so the change is there: unpicked rows print "not picked yet" without tag, [F]/[B] flags or definable fields. (The same template serves invoice, quote and delivery note; `export-note.twig` lists no assets.)
  - Done 2026-10-08: invoice with show all → "3x QB Test Cable", "not picked yet" ×2, "QB-10". Without show all → only the type line.
- [x] **T12: Bulk create**
  - Acceptance: a Quantity field (1–500) on the new-asset form. `newAssetFromType.php` with `quantity` creates N assets + N QR barcodes in one transaction, with auto tags. A custom tag is only allowed with quantity 1. The response adds `assets[]`. The success message links to `maintenance/barcodePrint.php?ids=…`.
  - Verify: spec test 10; quantity 1 behaves exactly as before; the print link shows N labels.
  - Files: `src/api/assets/newAssetFromType.php`, `src/newAsset.twig`
  - Done 2026-10-08: quantity 3 → A-0077..79, each with its own QR barcode, storage location and notes. Quantity 2 + custom tag and quantity 501 are refused. Quantity 1 + custom tag, and a request without the field, behave as before. The label link (`barcodePrint.php?barcodeType=QR_CODE&ids=…`) printed the 3 labels without creating new barcodes. In Chrome: the field shows once a type is chosen and disables/clears the tag above 1; saving 2 → "2 assets added (A-0081 to A-0082) · Print labels". Not tested: a failure midway (the rollback path is the same MysqliDb transaction already proven in T3).
- [x] **T13: Docs + full run**
  - Acceptance: a Fork Improvements bullet in `Readme.md`, and `@OA` docs updated for every new param/field.
  - Verify: the full spec test list 1–10 on the dev stack, results noted here.
  - Files: `Readme.md`, touched endpoints' OA blocks
  - Done 2026-10-08: Readme bullet "Booking by quantity", and bulk create merged into the existing "adding assets" bullet. OA docs now cover assign/unassign `quantity`, the setStatusBarcode/setStatusByTag pick behaviour and fields, `bind.php`, newAssetFromType `assets_quantity` and the asset type setting.
  - Full run (scratchpad `qb_fullrun.sh`, own fixture, cleaned up): **35/35 checks passed**, spec tests 1–10:
    1. book 10 of 12 (tray 1 first, subtotal 70.00, search "2 free")
    2. B gets only the 2 free, nothing overlaps
    3. free scan picks and follows the rate override (+12.00)
    4. exchange gives B a cable from the scanned cable's tray rather than the released one, with BIND/EXCHANGE audit entries
    5. CONFLICT changes nothing
    6. all picked → NOTASSIGNED + allPicked, then the offered add books it picked
    7. legacy scan/assign/unassign responses unchanged apart from the added fields
    8. moving C onto A's dates is refused, listing the clashing cables
    9. switching the setting off/on leaves every assignment untouched
    10. 20 bulk-created with unique tags + QR barcodes; a refused request creates nothing
  - Test 7's no-op scan on a real dev project writes an `EDIT-STATUS` audit entry each run, and the type edits log under the type id as project id (an existing quirk). Those 7 test entries were deleted afterwards.
- [ ] **CP-C**: review, then merge on the user's go-ahead.

## v1.5.1 — FR10 Re-arranging unpicked reservations
- [x] **T14: Planner**
  - Acceptance: `quantityPlan()` in `quantityBooking.php` follows R2–R5 and returns the moves plus the cables for new reservations, or false. `quantityApplyPlan()` performs the moves with finance correction and audit entries (R6).
  - Verify: scratchpad `qb_chain.php` against the dev DB: the refused-pick and refused-booking chains get the expected layouts, an impossible chain returns false, and nothing is double-booked.
  - Files: `src/common/libs/bCMS/quantityBooking.php`
- [x] **T15: Use it for picking and booking**
  - Acceptance: an exchange in `quantityBindLocked()` goes through the planner (R1). `assign.php` falls back to it when too few cables are directly free, books the largest number that fits, and reports `rearranged`. The search toast mentions moved jobs.
  - Verify: both chains over HTTP; the full run `qb_fullrun.sh` still 35/35.
  - Files: `quantityBooking.php`, `src/api/projects/assets/assign.php`, `src/assets.twig`
  - Done 2026-10-08, verified on the dev stack (scratchpad `qb_chain.php`, `qb_eval.php`, `qb_fullrun.sh`; own data, cleaned up):
    - the refused-pick chain now gives A = Y (picked), B = X, C = Y; the refused booking now books 1 with C and D moved (also over HTTP: `assign.php` reports `rearranged`); with no possible layout the pick is still refused and nothing changes;
    - 25 random 6-cable runs: never double-booked, every job kept its count, every refused booking was a real shortage;
    - frozen-state evaluation, 5 near-full pools, ~300 exchange questions, compared with an exhaustive reference search: never a wrong yes; 5 wrong refusals in the tightest pool (10 cables / 30 jobs), none in the others; about 6–11 reservations moved per successful pick in those overloaded pools after "send back where possible"; slowest call 1.5 s (the time limit);
    - 100 cables / 80 jobs: slowest booking 0.03 s, slowest pick 0.10 s;
    - v1.5.0 full run still 35/35.
  - Found while building: the shuffle passes reseeded PHP's global random generator, which would have affected other code using `mt_rand()`. Removed.
- [ ] **T16: Docs + release**
  - Acceptance: the Readme bullet and OA docs mention the re-arranging; release v1.5.1 after merge.
  - Files: `Readme.md`, `assign.php` OA block

