<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

// Read-only barcode lookup for the Barcode Scanner page: never records a scan, so looking something up
// can't change where an asset is believed to be.
if (!$AUTH->instancePermissionCheck("ASSETS:ASSET_BARCODES:VIEW")) finish(false, ["code" => "AUTH", "message" => "Sorry - you can't look up barcodes"]);

$instanceId = $AUTH->data['instance']['instances_id'];
$hasType = isset($_POST['type']) && strlen($_POST['type']) > 0 && $_POST['type'] !== 'UNKNOWN';
const LOOKUP_LIST_LIMIT = 200;

function lookupLocationPath($locationsId) {
    global $DBLIB, $instanceId;
    $path = [];
    $seen = [];
    while ($locationsId and !isset($seen[$locationsId]) and count($path) < 10) {
        $seen[$locationsId] = true;
        $DBLIB->where("locations_id", $locationsId);
        $DBLIB->where("instances_id", $instanceId);
        $DBLIB->where("locations_deleted", 0);
        $location = $DBLIB->getone("locations", ["locations_id", "locations_name", "locations_subOf"]);
        if (!$location) break;
        array_unshift($path, $location['locations_name']);
        $locationsId = $location['locations_subOf'];
    }
    return $path;
}

function lookupAsset($assetsId) {
    global $DBLIB, $AUTH, $CONFIG, $instanceId;
    $DBLIB->where("assets.assets_id", $assetsId);
    $DBLIB->where("assets.instances_id", $instanceId);
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("manufacturers", "manufacturers.manufacturers_id=assetTypes.manufacturers_id", "LEFT");
    $DBLIB->join("assetCategories", "assetCategories.assetCategories_id=assetTypes.assetCategories_id", "LEFT");
    $DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
    $asset = $DBLIB->getone("assets", ["assets.*", "assetTypes.assetTypes_name", "assetTypes.assetTypes_definableFields", "manufacturers.manufacturers_name", "assetCategories.assetCategories_name", "assetCategoriesGroups.assetCategoriesGroups_name"]);
    if (!$asset) return false;

    $result = [
        "assets_id" => $asset['assets_id'],
        "assets_tag" => $asset['assets_tag'],
        "assetTypes_id" => $asset['assetTypes_id'],
        "assetTypes_name" => $asset['assetTypes_name'],
        "manufacturers_name" => $asset['manufacturers_name'],
        "assetCategories_name" => $asset['assetCategories_name'],
        "assetCategoriesGroups_name" => $asset['assetCategoriesGroups_name'],
        "assets_notes" => $asset['assets_notes'],
        "archived" => ($asset['assets_endDate'] !== null and strtotime($asset['assets_endDate']) < time()),
        "url" => $CONFIG['ROOTURL'] . "/asset.php?id=" . $asset['assetTypes_id'] . "&asset=" . $asset['assets_id'],
    ];

    // Definable fields: the asset type names them, the asset holds the values. Only filled-in ones are useful here.
    $result['fields'] = [];
    foreach (explode(",", (string)$asset['assetTypes_definableFields']) as $index => $fieldName) {
        $value = $asset['asset_definableFields_' . ($index + 1)] ?? null;
        if (strlen(trim($fieldName)) > 0 and $value !== null and strlen(trim($value)) > 0) $result['fields'][] = ["name" => $fieldName, "value" => $value];
    }

    // Where it is (latest scan) and where it lives (storage location)
    $result['storageLocation'] = $asset['assets_storageLocation'] ? lookupLocationPath($asset['assets_storageLocation']) : [];
    $latestScan = assetLatestScan($asset['assets_id']);
    if ($latestScan) {
        if ($latestScan['locations_id']) $where = implode(" › ", lookupLocationPath($latestScan['locations_id'])) ?: $latestScan['locations_name'];
        elseif ($latestScan['location_assets_id']) $where = "Inside " . $latestScan['assetTypes_name'] . " (" . $latestScan['assets_tag'] . ")";
        elseif ($latestScan['assetsBarcodes_customLocation']) $where = $latestScan['assetsBarcodes_customLocation'];
        else $where = null;
        $result['lastScan'] = [
            "location" => $where,
            "timestamp" => $latestScan['assetsBarcodesScans_timestamp'],
            "user" => trim($latestScan['users_name1'] . " " . $latestScan['users_name2']),
            "note" => $latestScan['assetsBarcodesScans_validation'],
        ];
    } else $result['lastScan'] = null;

    // Flags and blocks are a warning anyone handling the asset needs to see
    $flagsBlocks = assetFlagsAndBlocks($asset['assets_id']);
    $result['blocks'] = [];
    $result['flags'] = [];
    foreach (["BLOCK" => "blocks", "FLAG" => "flags"] as $type => $key) {
        foreach ($flagsBlocks[$type] as $job) $result[$key][] = ["maintenanceJobs_id" => $job['maintenanceJobs_id'], "title" => $job['maintenanceJobs_title'], "status" => $job['maintenanceJobsStatuses_name']];
    }

    if ($AUTH->instancePermissionCheck("MAINTENANCE_JOBS:VIEW")) {
        $DBLIB->where("maintenanceJobs.maintenanceJobs_deleted", 0);
        $DBLIB->where("maintenanceJobs.instances_id", $instanceId);
        $DBLIB->where("(FIND_IN_SET(" . (int)$asset['assets_id'] . ", maintenanceJobs.maintenanceJobs_assets) > 0)");
        $DBLIB->where("(maintenanceJobsStatuses.maintenanceJobsStatuses_showJobInMainList = 1 OR maintenanceJobs.maintenanceJobsStatuses_id IS NULL)");
        $DBLIB->join("maintenanceJobsStatuses", "maintenanceJobs.maintenanceJobsStatuses_id=maintenanceJobsStatuses.maintenanceJobsStatuses_id", "LEFT");
        $DBLIB->orderBy("maintenanceJobs.maintenanceJobs_priority", "ASC");
        $DBLIB->orderBy("maintenanceJobs.maintenanceJobs_timestamp_due", "ASC");
        $result['maintenance'] = $DBLIB->get("maintenanceJobs", 20, ["maintenanceJobs.maintenanceJobs_id", "maintenanceJobs.maintenanceJobs_title", "maintenanceJobs.maintenanceJobs_timestamp_due", "maintenanceJobsStatuses.maintenanceJobsStatuses_name"]);
    }

    // Current and upcoming projects, with the dispatch status the asset has on each - plus the most recent finished
    // one, because an asset is often still out on (or just back from) a job that has ended
    if ($AUTH->instancePermissionCheck("PROJECTS:VIEW")) {
        $projectFields = ["projects.projects_id", "projects.projects_name", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour", "projectsStatuses.projectsStatuses_backgroundColour", "assetsAssignmentsStatus.assetsAssignmentsStatus_name"];
        $projectQuery = function () use ($DBLIB, $asset) {
            $DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
            $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
            $DBLIB->where("projects.projects_deleted", 0);
            $DBLIB->where("projects.projects_archived", 0);
            $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
            $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
            $DBLIB->join("assetsAssignmentsStatus", "assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id", "LEFT");
        };
        $projectQuery();
        $DBLIB->where("projects.projects_dates_deliver_end", date("Y-m-d H:i:s"), "<");
        $DBLIB->orderBy("projects.projects_dates_deliver_end", "DESC");
        $lastProject = $DBLIB->getone("assetsAssignments", $projectFields);
        $projectQuery();
        $DBLIB->where("projects.projects_dates_deliver_end", date("Y-m-d H:i:s"), ">=");
        $DBLIB->orderBy("projects.projects_dates_deliver_start", "ASC");
        $result['projects'] = [];
        if ($lastProject) $result['projects'][] = array_merge($lastProject, ["ended" => true]);
        foreach ($DBLIB->get("assetsAssignments", 20, $projectFields) as $project) $result['projects'][] = array_merge($project, ["ended" => false]);
    }
    return $result;
}

// Adds "whereabouts" to each asset in the list: is it at $homeLocationId (or one of its sub-locations) right now,
// somewhere else, or has it never been scanned? Uses the latest scan, like the rest of the app.
function lookupAddWhereabouts(&$assets, $homeLocationId) {
    global $DBLIB, $instanceId;
    if (!$assets) return;

    // This location and everything below it counts as "here"
    $DBLIB->where("instances_id", $instanceId);
    $DBLIB->where("locations_deleted", 0);
    $childrenOf = [];
    foreach ($DBLIB->get("locations", null, ["locations_id", "locations_subOf"]) as $location) {
        if ($location['locations_subOf']) $childrenOf[$location['locations_subOf']][] = $location['locations_id'];
    }
    $hereIds = [$homeLocationId => true];
    $toVisit = [$homeLocationId];
    while ($toVisit) {
        foreach ($childrenOf[array_pop($toVisit)] ?? [] as $childId) {
            if (isset($hereIds[$childId])) continue;
            $hereIds[$childId] = true;
            $toVisit[] = $childId;
        }
    }

    $assetIds = array_map('intval', array_column($assets, 'assets_id'));
    $placeholders = implode(",", array_fill(0, count($assetIds), "?"));
    $scans = $DBLIB->rawQuery("SELECT assetsBarcodes.assets_id, assetsBarcodesScans.assetsBarcodes_customLocation, assetsBarcodesScans.location_assets_id,
            locations.locations_id, locations.locations_name, containerAssets.assets_tag AS container_tag, containerTypes.assetTypes_name AS container_typeName
        FROM assetsBarcodesScans
        JOIN assetsBarcodes ON assetsBarcodes.assetsBarcodes_id = assetsBarcodesScans.assetsBarcodes_id
        JOIN (
            SELECT latestBarcodes.assets_id, MAX(latestScans.assetsBarcodesScans_timestamp) AS latestTimestamp
            FROM assetsBarcodesScans AS latestScans
            JOIN assetsBarcodes AS latestBarcodes ON latestBarcodes.assetsBarcodes_id = latestScans.assetsBarcodes_id
            WHERE latestBarcodes.assetsBarcodes_deleted = 0 AND latestBarcodes.assets_id IN (" . $placeholders . ")
            GROUP BY latestBarcodes.assets_id
        ) AS latest ON latest.assets_id = assetsBarcodes.assets_id AND latest.latestTimestamp = assetsBarcodesScans.assetsBarcodesScans_timestamp
        LEFT JOIN locationsBarcodes ON locationsBarcodes.locationsBarcodes_id = assetsBarcodesScans.locationsBarcodes_id
        LEFT JOIN locations ON locations.locations_id = locationsBarcodes.locations_id
        LEFT JOIN assets AS containerAssets ON containerAssets.assets_id = assetsBarcodesScans.location_assets_id
        LEFT JOIN assetTypes AS containerTypes ON containerTypes.assetTypes_id = containerAssets.assetTypes_id
        WHERE assetsBarcodes.assetsBarcodes_deleted = 0
        ORDER BY assetsBarcodesScans.assetsBarcodesScans_id DESC", $assetIds);
    $latestByAsset = [];
    foreach ($scans as $scan) {
        if (!isset($latestByAsset[$scan['assets_id']])) $latestByAsset[$scan['assets_id']] = $scan; // same timestamp twice: newest row wins
    }

    foreach ($assets as &$asset) {
        $scan = $latestByAsset[$asset['assets_id']] ?? null;
        if (!$scan) {
            $asset['whereabouts'] = ["state" => "noscan", "location" => null];
        } elseif ($scan['locations_id'] and isset($hereIds[$scan['locations_id']])) {
            // Here - name the sub-location if it's further down, e.g. a shelf in this store
            $asset['whereabouts'] = ["state" => "here", "location" => ($scan['locations_id'] == $homeLocationId ? null : $scan['locations_name'])];
        } elseif ($scan['locations_id']) {
            $asset['whereabouts'] = ["state" => "elsewhere", "location" => $scan['locations_name']];
        } elseif ($scan['location_assets_id']) {
            $asset['whereabouts'] = ["state" => "elsewhere", "location" => "Inside " . $scan['container_typeName'] . " (" . $scan['container_tag'] . ")"];
        } elseif ($scan['assetsBarcodes_customLocation']) {
            $asset['whereabouts'] = ["state" => "elsewhere", "location" => $scan['assetsBarcodes_customLocation']];
        } else {
            $asset['whereabouts'] = ["state" => "unknown", "location" => null];
        }
    }
    unset($asset);
}

function lookupLocation($locationsId) {
    global $DBLIB, $instanceId;
    $DBLIB->where("locations.locations_id", $locationsId);
    $DBLIB->where("locations.instances_id", $instanceId);
    $DBLIB->where("locations.locations_deleted", 0);
    $DBLIB->join("clients", "locations.clients_id=clients.clients_id", "LEFT");
    $location = $DBLIB->getone("locations", ["locations.locations_id", "locations.locations_name", "locations.locations_archived", "clients.clients_name"]);
    if (!$location) return false;
    $location['path'] = lookupLocationPath($location['locations_id']);

    // Assets whose most recent scan put them here - the same rule the rest of the app uses for "current location"
    $hereNow = $DBLIB->rawQuery("SELECT DISTINCT assets.assets_id, assets.assets_tag, assetTypes.assetTypes_name
        FROM assetsBarcodesScans
        JOIN assetsBarcodes ON assetsBarcodes.assetsBarcodes_id = assetsBarcodesScans.assetsBarcodes_id
        JOIN assets ON assets.assets_id = assetsBarcodes.assets_id
        JOIN assetTypes ON assetTypes.assetTypes_id = assets.assetTypes_id
        JOIN locationsBarcodes ON locationsBarcodes.locationsBarcodes_id = assetsBarcodesScans.locationsBarcodes_id
        JOIN (
            SELECT latestBarcodes.assets_id, MAX(latestScans.assetsBarcodesScans_timestamp) AS latestTimestamp
            FROM assetsBarcodesScans AS latestScans
            JOIN assetsBarcodes AS latestBarcodes ON latestBarcodes.assetsBarcodes_id = latestScans.assetsBarcodes_id
            JOIN assets AS latestAssets ON latestAssets.assets_id = latestBarcodes.assets_id
            WHERE latestBarcodes.assetsBarcodes_deleted = 0 AND latestAssets.instances_id = ?
            GROUP BY latestBarcodes.assets_id
        ) AS latest ON latest.assets_id = assets.assets_id AND latest.latestTimestamp = assetsBarcodesScans.assetsBarcodesScans_timestamp
        WHERE locationsBarcodes.locations_id = ? AND assets.instances_id = ? AND assets.assets_deleted = 0 AND assetsBarcodes.assetsBarcodes_deleted = 0
        ORDER BY assets.assets_tag ASC
        LIMIT " . (LOOKUP_LIST_LIMIT + 1), [$instanceId, $location['locations_id'], $instanceId]);
    $location['hereNowMore'] = count($hereNow) > LOOKUP_LIST_LIMIT;
    $location['hereNow'] = array_slice($hereNow, 0, LOOKUP_LIST_LIMIT);

    $DBLIB->where("assets.instances_id", $instanceId);
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->where("assets.assets_storageLocation", $location['locations_id']);
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $storedHere = $DBLIB->get("assets", LOOKUP_LIST_LIMIT + 1, ["assets.assets_id", "assets.assets_tag", "assetTypes.assetTypes_name"]);
    $location['storedHereMore'] = count($storedHere) > LOOKUP_LIST_LIMIT;
    $location['storedHere'] = array_slice($storedHere, 0, LOOKUP_LIST_LIMIT);
    lookupAddWhereabouts($location['storedHere'], $location['locations_id']);

    $DBLIB->where("instances_id", $instanceId);
    $DBLIB->where("locations_deleted", 0);
    $DBLIB->where("locations_subOf", $location['locations_id']);
    $DBLIB->orderBy("locations_name", "ASC");
    $location['subLocations'] = $DBLIB->get("locations", null, ["locations_id", "locations_name", "locations_archived"]);
    return $location;
}

// Direct lookups (from a list on the page) need no barcode
if (isset($_POST['assets_id'])) {
    $asset = lookupAsset((int)$_POST['assets_id']);
    if (!$asset) finish(false, ["code" => "NOTFOUND", "message" => "Asset not found"]);
    finish(true, null, ["kind" => "asset", "asset" => $asset, "matchedBy" => "id"]);
}
if (isset($_POST['locations_id'])) {
    $location = lookupLocation((int)$_POST['locations_id']);
    if (!$location) finish(false, ["code" => "NOTFOUND", "message" => "Location not found"]);
    finish(true, null, ["kind" => "location", "location" => $location]);
}

if (!isset($_POST['text']) or strlen(trim($_POST['text'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No barcode given"]);
$text = trim($_POST['text']);

// 1. An asset's barcode
$DBLIB->where("assetsBarcodes.assetsBarcodes_value", $text);
if ($hasType) $DBLIB->where("assetsBarcodes.assetsBarcodes_type", $_POST['type']);
$DBLIB->where("assetsBarcodes.assetsBarcodes_deleted", 0);
$DBLIB->where("assets.instances_id", $instanceId);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->join("assets", "assets.assets_id=assetsBarcodes.assets_id", "LEFT");
$barcodes = $DBLIB->get("assetsBarcodes", null, ["assetsBarcodes.assets_id", "assetsBarcodes.assetsBarcodes_id"]);
if ($barcodes and count(array_unique(array_column($barcodes, 'assets_id'))) > 1) finish(false, ["code" => "ASSET-BARCODE-NOT-UNIQUE", "message" => "Several assets share this barcode"]);
if ($barcodes) {
    $asset = lookupAsset($barcodes[0]['assets_id']);
    if ($asset) finish(true, null, ["kind" => "asset", "asset" => $asset, "matchedBy" => "barcode", "barcode" => ["assetsBarcodes_id" => $barcodes[0]['assetsBarcodes_id']]]);
}

// 2. A location's barcode
$DBLIB->where("locationsBarcodes.locationsBarcodes_value", $text);
if ($hasType) $DBLIB->where("locationsBarcodes.locationsBarcodes_type", $_POST['type']);
$DBLIB->where("locationsBarcodes.locationsBarcodes_deleted", 0);
$DBLIB->where("locations.instances_id", $instanceId);
$DBLIB->where("locations.locations_deleted", 0);
$DBLIB->join("locations", "locations.locations_id=locationsBarcodes.locations_id", "LEFT");
$locationBarcode = $DBLIB->getone("locationsBarcodes", ["locationsBarcodes.locations_id"]);
if ($locationBarcode) {
    $location = lookupLocation($locationBarcode['locations_id']);
    if ($location) finish(true, null, ["kind" => "location", "location" => $location]);
}

// 3. No barcode, but the text is an asset tag (e.g. typed by hand, or a printed tag that was never registered as a barcode)
$DBLIB->where("assets.instances_id", $instanceId);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->where("assets.assets_tag", $text);
$taggedAsset = $DBLIB->getone("assets", ["assets_id"]);
if ($taggedAsset) {
    $asset = lookupAsset($taggedAsset['assets_id']);
    if ($asset) finish(true, null, ["kind" => "asset", "asset" => $asset, "matchedBy" => "tag", "barcode" => false]);
}

finish(true, null, ["kind" => "none"]);

/** @OA\Post(
 *     path="/assets/barcodes/lookup.php",
 *     summary="Barcode Lookup",
 *     description="Look up what a barcode belongs to without recording a scan. Returns an asset (with its locations, projects, flags/blocks, maintenance jobs, notes and fields), a location (with the assets currently there and stored there) or nothing.
Requires Instance Permission ASSETS:ASSET_BARCODES:VIEW
",
 *     operationId="barcodeLookup",
 *     tags={"barcodes"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *                 @OA\Property(property="response", type="object", description="kind ('asset', 'location' or 'none'), plus asset or location. For an asset, matchedBy ('barcode', 'tag' or 'id')"),
 *             ),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="text",
 *         in="query",
 *         description="The barcode value (or an asset tag)",
 *         @OA\Schema(type="string"),
 *     ),
 *     @OA\Parameter(
 *         name="type",
 *         in="query",
 *         description="The barcode type (optional - if not provided or set to UNKNOWN, searches by value only)",
 *         @OA\Schema(type="string"),
 *     ),
 *     @OA\Parameter(
 *         name="assets_id",
 *         in="query",
 *         description="Look up an asset directly instead of by barcode",
 *         @OA\Schema(type="number"),
 *     ),
 *     @OA\Parameter(
 *         name="locations_id",
 *         in="query",
 *         description="Look up a location directly instead of by barcode",
 *         @OA\Schema(type="number"),
 *     ),
 * )
 */
