# Spec: Quantity Booking for Asset Types

Status: **APPROVED (2026-10-08).** Open questions resolved with the proposals below.

### Decisions (confirmed by the user)
| # | Decision |
|---|---|
| Q1 | **Option A**: reserve real assignments, bind them on scan. |
| Q2 | Exchange with another project's unbound placeholder happens **silently** (logged). The replacement cable for the other project is chosen with a **preference for the scanned cable's storage location** (see FR5). |
| Q3 | Turning the setting on keeps all existing assignments **bound**. Only new quantity bookings are unbound. |
| Q4 | **Bulk creation** of N assets is **in scope** (FR9). |

## Objective

Some asset types are interchangeable bulk items (e.g. 100× "XLR 10m"). For these, a project should book **a quantity** ("25× XLR 10m"), not specific tags. Which physical cables go out is decided **when they are picked**: the warehouse grabs 25 cables from the tray, scans them in Barcode Dispatch, and those become the project's cables. Nobody has to hunt for specific tags, and nobody has to rebook by hand.

Hard constraints from the user:
- Every physical item stays a real `assets` row with its own tag/barcode, location history and maintenance.
- Overbooking must stay impossible. The existing per-asset availability logic (clash query + blocks, `src/api/projects/assets/assign.php:68-81`) must stay the single source of truth.
- No breaking changes to the general booking system or to the mobile JSON API (only add fields).

### Verified facts about today's code
- An `assetsAssignments` row = one asset on one project. Availability = "no non-deleted assignment of this asset on an overlapping project whose status has `projectsStatuses_assetsReleased = 0`, and no BLOCK flag" (`assign.php:69-78`). The same check is copied in `swap.php`, `changeProjectDeliverDates.php`, `changeStatus.php` and `assets.php` (`countAvailable`).
- `assign.php` with `assetTypes_id` books **every** asset of that type (`assign.php:33`). The search page's "dolly" button uses this (`assets.twig:947`).
- `swap.php` already moves an assignment to another free asset of the **same type**, and corrects the finance cache for per-asset rate/value/mass overrides.
- Barcode Dispatch (`setStatusBarcode.php`) finds the assignment by the scanned asset. If there is none it returns `NOTASSIGNED`, and the board offers "Add asset to project?" (`project_assetsBoard.twig:729`).

---

## Implementation options

### Option A — Real reservations, bound when scanned **(CHOSEN)**
Booking "25×" creates **25 normal `assetsAssignments` rows** right away, on 25 cables that are free at that moment (auto-picked). Each row is marked **unbound** (`assetsAssignments_bound = 0`): a placeholder that holds a real, availability-checked slot. When a cable of that type is scanned in Barcode Dispatch, the scan **re-points one unbound row onto the scanned cable** (swap logic) and marks it bound.

- Availability, clashes, blocks, date changes, finance, invoices, calendars, mobile app: **unchanged**, because there are still exactly N real assignments. You can never book more than physically exist. This is the main reason for recommending it.
- The scan cases:
  | Scanned cable is… | Action |
  |---|---|
  | free for this project's dates | Swap one of this project's unbound rows onto it and bind it. The auto-picked cable becomes free again. |
  | an **unbound** placeholder on another overlapping project B | **Exchange** (silently, logged): the scanned cable goes to this project (bound). B's row is re-pointed to another cable that is free for B's dates, chosen by the replacement order in FR5. If none is free → refuse with a clear message. |
  | **bound** (picked) on another overlapping project | Real conflict → error, as today. |
  | already bound to this project | Normal status update, as today. |
  | free, but all of this project's rows are already bound | "25 of 25 already picked". Offer the existing "Add asset to project?" (one extra assignment). |
- The project shows the type as **"25× XLR 10m — 7 picked"**. Unbound rows hide their tags (the auto-picked tag means nothing).
- Cost: one new column, a quantity param on `assign.php`, a binding step in `setStatusBarcode.php`, and UI grouping. Assignment rows change their `assets_id` while unbound (this is already possible today via swap).

### Option B — Quantity reservation table
A new table `projectsQuantityBookings (projects_id, assetTypes_id, quantity)`. No assignment rows until scanning. Availability for these types = assets of the type − overlapping individual assignments − overlapping quantity reservations.

- Cleanest data model, and it matches the mental model exactly.
- **But every availability check has to learn the new table**: assign, swap, date changes, project status changes, search page counts, substitutions, calendar export, finance cache, invoices/PDF, mobile API. A new BLOCK flag or end date on one cable can silently make an existing reservation overbooked. This is the kind of deep change you want to avoid. **Not recommended.**

### Option C — Booking without a reservation (no new data)
Pick only at scan time. Booking just adds a note or target quantity, and the scan does a normal `assign.php`. Simple, but **nothing is reserved**: two projects can both plan 80 of 100 cables. That breaks the "no overbooking" requirement. **Rejected.**

### Option D — One "bulk" asset with a count
One asset row = 100 cables. Breaks per-cable barcodes, locations and maintenance, and every count-based query. **Rejected** (it contradicts your requirement that each cable is tracked).

---

## Functional requirements (Option A) — item n = FRn

1. **Asset type setting** `assetTypes_quantityBooking` (tinyint, default 0), editable in the asset type edit form (`editAssetType.php`) and shown on the asset type page.
2. **Assignment column** `assetsAssignments_bound` (tinyint, default 1). Existing rows and all non-quantity bookings are bound, so behaviour is unchanged.
3. **Book by quantity**: on the asset search page, a quantity type gets a number input + "Add" (max = `countAvailable`) instead of per-tag buttons. `assign.php` gets an optional `quantity` param, used together with `assetTypes_id`. Without it, it behaves exactly as today (all of the type). Partial success reports how many were booked.
4. **Reduce quantity**: removing from a quantity booking deletes **unbound rows first**. Removing a bound one is an explicit per-tag action.
5. **Bind on scan** in `setStatusBarcode.php`, following the table above. Done in one DB transaction, with the availability re-check inside it. The response gets additive fields: `bound: true`, plus `exchangedWith: {projects_id, projects_name}` when an exchange happened. The scanner log line reads e.g. *"XLR 10m A-0042 picked (exchanged with Project B)"*.
   **Replacement order** (when project B's placeholder needs a new cable): only cables of the same type that are free for B's dates and not blocked/ended. Among those, prefer in this order:
   1. same `assets_storageLocation` as the scanned cable (it came from that tray, so the other cables there are the natural substitutes)
   2. the cable this project just released (the same cable it had before)
   3. any other free cable, lowest tag first (deterministic).
   The same order (minus rule 2, with "the type's most common storage location" as the tray) is used for the initial auto-pick when booking, so placeholders sit in one tray where possible.
6. **Project views** (asset list, Asset Dispatch board, PDF/export): quantity types are grouped as "N× Type (k picked)". Bound rows show tags as now, unbound rows show no tag. The board only lets **bound** rows be moved between statuses; unbound ones count as "to pick".
7. **Manual bind** without a scanner: on the project asset list, "Pick tag…" on an unbound row → reuses swap + bind.
8. **Fork Improvements** bullet in `Readme.md`.

9. **Bulk create**: the "new asset" form (`src/newAsset.twig`) gets a **Quantity** field (default 1, max 500), for all asset types. `newAssetFromType.php` gets an optional `quantity`. Each created asset gets an auto-generated tag (`generateNewTag()`) and its own QR barcode (as today). A custom tag is only allowed when quantity = 1. Storage location, notes, groups and definable fields are copied to every asset. All rows are inserted in one transaction (all or nothing). The response keeps today's fields (first asset) and adds `assets: [{assets_id, assets_tag}, …]`. After success the user is offered the existing barcode label printing for the new tags, if that flow can take a list (to verify during planning).

Explicitly out of scope unless you want them: linked assets on quantity types (proposal: not allowed while the setting is on); sub-business (`instance_ids`) cross-booking beyond what assign already does.

## Tech Stack
PHP 8.3, MysqliDb (`$DBLIB`), Phinx migrations, Twig, jQuery/Bootstrap 4 (CDN). moneyphp for finance adjustments (reuse `projectFinanceCacher` exactly as `swap.php` does).

## Commands
```bash
composer update
php vendor/bin/phinx migrate               # applies the two new columns
php vendor/bin/phinx rollback              # reversible change() migration
php -l src/api/projects/assets/assign.php  # syntax check per touched file
# Manual testing: dev Docker stack on http://localhost:8090
```

## Project Structure (touched)
```
db/migrations/YYYYMMDDHHmmss_QuantityBooking.php   → two columns
src/api/assets/editAssetType.php                    → save the setting
src/api/projects/assets/assign.php                  → optional quantity param, sets bound=0
src/api/projects/assets/unassign.php                → remove unbound first
src/api/projects/assets/setStatusBarcode.php        → bind / exchange on scan
src/api/assets/newAssetFromType.php                 → optional quantity (bulk create)
src/newAsset.twig                                   → Quantity field
src/common/libs/bCMS/ (new helper)                  → shared "free asset of type for dates" + bind/exchange logic
src/assets.twig, src/project/*.twig                 → quantity input, grouping, "k picked"
Readme.md                                           → Fork Improvements bullet
```

## Code Style
Match the existing endpoints: `$DBLIB` builder calls scoped by `instances_id`, `*_deleted = 0` on every query, `finish()` for responses, `$bCMS->auditLog()` for every bind/exchange (e.g. `"BIND-ASSET"`, `"EXCHANGE-ASSET"`). The availability query moves into **one** helper, used by the new code (existing copies are left as they are to limit blast radius).

## Testing Strategy
There is no automated test suite. Verification = a written manual test script on the dev stack, covering at least:
1. Book 10 of 12 → 10 unbound rows, search shows 2 available, the finance totals match 10 cables.
2. Second overlapping project books 3 → refused/partial (only 2 free). **No overbooking.**
3. Scan a free cable → binds, the auto-picked cable becomes free, totals stay correct (incl. a cable with a per-asset rate override).
4. Scan a cable that is an unbound placeholder on project B → exchange, B still has its count, B's replacement comes from the scanned cable's storage location when one is free there, audit log on both.
5. Scan a cable that is bound on B → error, nothing changes.
6. Scan an 11th cable when 10/10 are bound → "Add asset?" offer.
7. Non-quantity types and a mobile-style call to `assign.php`/`setStatusBarcode.php` without the new params → identical behaviour to before.
8. Change project dates with unbound rows → the existing clash check still applies.
9. Turn the setting on for a type with existing bookings → those stay bound, nothing moves.
10. Bulk create 20 assets → 20 assets, 20 unique tags, 20 barcodes. A failure midway leaves no partial set. Quantity 1 with a custom tag works exactly as before.

## Boundaries
- **Always:** scope by instance, soft-delete filters, run bind/exchange in a transaction with the availability re-checked inside it, keep API changes additive, add the Readme bullet.
- **Ask first:** changing the existing availability copies in other files, changing PDF/invoice layout, making quantity types incompatible with existing features (linked assets, groups).
- **Never:** let the number of assignments differ from the booked quantity, write location-less scan rows (see SPEC.md D2), or break existing request/response shapes.

## Success Criteria
- A quantity type can be booked as "N×" in one action, and is never booked beyond physical availability.
- Picking N arbitrary cables of that type in Barcode Dispatch binds them with no manual rebooking.
- Non-quantity types and all existing API calls behave byte-for-byte as before.

## Open Questions
1. Should the board / PDF / invoice show unbound rows at all, or only "N× Type (k picked)"? *Proposal: grouped line on the PDF/invoice; on the board unbound rows count as "to pick" and are not draggable.*
2. Should the asset search hide per-tag "add" buttons for quantity types entirely, or keep them for the rare "I want exactly this one" case? *Proposal: keep them, collapsed under the details dialog; a tag added this way is booked bound.*
