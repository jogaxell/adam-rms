import type { Page } from "@playwright/test";
import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { assign, newProject } from "../projects";
import { dbQuery, expect, test, type Tenant } from "../tenants";

/**
 * Scanning an asset's barcode records where it was scanned: at a location's barcode, next to another asset, or at a
 * place typed in by hand (api/assets/barcodes/search.php, used by the asset page, and setStatusBarcode.php, used by
 * Barcode Dispatch).
 */

type Scan = { locationsBarcodes_id: number | null; location_assets_id: number | null };
function latestScan(t: Tenant): Scan | undefined {
  return dbQuery<Scan>(
    "SELECT locationsBarcodes_id, location_assets_id FROM assetsBarcodesScans WHERE assetsBarcodes_id = ? ORDER BY assetsBarcodesScans_id DESC LIMIT 1",
    [t.barcodeId],
  )[0];
}
function scanCount(t: Tenant) {
  return dbQuery<{ n: number }>("SELECT COUNT(*) n FROM assetsBarcodesScans WHERE assetsBarcodes_id = ?", [t.barcodeId])[0].n;
}

for (const type of ["UNKNOWN", "CODE_128"]) {
  test.describe(`scanning an asset's barcode (type ${type})`, () => {
    // Seen in production: the barcode page sent "undefined" after the location was changed mid-scan, and the scan
    // failed with "Incorrect integer value: 'undefined' for column 'locationsBarcodes_id'"
    test("with a location that isn't an ID still records the scan, without a location", async ({ asA, tenants: { a } }) => {
      const before = scanCount(a);
      const response = await asA.api("/api/assets/barcodes/search.php", {
        instances_id: a.instanceId, text: a.barcodeValue, type, scanned: "true", locationType: "barcode", location: "undefined",
      });
      expect(response.json, response.body.slice(0, 300)).toMatchObject({ result: true, response: { asset: { assets_id: a.assetId } } });
      expect(scanCount(a)).toBe(before + 1);
      expect(latestScan(a)).toEqual({ locationsBarcodes_id: null, location_assets_id: null });
    });

    test("records a location barcode or asset of the business, but not another business's", async ({ asA, tenants: { a, b } }) => {
      const scan = (locationType: string, location: number) => asA.api("/api/assets/barcodes/search.php", {
        instances_id: a.instanceId, text: a.barcodeValue, type, scanned: "true", locationType, location,
      });
      await scan("barcode", a.locationBarcodeId);
      expect(latestScan(a)).toEqual({ locationsBarcodes_id: a.locationBarcodeId, location_assets_id: null });
      await scan("barcode", b.locationBarcodeId);
      expect(latestScan(a)).toEqual({ locationsBarcodes_id: null, location_assets_id: null });

      await scan("asset", a.spareAssetId);
      expect(latestScan(a)).toEqual({ locationsBarcodes_id: null, location_assets_id: a.spareAssetId });
      await scan("asset", b.spareAssetId);
      expect(latestScan(a)).toEqual({ locationsBarcodes_id: null, location_assets_id: null });
    });
  });
}

/** Feeds a scan to Barcode Dispatch the way the scanner widget does */
async function scanOnPage(page: Page, value: string) {
  await page.evaluate((v) => (window as unknown as { dispatchBarcodeScanned: (value: string, type: string, done: () => void) => void })
    .dispatchBarcodeScanned(v, "UNKNOWN", () => {}), value);
}

// Fork: the Barcode Scanner page is a read-only lookup, and recording where assets are scanned moved to Barcode
// Dispatch on the project's asset board, which takes a location barcode scanned at any point as the new location.
test("in Barcode Dispatch, scanning a location barcode mid-way records the new location", async ({ page, asA, tenants: { a, password } }) => {
  dbQuery("INSERT INTO assetsAssignmentsStatus (instances_id, assetsAssignmentsStatus_name, assetsAssignmentsStatus_order, assetsAssignmentsStatus_deleted) VALUES (?, 'E2E dispatch status', 1, 0)", [a.instanceId]);
  let project = 0;
  try {
    project = await newProject(asA, a, a.users.full.id, { start: "2037-01-05 09:00:00", end: "2037-01-06 18:00:00" });
    await assign(asA, a, project, a.assetId);
    await cacheCdn(page.context());
    await login(page, a.users.full.email, password);
    await page.goto(`/project/?id=${project}`);
    await page.locator('a[href="#assets-board"]').click();
    await page.locator('[data-target="#barcodeDispatchModal"]').click();
    await expect(page.locator("#barcodeDispatchModal")).toBeVisible();

    const [{ id: status }] = dbQuery<{ id: number }>("SELECT MAX(assetsAssignmentsStatus_id) id FROM assetsAssignmentsStatus WHERE instances_id = ? AND assetsAssignmentsStatus_name = 'E2E dispatch status'", [a.instanceId]);
    const statusOf = () => dbQuery<{ s: number }>("SELECT assetsAssignmentsStatus_id s FROM assetsAssignments WHERE projects_id = ? AND assets_id = ? AND assetsAssignments_deleted = 0", [project, a.assetId])[0].s;

    await page.locator("#barcodeDispatchModal .tab-pane.active .assetStatusSelectorBarcodeDispatch").selectOption(String(status));

    // With no location chosen, scanning an asset sets its status but records no scan
    const before = scanCount(a);
    await scanOnPage(page, a.barcodeValue);
    await expect.poll(statusOf).toBe(status);
    expect(scanCount(a)).toBe(before);

    // Scanning the location barcode mid-way makes it the location for the scans that follow
    await scanOnPage(page, a.locationBarcodeValue);
    await expect(page.locator("#barcodeDispatchLocation")).toHaveValue(`barcode:${a.locationBarcodeId}`);
    await scanOnPage(page, a.barcodeValue);
    await expect.poll(() => scanCount(a)).toBe(before + 1);
    expect(latestScan(a)).toEqual({ locationsBarcodes_id: a.locationBarcodeId, location_assets_id: null });
  } finally {
    // Free the asset again, as it can't be booked twice for the same dates
    dbQuery("UPDATE assetsAssignments SET assetsAssignments_deleted = 1 WHERE projects_id = ?", [project]);
    dbQuery("UPDATE assetsAssignmentsStatus SET assetsAssignmentsStatus_deleted = 1 WHERE instances_id = ? AND assetsAssignmentsStatus_name = 'E2E dispatch status'", [a.instanceId]);
  }
});
