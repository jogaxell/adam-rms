<?php
/**
 * API
 * \projects\assets\bind.php
 * Picks an asset for a quantity booking by its tag, for when there's no scanner to hand - the same as scanning it in
 * Barcode Dispatch, without changing its status. See SPEC-quantity-booking.md.
 *
 * Arguments:
 *  - assetsAssignments_id: an unpicked placeholder of the project - any placeholder of the type may be the one that moves
 *  - assets_tag: the tag (or a barcode value) of the asset that was taken
 */
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/quantityBooking.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");
if (!isset($_POST['assetsAssignments_id']) or !isset($_POST['assets_tag']) or strlen(trim($_POST['assets_tag'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "Please enter a tag"]);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $_POST['assetsAssignments_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id");
$DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id");
$assignment = $DBLIB->getOne("assetsAssignments", ["assetsAssignments.projects_id", "assets.assetTypes_id"]);
if (!$assignment) finish(false, ["code" => "NOTFOUND", "message" => "Booking not found"]);

$tag = trim($_POST['assets_tag']);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->where("(assets.assets_tag = ? OR EXISTS (SELECT 1 FROM assetsBarcodes WHERE assetsBarcodes.assets_id = assets.assets_id AND assetsBarcodes.assetsBarcodes_deleted = 0 AND assetsBarcodes.assetsBarcodes_value = ?))", [$tag, $tag]);
$asset = $DBLIB->getOne("assets", ["assets.assets_id", "assets.assets_tag", "assets.assetTypes_id"]);
if (!$asset) finish(false, ["code" => "NOTFOUND", "message" => "No asset has the tag or barcode " . $bCMS->sanitizeString($tag)]);
if ($asset['assetTypes_id'] != $assignment['assetTypes_id']) finish(false, ["code" => "WRONGTYPE", "message" => $bCMS->sanitizeString($asset['assets_tag']) . " is a different type of asset"]);

$result = quantityBindForProject($assignment['projects_id'], $asset['assets_id']);
switch ($result['code']) {
    case "BOUND":
        finish(true, null, ["assets_id" => $asset['assets_id'], "assets_tag" => $asset['assets_tag'], "exchangedWith" => $result['exchangedWith']]);
    case "ALREADYBOUND":
        finish(false, ["code" => "ALREADYBOUND", "message" => $bCMS->sanitizeString($asset['assets_tag']) . " has already been picked for this project"]);
    case "NOTQUANTITY":
        finish(false, ["code" => "NOTQUANTITY", "message" => "This asset type is not booked by quantity"]);
    default: // ALLPICKED, CONFLICT, NOREPLACEMENT, ERROR
        finish(false, ["code" => ($result['code'] == "ERROR" ? "PICKFAILED" : $result['code']), "message" => $bCMS->sanitizeString($result['message'])]); //The message can hold a project name and is shown as HTML
}

/** @OA\Post(
 *     path="/projects/assets/bind.php",
 *     summary="Pick Asset for a Quantity Booking",
 *     description="Picks an asset for a project's quantity booking by its tag, the same as scanning it in Barcode Dispatch, without changing its status. If the asset was another project's unpicked placeholder, that project gets another asset of the type.
Requires Instance Permission PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN
",
 *     operationId="bindAssetAssignment",
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
 *                     description="assets_id, assets_tag and exchangedWith (projects_id + projects_name of each project whose placeholder the asset was). Errors: NOTFOUND, WRONGTYPE, ALREADYBOUND, NOTQUANTITY, ALLPICKED, CONFLICT, NOREPLACEMENT, PICKFAILED",
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="assetsAssignments_id",
 *         in="query",
 *         description="An unpicked placeholder of the project",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assets_tag",
 *         in="query",
 *         description="Tag or barcode value of the asset that was taken",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
