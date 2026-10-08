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
 *  - asset is an unbound placeholder on overlapping projects → BOUND; quantityPlan() re-arranges the unpicked
 *                                                            reservations so each of those projects keeps its count
 *                                                            (listed in exchangedWith, audit-logged as EXCHANGE-ASSET)
 *  - no layout is found for those reservations            → NOREPLACEMENT (nothing changes)
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

    // The asset is another job's unpicked reservation: plan where those reservations go instead (FR10), before changing anything
    $plan = null;
    if (count($others) > 0) {
        $plan = quantityPlan($asset['assetTypes_id'], $project, 0, [$placeholder, $asset], $asset['assets_storageLocation'], $placeholder['assets_id']);
        if (!$plan) return ["code" => "NOREPLACEMENT", "message" => "This asset is reserved for " . $others[0]['projects_name'] . " and the bookings of this type can't be re-arranged to free it"];
    }

    // Move this project's placeholder onto the scanned asset. The asset it leaves is free from here on.
    if (!quantityMoveAssignment($placeholder, $placeholder, $asset, 1)) return ["code" => "ERROR", "message" => "Could not move the booking onto this asset"];
    $bCMS->auditLog("BIND-ASSET", "assetsAssignments", "Bound to " . $asset['assets_tag'] . " in place of " . $placeholder['assets_tag'], $AUTH->data['users_userid'], null, $project['projects_id'], $placeholder['assetsAssignments_id']);

    $exchangedWith = [];
    if ($plan) {
        $exchangedWith = quantityApplyPlan($plan, $project);
        if ($exchangedWith === false) return ["code" => "ERROR", "message" => "Could not move the other bookings"];
    }
    return ["code" => "BOUND", "assetsAssignments_id" => $placeholder['assetsAssignments_id'], "exchangedWith" => $exchangedWith];
}

/**
 * FR10: plans where the unpicked reservations of a type can go, so a booking or pick that doesn't fit directly can
 * still be met by moving other jobs' unpicked reservations. Only reservations linked to the request through
 * overlapping dates are considered. Picked ones, reservations of jobs that have ended and jobs that have released
 * their assets stay where they are. Reservations are placed in order of start date, each keeping its cable if that is
 * still free, otherwise taking the first free cable by preference (same storage location, then $preferAsset, then
 * tag). Run inside the type lock (quantityLockType). Changes nothing - see quantityApplyPlan().
 * @param int $assetTypesId
 * @param array $project The job asking
 * @param int $newCount How many new reservations the job needs (booking), 0 for a pick
 * @param array|null $fix For a pick: [the job's placeholder (assignment + QUANTITY_ASSET_FIELDS), the scanned asset] - the placeholder goes onto that asset
 * @param int|null $preferStorage A locations_id
 * @param int|null $preferAsset
 * @return array|false ["moves" => [["assignment" => row, "from" => asset row, "to" => asset row], ...], "new" => [asset rows for the new reservations]], or false if no layout was found
 */
function quantityPlan($assetTypesId, $project, $newCount, $fix = null, $preferStorage = null, $preferAsset = null)
{
    global $DBLIB, $AUTH;
    $overlaps = fn($a, $b) => ($a['start'] <= $b['end'] and $a['end'] >= $b['start']);
    //Dates are compared as timestamps throughout - the search below compares them a lot
    $requestInterval = ["start" => strtotime($project['projects_dates_deliver_start']), "end" => strtotime($project['projects_dates_deliver_end'])];

    // The cables, with their maintenance blocks looked up once per request
    static $cableCache = [];
    if (!isset($cableCache[$assetTypesId])) {
        $DBLIB->where("assets.assetTypes_id", $assetTypesId);
        $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
        $DBLIB->where("assets.assets_deleted", 0);
        $cableCache[$assetTypesId] = [];
        foreach ($DBLIB->get("assets", null, QUANTITY_ASSET_FIELDS) as $cable) {
            $cable['blocked'] = (assetFlagsAndBlocks($cable['assets_id'])['COUNT']['BLOCK'] > 0);
            $cable['endsAt'] = ($cable['assets_endDate'] === null ? null : strtotime($cable['assets_endDate']));
            $cableCache[$assetTypesId][$cable['assets_id']] = $cable;
        }
    }
    $cables = $cableCache[$assetTypesId];
    $ordered = array_values($cables);
    usort($ordered, function ($a, $b) use ($preferStorage, $preferAsset) {
        foreach ([["assets_storageLocation", $preferStorage], ["assets_id", $preferAsset]] as [$field, $preferred]) {
            if ($preferred === null) continue;
            $aPreferred = ($a[$field] !== null and $a[$field] == $preferred);
            $bPreferred = ($b[$field] !== null and $b[$field] == $preferred);
            if ($aPreferred != $bPreferred) return $aPreferred ? -1 : 1;
        }
        return strnatcasecmp($a['assets_tag'], $b['assets_tag']);
    });

    // Assignments of the type that hold a cable: on live jobs that haven't released their assets, or on the job asking
    $fields = array_merge(["assetsAssignments.assetsAssignments_id", "assetsAssignments.projects_id", "assetsAssignments.assetsAssignments_bound", "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_discount", "projects.projects_name", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end"], QUANTITY_ASSET_FIELDS);
    $holding = function () use ($DBLIB, $AUTH, $assetTypesId, $project) {
        $DBLIB->where("assets.assetTypes_id", $assetTypesId);
        $DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
        $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
        $DBLIB->where("projects.projects_deleted", 0);
        $DBLIB->where("projects.projects_dates_deliver_start", NULL, 'IS NOT');
        $DBLIB->where("projects.projects_dates_deliver_end", NULL, 'IS NOT');
        $DBLIB->where("(projects.projects_id = ? OR projectsStatuses.projectsStatuses_assetsReleased = 0)", [$project['projects_id']]);
        $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id");
        $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id");
        $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    };
    $fixId = ($fix ? $fix[0]['assetsAssignments_id'] : null);

    // Movable: unpicked reservations of jobs that haven't ended, linked to the request through overlapping dates
    $holding();
    $DBLIB->where("assetsAssignments.assetsAssignments_bound", 0);
    $DBLIB->where("projects.projects_dates_deliver_end", date("Y-m-d H:i:s"), '>=');
    $candidates = [];
    foreach ($DBLIB->get("assetsAssignments", null, $fields) as $row) {
        if ($row['assetsAssignments_id'] == $fixId) continue;
        $row['start'] = strtotime($row['projects_dates_deliver_start']);
        $row['end'] = strtotime($row['projects_dates_deliver_end']);
        $candidates[$row['assetsAssignments_id']] = $row;
    }
    $linked = [$requestInterval];
    $movable = [];
    do {
        $added = false;
        foreach ($candidates as $id => $row) {
            foreach ($linked as $interval) {
                if ($overlaps($row, $interval)) {
                    $movable[$id] = $row;
                    $linked[] = $row;
                    unset($candidates[$id]);
                    $added = true;
                    break;
                }
            }
        }
    } while ($added);

    // Everything else that holds a cable in that time stays where it is
    $windowStart = min(array_column($linked, 'start'));
    $windowEnd = max(array_column($linked, 'end'));
    $taken = []; // assets_id => [intervals]
    $holding();
    $DBLIB->where("projects.projects_dates_deliver_start", date("Y-m-d H:i:s", $windowEnd), '<=');
    $DBLIB->where("projects.projects_dates_deliver_end", date("Y-m-d H:i:s", $windowStart), '>=');
    foreach ($DBLIB->get("assetsAssignments", null, $fields) as $row) {
        if (isset($movable[$row['assetsAssignments_id']]) or $row['assetsAssignments_id'] == $fixId) continue;
        $taken[$row['assets_id']][] = ["start" => strtotime($row['projects_dates_deliver_start']), "end" => strtotime($row['projects_dates_deliver_end'])];
    }
    if ($fix) $taken[$fix[1]['assets_id']][] = $requestInterval;

    // Place the movable and new reservations by start date
    $items = [];
    foreach ($movable as $row) $items[] = ["row" => $row, "start" => $row['start'], "end" => $row['end'], "new" => false];
    for ($i = 0; $i < $newCount; $i++) $items[] = ["row" => null, "start" => $requestInterval['start'], "end" => $requestInterval['end'], "new" => true];
    usort($items, function ($a, $b) {
        return [$a['start'], $a['end'], $a['new'], $a['row']['assetsAssignments_id'] ?? 0] <=> [$b['start'], $b['end'], $b['new'], $b['row']['assetsAssignments_id'] ?? 0];
    });
    $isFree = function ($assetsId, $item) use ($cables, &$taken, $overlaps) {
        if (!isset($cables[$assetsId]) or $cables[$assetsId]['blocked']) return false;
        if ($cables[$assetsId]['endsAt'] !== null and $cables[$assetsId]['endsAt'] < $item['end']) return false;
        foreach ($taken[$assetsId] ?? [] as $interval) if ($overlaps($interval, $item)) return false;
        return true;
    };
    // Quick refusal of a real shortage: at some moment of the request more reservations than usable cables
    $usable = count(array_filter($cables, fn($cable) => !$cable['blocked']));
    $onUsable = [];
    foreach ($taken as $assetsId => $intervals) {
        if (isset($cables[$assetsId]) and !$cables[$assetsId]['blocked']) foreach ($intervals as $interval) $onUsable[] = $interval;
    }
    $all = array_merge($onUsable, $items);
    foreach (array_merge([$requestInterval['start']], array_column($all, 'start')) as $moment) {
        if ($moment < $requestInterval['start'] or $moment > $requestInterval['end']) continue;
        if (count(array_filter($all, fn($interval) => $interval['start'] <= $moment and $interval['end'] >= $moment)) > $usable) return false;
    }

    // The cables a reservation can take, in the order to try them. "keep": its own cable, then by preference.
    // "fit": the cable whose next booking starts soonest after it ends, keeping long free stretches for long jobs.
    $rank = array_flip(array_column($ordered, 'assets_id'));
    $choices = function ($item, $mode) use ($ordered, $rank, &$taken, $isFree) {
        $current = ($item['new'] ? null : $item['row']['assets_id']);
        $free = [];
        foreach ($ordered as $cable) {
            if (!$isFree($cable['assets_id'], $item)) continue;
            $nextBooking = PHP_INT_MAX;
            if ($mode == "fit") foreach ($taken[$cable['assets_id']] ?? [] as $interval) {
                if ($interval['start'] > $item['end']) $nextBooking = min($nextBooking, $interval['start']);
            }
            $free[] = [$nextBooking, ($cable['assets_id'] == $current ? 0 : 1), $rank[$cable['assets_id']], $cable['assets_id']];
        }
        if ($mode == "keep") usort($free, fn($a, $b) => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        else sort($free);
        return array_column($free, 3);
    };
    $fixedTaken = $taken;
    // Orders to place the reservations in: by start date first (keeps cables, and is exact without picked cables in
    // the way), then the most constrained first (fewest cables left by the fixed bookings)
    $byStart = array_keys($items);
    $optionsCount = [];
    foreach ($items as $k => $item) $optionsCount[$k] = count($choices($item, "keep"));
    $mostConstrained = $byStart;
    usort($mostConstrained, fn($a, $b) => [$optionsCount[$a], $items[$a]['start']] <=> [$optionsCount[$b], $items[$b]['start']]);
    $attempts = [["keep", $byStart], ["fit", $byStart], ["fit", $mostConstrained]];
    $chosen = null;
    foreach ($attempts as [$mode, $order]) {
        $taken = $fixedTaken;
        $chosen = [];
        foreach ($order as $k) {
            $options = $choices($items[$k], $mode);
            if (count($options) < 1) {
                $chosen = null;
                break;
            }
            $chosen[$k] = $options[0];
            $taken[$options[0]][] = ["start" => $items[$k]['start'], "end" => $items[$k]['end']];
        }
        if ($chosen !== null) break;
    }
    if ($chosen === null) {
        // Picked cables in the way can make these orders miss a layout: search, always placing the reservation with the
        // fewest cables left next and backing out as soon as one has none. Limited, as proving there is no layout can take long.
        $taken = $fixedTaken;
        $chosen = [];
        $steps = 0;
        $deadline = microtime(true) + 1.5;
        $search = function () use (&$search, &$chosen, &$taken, &$steps, $deadline, $items, $choices) {
            if (count($chosen) == count($items)) return true;
            if (++$steps > 10000 or microtime(true) > $deadline) return false;
            $next = null;
            $nextOptions = null;
            foreach ($items as $k => $item) {
                if (isset($chosen[$k])) continue;
                $options = $choices($item, "fit");
                if (count($options) == 0) return false;
                if ($next === null or count($options) < count($nextOptions)) {
                    $next = $k;
                    $nextOptions = $options;
                }
            }
            foreach ($nextOptions as $assetsId) {
                $taken[$assetsId][] = ["start" => $items[$next]['start'], "end" => $items[$next]['end']];
                $chosen[$next] = $assetsId;
                if ($search()) return true;
                array_pop($taken[$assetsId]);
                unset($chosen[$next]);
            }
            return false;
        };
        if (!$search()) return false;
    }

    // The later orders ignore where reservations are now, so send each moved one back to its own cable if nothing in
    // the layout needs it - only the moves that make room are left
    do {
        $changed = false;
        foreach ($items as $k => $item) {
            if ($item['new'] or $chosen[$k] == $item['row']['assets_id']) continue;
            $own = $item['row']['assets_id'];
            if (!isset($cables[$own]) or $cables[$own]['blocked'] or ($cables[$own]['endsAt'] !== null and $cables[$own]['endsAt'] < $item['end'])) continue;
            $free = true;
            foreach ($fixedTaken[$own] ?? [] as $interval) if ($overlaps($interval, $item)) $free = false;
            foreach ($items as $j => $other) if ($free and $j != $k and $chosen[$j] == $own and $overlaps($other, $item)) $free = false;
            if ($free) {
                $chosen[$k] = $own;
                $changed = true;
            }
        }
    } while ($changed);

    $plan = ["moves" => [], "new" => []];
    foreach ($items as $k => $item) {
        if ($item['new']) $plan['new'][] = $cables[$chosen[$k]];
        elseif ($chosen[$k] != $item['row']['assets_id']) $plan['moves'][] = ["assignment" => $item['row'], "from" => $item['row'], "to" => $cables[$chosen[$k]]];
    }
    return $plan;
}

/**
 * Performs the moves of a quantityPlan(), correcting each job's finances and audit-logging every moved reservation.
 * @param array $plan
 * @param array $project The job the moves make room for
 * @return array|false The other jobs whose reservations moved ([projects_id, projects_name], ...), or false if a move failed
 */
function quantityApplyPlan($plan, $project)
{
    global $AUTH, $bCMS;
    $moved = [];
    foreach ($plan['moves'] as $move) {
        if (!quantityMoveAssignment($move['assignment'], $move['from'], $move['to'])) return false;
        $bCMS->auditLog("EXCHANGE-ASSET", "assetsAssignments", $move['from']['assets_tag'] . " moved to " . $move['to']['assets_tag'] . " to make room for " . $project['projects_name'], $AUTH->data['users_userid'], null, $move['assignment']['projects_id'], $move['assignment']['assetsAssignments_id']);
        if ($move['assignment']['projects_id'] != $project['projects_id']) $moved[$move['assignment']['projects_id']] = ["projects_id" => $move['assignment']['projects_id'], "projects_name" => $move['assignment']['projects_name']];
    }
    return array_values($moved);
}
