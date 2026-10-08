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
- [ ] **T4: `assign.php` quantity**
  - Acceptance: `assetTypes_id` + `quantity` on a quantity type books up to N free cables (helper order) as `bound = 0`, inside a transaction. The response adds `assigned` (int). If fewer are free → books what is free and reports it. A per-tag assign on a quantity type → `bound = 1`. Without `quantity` → unchanged.
  - Verify: spec tests 1, 2; a legacy call with `assetTypes_id` only still books all of the type.
  - Files: `src/api/projects/assets/assign.php`
- [ ] **T5: `unassign.php` quantity**
  - Acceptance: `assetTypes_id` + `projects_id` + `quantity` removes N assignments, unbound first, never bound ones beyond what's needed (and bound ones only when explicitly asked by assignment id). Without `quantity` → unchanged.
  - Verify: book 10, pick 3, reduce by 8 → 7 unbound removed + 1 refused/reported; the finance totals match.
  - Files: `src/api/projects/assets/unassign.php`
- [ ] **T6: Search page UI**
  - Acceptance: quantity types show a number input (max = available) + Add / Remove, and "N booked" when a project is selected. Per-tag buttons are moved into the details dialog. Other types are unchanged.
  - Verify: book/reduce from the search page; available counts update; no console errors.
  - Files: `src/assets.php`, `src/assets.twig`
- [ ] **CP-A**: spec tests 1, 2, 7.

## Slice 3 — Picking
- [ ] **T7: Bind on scan**
  - Acceptance: `setStatusBarcode.php` calls `quantityBindScan()` before its `NOTASSIGNED` check, for quantity types only. Every row of the decision table works: exchange is silent and audit-logged on both projects (`BIND-ASSET`, `EXCHANGE-ASSET`), and the replacement prefers the scanned cable's storage location. The response adds `bound` / `exchangedWith`. Status and location then proceed as today.
  - Verify: spec tests 3–6; a legacy request on a non-quantity type gives an identical response.
  - Files: `src/api/projects/assets/setStatusBarcode.php`, `quantityBooking.php`
- [ ] **T8: Barcode Dispatch UI**
  - Acceptance: the log line shows "picked" / "(exchanged with Project B)". The board shows unbound rows as a "to pick: N" counter per type, not as draggable cards. After a bind, the board is marked stale like for new assets.
  - Verify: scan through a booking of 5 on the 320×450 viewport.
  - Files: `src/project/project_assetsBoard.twig`
- [ ] **CP-B**: spec tests 3–6 + a legacy `setStatusBarcode` call.
- [ ] **T9: Project data + asset list**
  - Acceptance: `data.php` adds per-type `totals.quantityBooking`, `totals.count`, `totals.picked` (additive). The asset list shows "N× Type (k picked)", bound rows with tags, unbound rows as "not picked yet" without a tag.
  - Verify: the project page with a mixed booking; mobile-shaped `data.php` output only gains fields.
  - Files: `src/api/projects/data.php`, `src/project/project_assets.twig`
- [ ] **T10: Manual "Pick tag…"**
  - Acceptance: on an unbound row, enter or select a tag → new `src/api/projects/assets/bind.php` (permission `ASSIGN_AND_UNASSIGN`) runs the same `quantityBindScan()` logic.
  - Verify: pick a free tag, a tag that is a placeholder elsewhere (exchange), and a tag that is bound elsewhere (error).
  - Files: `src/api/projects/assets/bind.php`, `src/project/project_assets.twig`

## Slice 4 — Display, bulk create, docs
- [ ] **T11: PDF / invoice grouping**
  - Acceptance: quantity types appear as one line "N× Type" with the total price. Bound tags are listed as today if the template lists tags; unbound rows show no tag.
  - Verify: generate the project PDF + invoice for a mixed booking.
  - Files: `src/project/pdf.twig` (+ invoice twig if separate)
- [ ] **T12: Bulk create**
  - Acceptance: a Quantity field (1–500) on the new-asset form. `newAssetFromType.php` with `quantity` creates N assets + N QR barcodes in one transaction, with auto tags. A custom tag is only allowed with quantity 1. The response adds `assets[]`. The success message links to `maintenance/barcodePrint.php?ids=…`.
  - Verify: spec test 10; quantity 1 behaves exactly as before; the print link shows N labels.
  - Files: `src/api/assets/newAssetFromType.php`, `src/newAsset.twig`
- [ ] **T13: Docs + full run**
  - Acceptance: a Fork Improvements bullet in `Readme.md`, and `@OA` docs updated for every new param/field.
  - Verify: the full spec test list 1–10 on the dev stack, results noted here.
  - Files: `Readme.md`, touched endpoints' OA blocks
- [ ] **CP-C**: review, then merge on the user's go-ahead.
