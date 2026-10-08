<?php
/**
 * Quantity booking - see SPEC-quantity-booking.md
 *
 * Assets of a type with assetTypes_quantityBooking = 1 are booked by quantity: booking N creates N normal assignments
 * on assets that are free at that moment, marked unbound (assetsAssignments_bound = 0). They hold real,
 * availability-checked slots, so the rest of the system works on them unchanged. Scanning an asset of the type for a
 * project binds one of that project's unbound assignments to the scanned asset.
 *
 * Callers must have checked that the project belongs to the user's instance(s).
 * A $project array needs projects_id, projects_name, projects_dates_deliver_start and projects_dates_deliver_end.
 */
require_once __DIR__ . '/projectFinance.php';

const QUANTITY_ASSET_FIELDS = ["assets.assets_id", "assets.assets_tag", "assets.assetTypes_id", "assets.assets_storageLocation", "assets.assets_endDate", "assets.assets_dayRate", "assets.assets_weekRate", "assets.assets_value", "assets.assets_mass"];

/**
 * Adds a where condition matching assets that can't be booked for the project's dates: already on this project, or on
 * another project with overlapping delivery dates whose status hasn't released its assets. The same rule as
 * projects/assets/assign.php, written as one overlap check (start <= other end and end >= other start).
 * @param array $project The project to check against
 * @param bool $clashing true matches assets that clash, false assets that don't
 */
function quantityWhereClash($project, $clashing)
{
    global $DBLIB;
    $DBLIB->where(($clashing ? "" : "NOT ") . "EXISTS (SELECT 1 FROM assetsAssignments AS clashAssignments
        INNER JOIN projects AS clashProjects ON clashAssignments.projects_id = clashProjects.projects_id
        LEFT JOIN projectsStatuses AS clashStatuses ON clashProjects.projectsStatuses_id = clashStatuses.projectsStatuses_id
        WHERE clashAssignments.assets_id = assets.assets_id
        AND clashAssignments.assetsAssignments_deleted = 0
        AND clashProjects.projects_deleted = 0
        AND (clashProjects.projects_id = ? OR clashStatuses.projectsStatuses_assetsReleased = 0)
        AND clashProjects.projects_dates_deliver_start <= ?
        AND clashProjects.projects_dates_deliver_end >= ?)", [$project['projects_id'], $project['projects_dates_deliver_end'], $project['projects_dates_deliver_start']]);
}

/**
 * Locks every asset of a type until the transaction ends, so two bookings or scans of the same type can't pick the
 * same asset. Must be called inside $DBLIB->startTransaction().
 */
function quantityLockType($assetTypesId)
{
    global $DBLIB, $AUTH;
    $DBLIB->where("assets.assetTypes_id", $assetTypesId);
    $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
    $DBLIB->setQueryOption('FOR UPDATE');
    $DBLIB->get("assets", null, ["assets.assets_id"]);
}

/**
 * Assets of a type that are free for a project's dates: not deleted, not ended before the project ends, not blocked by
 * maintenance and not clashing with another booking. Sorted by preference: same storage location as
 * $preferStorageLocation (the tray an asset was taken from), then $preferAssetId, then by tag.
 * @param int $assetTypesId
 * @param array $project
 * @param int|null $limit Stop once this many are found (null for all)
 * @param int|null $preferStorageLocation A locations_id
 * @param int|null $preferAssetId
 * @param array $excludeAssetIds Assets to leave out
 * @return array Asset rows with the QUANTITY_ASSET_FIELDS
 */
function quantityFreeAssets($assetTypesId, $project, $limit = null, $preferStorageLocation = null, $preferAssetId = null, $excludeAssetIds = [])
{
    global $DBLIB, $AUTH;
    $DBLIB->where("assets.assetTypes_id", $assetTypesId);
    $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->where("(assets.assets_endDate IS NULL OR assets.assets_endDate >= ?)", [$project['projects_dates_deliver_end']]);
    if (count($excludeAssetIds) > 0) $DBLIB->where("assets.assets_id", $excludeAssetIds, 'NOT IN');
    quantityWhereClash($project, false);
    $candidates = $DBLIB->get("assets", null, QUANTITY_ASSET_FIELDS);
    if (!$candidates) return [];

    usort($candidates, function ($a, $b) use ($preferStorageLocation, $preferAssetId) {
        foreach ([["assets_storageLocation", $preferStorageLocation], ["assets_id", $preferAssetId]] as [$field, $preferred]) {
            if ($preferred === null) continue;
            $aPreferred = ($a[$field] !== null and $a[$field] == $preferred);
            $bPreferred = ($b[$field] !== null and $b[$field] == $preferred);
            if ($aPreferred != $bPreferred) return $aPreferred ? -1 : 1;
        }
        return strnatcasecmp($a['assets_tag'], $b['assets_tag']);
    });

    $free = [];
    foreach ($candidates as $asset) {
        if (assetFlagsAndBlocks($asset['assets_id'])['COUNT']['BLOCK'] > 0) continue;
        $free[] = $asset;
        if ($limit !== null and count($free) >= $limit) break;
    }
    return $free;
}

/**
 * The storage location most assets of a type are kept in, so new bookings take their placeholders from one tray.
 * @return int|null A locations_id, or null if none of them has one
 */
function quantityMainStorageLocation($assetTypesId)
{
    global $DBLIB, $AUTH;
    $DBLIB->where("assets.assetTypes_id", $assetTypesId);
    $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->where("assets.assets_storageLocation", NULL, 'IS NOT');
    $DBLIB->groupBy("assets.assets_storageLocation");
    $DBLIB->orderBy("assetCount", "DESC");
    $location = $DBLIB->getOne("assets", ["assets.assets_storageLocation", "COUNT(*) AS assetCount"]);
    return $location ? (int)$location['assets_storageLocation'] : null;
}

/**
 * Moves an assignment to another asset of the same type and corrects the project's finance cache.
 * @param array $assignment assetsAssignments_id, projects_id, assetsAssignments_customPrice and assetsAssignments_discount
 * @param array $oldAsset The asset it's on now, with the QUANTITY_ASSET_FIELDS
 * @param array $newAsset The asset it moves to, with the QUANTITY_ASSET_FIELDS
 * @param int|null $bound Also set assetsAssignments_bound, or null to leave it
 * @return bool
 */
function quantityMoveAssignment($assignment, $oldAsset, $newAsset, $bound = null)
{
    global $DBLIB;
    $update = ["assets_id" => $newAsset['assets_id']];
    if ($bound !== null) $update["assetsAssignments_bound"] = $bound;
    $DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
    $DBLIB->where("assets_id", $oldAsset['assets_id']);
    $DBLIB->where("assetsAssignments_deleted", 0);
    if (!$DBLIB->update("assetsAssignments", $update, 1) or $DBLIB->count != 1) return false;

    $DBLIB->where("assetTypes_id", $oldAsset['assetTypes_id']);
    $assetType = $DBLIB->getone("assetTypes", ["assetTypes_dayRate", "assetTypes_weekRate", "assetTypes_value", "assetTypes_mass"]);
    if (!$assetType) return false;
    $projectFinanceHelper = new projectFinance();
    return $projectFinanceHelper->swapAssignmentAsset($assignment['projects_id'], $assignment, $oldAsset, $newAsset, $assetType);
}

/**
 * Binds a scanned asset to one of a project's quantity bookings. Runs in its own transaction.
 * Decision table (SPEC-quantity-booking.md, Option A):
 *  - type not booked by quantity                           → NOTQUANTITY (caller carries on as before)
 *  - asset already bound to this project                   → ALREADYBOUND
 *  - asset is one of this project's unbound placeholders   → BOUND (just marked bound)
 *  - project has no unbound placeholder of the type left   → ALLPICKED
 *  - asset is deleted, ended or blocked                    → CONFLICT
 *  - asset is free                                         → BOUND (a placeholder moves onto it, the old asset becomes free)
 *  - asset is an unbound placeholder on overlapping projects → BOUND, and each of those projects gets a replacement
 *                                                            (listed in exchangedWith, audit-logged as EXCHANGE-ASSET)
 *  - no replacement is free for one of those projects     → NOREPLACEMENT (nothing changes)
 *  - asset is bound to an overlapping project              → CONFLICT
 * @param array $project
 * @param int $assetsId The scanned asset, already checked to belong to the user's instance
 * @return array ["code" => ..., "message" => ..., "assetsAssignments_id" => ..., "exchangedWith" => [[projects_id, projects_name], ...]]
 */
function quantityBindScan($project, $assetsId)
{
    global $DBLIB, $AUTH;
    $DBLIB->where("assets.assets_id", $assetsId);
    $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $asset = $DBLIB->getOne("assets", array_merge(QUANTITY_ASSET_FIELDS, ["assets.assets_deleted", "assetTypes.assetTypes_quantityBooking"]));
    if (!$asset or $asset['assetTypes_quantityBooking'] != 1) return ["code" => "NOTQUANTITY"];

    $DBLIB->startTransaction();
    quantityLockType($asset['assetTypes_id']);
    $result = quantityBindLocked($project, $asset);
    if ($result['code'] == "BOUND") $DBLIB->commit();
    else $DBLIB->rollback();
    return $result;
}

/**
 * For the endpoints that set a status from a scanned or typed asset: picks the asset for the project first if its
 * type is booked by quantity, so the status change that follows finds an assignment for it.
 * @param int $projectsId Not yet validated - only projects of the current instance are used
 * @param int $assetsId The asset, already checked to belong to the user's instance
 * @return array quantityBindScan's result, or ["code" => "NOTQUANTITY"] when there is nothing to pick
 */
function quantityBindForProject($projectsId, $assetsId)
{
    global $DBLIB, $AUTH;
    $DBLIB->where("assets.assets_id", $assetsId);
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    if ($DBLIB->getValue("assets", "assetTypes.assetTypes_quantityBooking") != 1) return ["code" => "NOTQUANTITY"];

    $DBLIB->where("projects.projects_id", $projectsId);
    $DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("projects.projects_deleted", 0);
    $project = $DBLIB->getOne("projects", ["projects_id", "projects_name", "projects_dates_deliver_start", "projects_dates_deliver_end"]);
    if (!$project or $project['projects_dates_deliver_start'] === null or $project['projects_dates_deliver_end'] === null) return ["code" => "NOTQUANTITY"];
    return quantityBindScan($project, $assetsId);
}

/**
 * The body of quantityBindScan, run while the asset type is locked. The caller commits or rolls back.
 */
function quantityBindLocked($project, $asset)
{
    global $DBLIB, $AUTH, $bCMS;
    $assignmentFields = ["assetsAssignments.assetsAssignments_id", "assetsAssignments.projects_id", "assetsAssignments.assets_id", "assetsAssignments.assetsAssignments_bound", "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_discount"];

    // Already on this project?
    $DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
    $DBLIB->where("assetsAssignments.projects_id", $project['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $own = $DBLIB->getOne("assetsAssignments", $assignmentFields);
    if ($own and $own['assetsAssignments_bound'] == 1) return ["code" => "ALREADYBOUND", "assetsAssignments_id" => $own['assetsAssignments_id']];
    if ($own) {
        $DBLIB->where("assetsAssignments_id", $own['assetsAssignments_id']);
        if (!$DBLIB->update("assetsAssignments", ["assetsAssignments_bound" => 1], 1)) return ["code" => "ERROR", "message" => "Could not update the assignment"];
        $bCMS->auditLog("BIND-ASSET", "assetsAssignments", "Bound to " . $asset['assets_tag'], $AUTH->data['users_userid'], null, $project['projects_id'], $own['assetsAssignments_id']);
        return ["code" => "BOUND", "assetsAssignments_id" => $own['assetsAssignments_id'], "exchangedWith" => []];
    }

    // One of this project's placeholders to move onto the scanned asset
    $DBLIB->where("assetsAssignments.projects_id", $project['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->where("assetsAssignments.assetsAssignments_bound", 0);
    $DBLIB->where("assets.assetTypes_id", $asset['assetTypes_id']);
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->orderBy("assetsAssignments.assetsAssignments_id", "ASC");
    $placeholder = $DBLIB->getOne("assetsAssignments", array_merge($assignmentFields, QUANTITY_ASSET_FIELDS));
    if (!$placeholder) return ["code" => "ALLPICKED", "message" => "All booked assets of this type have already been picked"];

    if ($asset['assets_deleted'] != 0) return ["code" => "CONFLICT", "message" => "This asset has been deleted"];
    if ($asset['assets_endDate'] !== null and strtotime($asset['assets_endDate']) < strtotime($project['projects_dates_deliver_end'])) return ["code" => "CONFLICT", "message" => "This asset is archived before the project ends"];
    if (assetFlagsAndBlocks($asset['assets_id'])['COUNT']['BLOCK'] > 0) return ["code" => "CONFLICT", "message" => "This asset is blocked by maintenance"];

    // Who else has it on overlapping dates?
    $DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("projectsStatuses.projectsStatuses_assetsReleased", 0);
    $DBLIB->where("projects.projects_dates_deliver_start", $project['projects_dates_deliver_end'], '<=');
    $DBLIB->where("projects.projects_dates_deliver_end", $project['projects_dates_deliver_start'], '>=');
    $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    $others = $DBLIB->get("assetsAssignments", null, array_merge($assignmentFields, ["projects.projects_name", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end"]));
    foreach ($others as $other) {
        if ($other['assetsAssignments_bound'] == 1) return ["code" => "CONFLICT", "message" => "This asset has already been picked for " . $other['projects_name']];
    }

    // Move this project's placeholder onto the scanned asset. The asset it leaves is free from here on.
    if (!quantityMoveAssignment($placeholder, $placeholder, $asset, 1)) return ["code" => "ERROR", "message" => "Could not move the booking onto this asset"];
    $bCMS->auditLog("BIND-ASSET", "assetsAssignments", "Bound to " . $asset['assets_tag'] . " in place of " . $placeholder['assets_tag'], $AUTH->data['users_userid'], null, $project['projects_id'], $placeholder['assetsAssignments_id']);

    // Give every other project that held the scanned asset as a placeholder a replacement, preferring the same tray
    $exchangedWith = [];
    foreach ($others as $other) {
        $replacement = quantityFreeAssets($asset['assetTypes_id'], $other, 1, $asset['assets_storageLocation'], $placeholder['assets_id'], [$asset['assets_id']]);
        if (count($replacement) < 1) return ["code" => "NOREPLACEMENT", "message" => "This asset is reserved for " . $other['projects_name'] . " and no other asset of this type is free for it"];
        if (!quantityMoveAssignment($other, $asset, $replacement[0])) return ["code" => "ERROR", "message" => "Could not move the booking of " . $other['projects_name']];
        $bCMS->auditLog("EXCHANGE-ASSET", "assetsAssignments", $asset['assets_tag'] . " was picked for " . $project['projects_name'] . ", replaced by " . $replacement[0]['assets_tag'], $AUTH->data['users_userid'], null, $other['projects_id'], $other['assetsAssignments_id']);
        $exchangedWith[] = ["projects_id" => $other['projects_id'], "projects_name" => $other['projects_name']];
    }
    return ["code" => "BOUND", "assetsAssignments_id" => $placeholder['assetsAssignments_id'], "exchangedWith" => $exchangedWith];
}
