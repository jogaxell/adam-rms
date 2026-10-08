<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/quantityBooking.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS") or !isset($_POST['projects_id']) or !isset($_POST['assetsAssignments_status']) or !isset($_POST['text']) or strlen($_POST['text']) < 1) finish(false, ["message" => "Missing required fields","code"=>"MISSINGFIELDS"]);

// Quantity booking: an asset of a type booked by quantity is picked for this project first, as in setStatusBarcode.php
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->where("assets.assets_tag", $_POST["text"]);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$taggedAsset = $DBLIB->getOne("assets", ["assets.assets_id"]);
if ($taggedAsset) {
    $quantityBind = quantityBindForProject($_POST['projects_id'], $taggedAsset['assets_id']);
    if (in_array($quantityBind['code'], ["CONFLICT", "NOREPLACEMENT", "ERROR"])) finish(false, ["message" => $quantityBind['message'], "code" => ($quantityBind['code'] == "ERROR" ? "PICKFAILED" : $quantityBind['code'])]);
    if ($quantityBind['code'] == "ALLPICKED") finish(false, ["message" => $quantityBind['message'], "code" => "NOTASSIGNED"]);
}

$DBLIB->where("assets.assets_deleted",0);
$DBLIB->where("assets.assets_tag", $_POST["text"]);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
$assignment = $DBLIB->getone("assetsAssignments",["assets.assets_id", "assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignmentsStatus_id", "assets.instances_id"]);
if (!$assignment or $assignment['assets_id'] == null) finish(false, ["message" => "Asset not found","code"=>"NOTFOUND"]);

// Validate before the no-op check below, so a deleted status is always rejected even if the assignment already happens to be on it
$DBLIB->where("assetsAssignmentsStatus_id", $_POST['assetsAssignments_status']);
$DBLIB->where("instances_id", $assignment['instances_id']); // Use the instance of the asset
$DBLIB->where("assetsAssignmentsStatus_deleted", 0);
$status = $DBLIB->getone("assetsAssignmentsStatus",["assetsAssignmentsStatus_id"]);
if (!$status or $status['assetsAssignmentsStatus_id'] == null) finish(false, ["message" => "Status not found","code"=>"STATUSNOTFOUND"]);

if ($assignment['assetsAssignmentsStatus_id'] == $_POST['assetsAssignments_status']) finish(true, null, ["assets_id" => $assignment['assets_id']]); // No change

$DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
$update = $DBLIB->update("assetsAssignments", ["assetsAssignmentsStatus_id" => $status['assetsAssignmentsStatus_id']], 1);
if (!$update) finish(false, ["message" => "Asset not assigned to project","code"=>"NOTASSIGNED"]);
else {
    $bCMS->auditLog("EDIT-STATUS", "assetsAssignments", $assignment['assetsAssignments_id'] . " set from " . $assignment['assetsAssignmentsStatus_id'] . " to " . $status['assetsAssignmentsStatus_id'] . " by direct tag entry", $AUTH->data['users_userid'],null, $_POST['projects_id']);
    finish(true, null, ["assets_id" => $assignment['assets_id']]);
}

/**
 *  @OA\Post(
 *      path="/projects/assets/setStatusByTag.php",
 *      summary="Set Asset Status by Tag",
 *      description="Set asset status for a project by the asset's tag",
 *      operationId="setStatusByTag",
 *      tags={"project_assets"},
 *      @OA\Response(
 *          response="200",
 *          description="Success",
 *          @OA\MediaType(
 *             mediaType="application/json", 
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *      ),
 *      @OA\Parameter(
 *          name="text",
 *          in="query",
 *          description="Value of the asset tag",
 *          required="true",
 *          @OA\Schema(
 *              type="string",
 *          ),
 *      ),
 *      @OA\Parameter(
 *          name="assetsAssignments_status",
 *          in="query",
 *          description="Status Id to set asset to",
 *          required="true",
 *          @OA\Schema(
 *              type="number",
 *          ),
 *      ),
 *  )
 */