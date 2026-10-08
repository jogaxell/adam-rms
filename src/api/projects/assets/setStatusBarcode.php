<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/quantityBooking.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS") or !isset($_POST['projects_id']) or !isset($_POST['assetsAssignments_status']) or !isset($_POST['text']) or strlen($_POST['text']) < 1) finish(false);

$hasType = isset($_POST['type']) && strlen($_POST['type']) > 0 && $_POST['type'] !== 'UNKNOWN';
$locationType = strtolower($_POST['locationType'] ?? '');
// recordScan=location: only record a scan when a location is given, and only once the status has been set.
// Without it the legacy behaviour applies: every scan is recorded up front, with or without a location.
$recordLocationOnly = (isset($_POST['recordScan']) && $_POST['recordScan'] === "location");

//See if Barcode is in database - scope to current instance via assets join
$DBLIB->where("assetsBarcodes.assetsBarcodes_value", $_POST['text']);
if ($hasType) $DBLIB->where("assetsBarcodes.assetsBarcodes_type", $_POST['type']);
$DBLIB->where("assetsBarcodes.assetsBarcodes_deleted", 0);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->join("assets", "assets.assets_id=assetsBarcodes.assets_id", "LEFT");
$barcode = $DBLIB->getone("assetsBarcodes", ["assetsBarcodes.assets_id", "assetsBarcodes.assetsBarcodes_id"]);
if (!$barcode or $barcode['assets_id'] == null) {
    // Not an asset - tell the caller if it's one of this business's location barcodes, so a scanner can switch location
    $DBLIB->where("locationsBarcodes.locationsBarcodes_value", $_POST['text']);
    if ($hasType) $DBLIB->where("locationsBarcodes.locationsBarcodes_type", $_POST['type']);
    $DBLIB->where("locationsBarcodes.locationsBarcodes_deleted", 0);
    $DBLIB->where("locations.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("locations.locations_deleted", 0);
    $DBLIB->join("locations", "locations.locations_id=locationsBarcodes.locations_id", "LEFT");
    $location = $DBLIB->getone("locationsBarcodes", ["locations.locations_id", "locationsBarcodes.locationsBarcodes_id", "locations.locations_name", "locations.locations_archived"]);
    if ($location) finish(false, ["message" => "This is the barcode of the location " . $location['locations_name'], "code" => "ISLOCATION", "location" => $location]);
    finish(false, ["message" => "Barcode not found", "code" => "NOTFOUND"]);
}

if (!$recordLocationOnly) {
    $scan = [
        "assetsBarcodes_id" => $barcode['assetsBarcodes_id'],
        "users_userid" => $AUTH->data['users_userid'],
        "assetsBarcodesScans_timestamp" => date('Y-m-d H:i:s'),
        "locationsBarcodes_id" => ($locationType == "barcode" && isset($_POST['location']) ? $_POST['location'] : null),
        "location_assets_id" => ($locationType == "asset" && isset($_POST['location']) ? $_POST['location'] : null),
        "assetsBarcodes_customLocation" => ($locationType == "custom" && isset($_POST['location']) ? $_POST['location'] : null)
    ];
    $DBLIB->insert("assetsBarcodesScans", $scan);
}

$DBLIB->where("assets.assets_id", $barcode['assets_id']);
$DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$asset = $DBLIB->getone("assets", ["assets.assets_id", "assets.assets_tag", "assets.assets_storageLocation", "assetTypes.assetTypes_name", "assetTypes.assetTypes_quantityBooking"]);

// Validate that the requested status belongs to the current instance and is not deleted
$DBLIB->where("assetsAssignmentsStatus_id", $_POST['assetsAssignments_status']);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assetsAssignmentsStatus_deleted", 0);
$status = $DBLIB->getone("assetsAssignmentsStatus", ["assetsAssignmentsStatus_id", "assetsAssignmentsStatus_name"]);

// Quantity booking: an asset of a type booked by quantity is picked for this project before its status is set,
// taking the place of one of the project's unpicked placeholders (and exchanging it with another project's if needed)
$quantityBind = ["code" => "NOTQUANTITY"];
if ($asset['assetTypes_quantityBooking'] == 1) {
    if (!$status) finish(false, ["message" => "Status not found", "code" => "STATUSNOTFOUND"]); //Don't pick an asset for a status that can't be set
    $quantityBind = quantityBindForProject($_POST['projects_id'], $barcode['assets_id']);
    if (in_array($quantityBind['code'], ["CONFLICT", "NOREPLACEMENT", "ERROR"])) finish(false, ["message" => $quantityBind['message'], "code" => ($quantityBind['code'] == "ERROR" ? "PICKFAILED" : $quantityBind['code']), "assets_id" => $barcode['assets_id'], "assets_tag" => $asset['assets_tag'], "assetTypes_name" => $asset['assetTypes_name']]);
}

$DBLIB->where("assetsAssignments.assets_id", $barcode['assets_id']);
$DBLIB->where("assetsAssignments.projects_id", $_POST['projects_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$currentAssignment = $DBLIB->getOne("assetsAssignments", ["assetsAssignments.assetsAssignments_id", "assetsAssignmentsStatus_id", "projects.projects_name", "projects.locations_id"]);

if (!$currentAssignment) {
    finish(false, ["message" => ($quantityBind['code'] == "ALLPICKED" ? $quantityBind['message'] : "Asset not assigned to project"), "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id'], "assets_tag" => $asset['assets_tag'], "assetTypes_name" => $asset['assetTypes_name'], "allPicked" => ($quantityBind['code'] == "ALLPICKED")]);
}

// Work out where the asset is going before touching its status, so a bad location doesn't leave a half-done scan
$scanLocation = null; // ["locationsBarcodes_id" => int, "locations_name" => string]
$locationSkipped = null;
function locationWithBarcode($locationsId) {
    global $DBLIB, $AUTH;
    $DBLIB->where("locations.locations_id", $locationsId);
    $DBLIB->where("locations.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("locations.locations_deleted", 0);
    $DBLIB->where("locationsBarcodes.locationsBarcodes_deleted", 0);
    $DBLIB->join("locations", "locations.locations_id=locationsBarcodes.locations_id", "LEFT");
    $DBLIB->orderBy("locationsBarcodes.locationsBarcodes_id", "ASC");
    return $DBLIB->getone("locationsBarcodes", ["locationsBarcodes.locationsBarcodes_id", "locations.locations_name"]);
}
if ($recordLocationOnly and $locationType != "") {
    if ($locationType == "barcode") {
        if (!isset($_POST['location']) or !is_numeric($_POST['location'])) finish(false, ["message" => "No location given", "code" => "INVALIDLOCATION"]);
        $DBLIB->where("locationsBarcodes.locationsBarcodes_id", (int)$_POST['location']);
        $DBLIB->where("locationsBarcodes.locationsBarcodes_deleted", 0);
        $DBLIB->where("locations.instances_id", $AUTH->data['instance']['instances_id']);
        $DBLIB->where("locations.locations_deleted", 0);
        $DBLIB->join("locations", "locations.locations_id=locationsBarcodes.locations_id", "LEFT");
        $scanLocation = $DBLIB->getone("locationsBarcodes", ["locationsBarcodes.locationsBarcodes_id", "locations.locations_name", "locations.locations_archived"]);
        if (!$scanLocation) finish(false, ["message" => "Location not found", "code" => "INVALIDLOCATION"]);
        if ($scanLocation['locations_archived'] == 1) finish(false, ["message" => "Location " . $scanLocation['locations_name'] . " is archived", "code" => "INVALIDLOCATION"]);
    } elseif ($locationType == "project") {
        if (!$currentAssignment['locations_id']) finish(false, ["message" => "This project has no venue assigned", "code" => "NOVENUEASSIGNED"]);
        $scanLocation = locationWithBarcode($currentAssignment['locations_id']);
        if (!$scanLocation) finish(false, ["message" => "The project venue has no barcode", "code" => "INVALIDLOCATION"]);
    } elseif ($locationType == "storage") {
        if ($asset['assets_storageLocation']) $scanLocation = locationWithBarcode($asset['assets_storageLocation']);
        if (!$scanLocation) $locationSkipped = "NOSTORAGELOCATION";
    } else finish(false, ["message" => "Unknown location type", "code" => "INVALIDLOCATION"]);
}

// Called once the status is in place: record the location (if any) and report back
function dispatchFinished() {
    global $DBLIB, $AUTH, $bCMS, $barcode, $asset, $status, $scanLocation, $locationSkipped, $currentAssignment, $quantityBind;
    $bCMS->auditLog("EDIT-STATUS", "assetsAssignments", "set to " . $_POST['assetsAssignments_status'] . " by barcode scan", $AUTH->data['users_userid'], null, $_POST['projects_id']);
    if ($scanLocation) {
        $DBLIB->insert("assetsBarcodesScans", [
            "assetsBarcodes_id" => $barcode['assetsBarcodes_id'],
            "users_userid" => $AUTH->data['users_userid'],
            "assetsBarcodesScans_timestamp" => date('Y-m-d H:i:s'),
            "locationsBarcodes_id" => $scanLocation['locationsBarcodes_id'],
            "assetsBarcodesScans_barcodeWasScanned" => (isset($_POST['scanned']) && $_POST['scanned'] == "true" ? 1 : 0),
            //Same wording as Location Dispatch, so the asset's location history shows where the move came from
            "assetsBarcodesScans_validation" => "Dispatched from Project " . $currentAssignment['projects_name'],
        ]);
    }
    finish(true, null, [
        "assets_id" => $barcode['assets_id'],
        "assets_tag" => $asset['assets_tag'],
        "assetTypes_name" => $asset['assetTypes_name'],
        "assetsAssignmentsStatus_name" => ($status ? $status['assetsAssignmentsStatus_name'] : null),
        "location_name" => ($scanLocation ? $scanLocation['locations_name'] : null),
        "locationSkipped" => $locationSkipped,
        "bound" => ($quantityBind['code'] == "BOUND"), //This scan picked the asset for a quantity booking
        "exchangedWith" => ($quantityBind['exchangedWith'] ?? []), //Projects whose placeholder it was - they got another asset of the type
    ]);
}

// If the assignment already has the requested status, treat this as success (no-op)
if ((int)$currentAssignment['assetsAssignmentsStatus_id'] === (int)$_POST['assetsAssignments_status']) {
    dispatchFinished();
}

if (!$status or $status['assetsAssignmentsStatus_id'] == null) finish(false, ["message" => "Status not found", "code" => "STATUSNOTFOUND"]);

// Update using the assignment ID while also asserting the originally matched
// asset/project identity still holds, so a concurrent swap cannot cause this
// scan to update the status of a replacement asset on the same assignment row.
$DBLIB->where("assetsAssignments_id", $currentAssignment['assetsAssignments_id']);
$DBLIB->where("assets_id", $barcode['assets_id']);
$DBLIB->where("projects_id", $_POST['projects_id']);
$DBLIB->where("assetsAssignments_deleted", 0);
$assignment = $DBLIB->update("assetsAssignments", ["assetsAssignmentsStatus_id" => $status['assetsAssignmentsStatus_id']], 1);

if (!$assignment) {
    finish(false, ["message" => "Asset not assigned to project", "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id']]);
}

// MySQL returns 0 affected rows when the row exists but the value is unchanged
// (e.g. a concurrent scan already wrote this status). Re-read to distinguish a
// genuine no-match from an idempotent write, avoiding a false NOTASSIGNED error.
if ($DBLIB->count != 1) {
    $DBLIB->where("assetsAssignments_id", $currentAssignment['assetsAssignments_id']);
    $DBLIB->where("assets_id", $barcode['assets_id']);
    $DBLIB->where("projects_id", $_POST['projects_id']);
    $DBLIB->where("assetsAssignments_deleted", 0);
    $DBLIB->where("assetsAssignmentsStatus_id", $status['assetsAssignmentsStatus_id']);
    $confirm = $DBLIB->getone("assetsAssignments", ["assetsAssignments_id"]);
    if (!$confirm) finish(false, ["message" => "Asset not assigned to project", "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id']]);
}

dispatchFinished();

/** @OA\Post(
 *     path="/projects/assets/setStatusBarcode.php",
 *     summary="Set Asset Assignment Status using Barcode",
 *     description="Set the status for an asset assignment using a barcode
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS
If the barcode belongs to a location rather than an asset, fails with code ISLOCATION and returns the location.
For an asset type booked by quantity, the scanned asset is picked for the project first. Fails with CONFLICT (picked for another project, blocked or archived) or NOREPLACEMENT (an unpicked placeholder elsewhere with no free substitute). NOTASSIGNED has allPicked=true when every booked asset of the type is already picked.
",
 *     operationId="setAssetAssignmentStatusBarcode",
 *     tags={"project_assets"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 @OA\Property(
 *                     property="result",
 *                     type="boolean",
 *                     description="Whether the request was successful",
 *                 ),
 *                 @OA\Property(
 *                     property="response",
 *                     type="object",
 *                     description="assets_id, assets_tag, assetTypes_name, assetsAssignmentsStatus_name, location_name (the location recorded, or null), locationSkipped (NOSTORAGELOCATION when locationType=storage and the asset has no storage location, otherwise null), bound (true when this scan picked the asset for a quantity booking) and exchangedWith (projects_id + projects_name of each project whose unpicked placeholder it was - they got another asset)",
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="text",
 *         in="query",
 *         description="barcode value",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="type",
 *         in="query",
 *         description="barcode type (optional - if not provided or set to UNKNOWN, searches by value only)",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="locationType",
 *         in="query",
 *         description="location type - 'barcode', 'asset' or 'custom'. With recordScan=location: 'barcode' (a locationsBarcodes_id in location), 'project' (the project's venue) or 'storage' (each asset's storage location)",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="location",
 *         in="query",
 *         description="a locationsBarcodes_id, assets_id or custom string, depending on locationType",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="recordScan",
 *         in="query",
 *         description="optional - 'location' to only record a scan when a location is given, after the status has been set, with the note 'Dispatched from Project {name}'. If omitted every scan is recorded, with or without a location.",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="scanned",
 *         in="query",
 *         description="optional - 'true' if the barcode was physically scanned (used with recordScan=location)",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetsAssignments_status",
 *         in="query",
 *         description="Status ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
