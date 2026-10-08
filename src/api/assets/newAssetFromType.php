<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("ASSETS:CREATE")) die("Sorry - you can't access this page");
$array = [];
foreach ($_POST['formData'] as $item) {
    $array[$item['name']] = $item['value'];
}
if (strlen($array['assetTypes_id']) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$array['instances_id'] = $AUTH->data['instance']['instances_id'];
$array['assets_inserted'] = date('Y-m-d H:i:s');

$DBLIB->where("(assetTypes.instances_id IS NULL OR assetTypes.instances_id = '" . $AUTH->data['instance']['instances_id'] . "')");
$DBLIB->where("assetTypes_id", $array['assetTypes_id']);
$asset = $DBLIB->getone("assetTypes");
if (!$asset) finish(false, ["code" => "LIST-ASSETTYPES-FAIL", "message" => "Could not find asset type"]);

//Bulk creation: up to 500 assets of the type at once, each with its own generated tag and barcode
$quantity = 1;
if (isset($array['assets_quantity']) and $array['assets_quantity'] !== '') {
    $quantity = filter_var($array['assets_quantity'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 500]]);
    if ($quantity === false) finish(false, ["code" => "PARAM-ERROR", "message" => "Please enter a quantity between 1 and 500"]);
}
if ($quantity > 1 and isset($array['assets_tag']) and $array['assets_tag'] != null) finish(false, ["code" => "PARAM-ERROR", "message" => "A custom tag can only be used when adding a single asset"]);

if (isset($array['assets_tag']) and $array['assets_tag'] != null) {
    $DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("assets.assets_tag", $array['assets_tag']);
    $DBLIB->where("assets.assets_deleted", 0); //Deleted assets can't be restored, so can be used
    $duplicateAssetTag = $DBLIB->getValue("assets", "count(*)");
    if ($duplicateAssetTag > 0) finish(false, ["code" => "INSERT-FAIL", "message" => "Sorry that tag you chose was a duplicate - please choose another one"]);
} else $array['assets_tag'] = generateNewTag();

if (isset($array['assets_storageLocation']) and $array['assets_storageLocation'] != null) {
    $array['assets_storageLocation'] = (int) $array['assets_storageLocation'];
    $DBLIB->where("locations.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("locations.locations_id", $array['assets_storageLocation']);
    $DBLIB->where("locations.locations_deleted", 0);
    $DBLIB->where("locations.locations_archived", 0);
    if (!$DBLIB->getValue("locations", "count(*)")) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find that storage location"]);
} else unset($array['assets_storageLocation']);

function checkDuplicate($value, $type)
{
    global $DBLIB;
    $DBLIB->where("assetsBarcodes_value", $value);
    $DBLIB->where("assetsBarcodes_type", $type);
    $result = $DBLIB->getone("assetsBarcodes", ["assetsBarcodes_id"]);
    if ($result) return true;
    else return false;
}

$DBLIB->startTransaction(); //All or nothing - rolled back by MysqliDb's shutdown handler if anything below finishes early
$created = [];
for ($i = 0; $i < $quantity; $i++) {
    if ($i > 0) $array['assets_tag'] = generateNewTag(); //Sees the tags inserted so far, as they're in the same transaction
    $result = $DBLIB->insert("assets", array_intersect_key($array, array_flip(['assets_tag', 'assetTypes_id', 'assets_notes', 'assets_storageLocation', 'instances_id', 'asset_definableFields_1', 'asset_definableFields_2', 'asset_definableFields_3', 'asset_definableFields_4', 'asset_definableFields_5', 'asset_definableFields_6', 'asset_definableFields_7', 'asset_definableFields_8', 'asset_definableFields_9', 'asset_definableFields_10', 'assets_assetGroups'])));
    if (!$result) finish(false, ["code" => "INSERT-FAIL", "message" => "Could not insert asset"]);

    //Generate asset barcode
    $assetBarcodeData = [
        "assetsBarcodes_value" => $array['assets_tag'],
        "assetsBarcodes_type" => "QR_CODE",
        "assets_id" => $result,
        "users_userid" => $AUTH->data['users_userid'],
        "assetsBarcodes_added" => date("Y-m-d H:i:s")
    ];
    while (checkDuplicate($assetBarcodeData["assetsBarcodes_value"], $assetBarcodeData["assetsBarcodes_type"])) {
        $assetBarcodeData["assetsBarcodes_value"] = mt_rand(1000, 999999); //Duplicate, so generate a hopefully random number as a replacement
    }
    $insert = $DBLIB->insert("assetsBarcodes", $assetBarcodeData);
    //We don't really mind if the insert fails, we can always generate another one later...

    $created[] = ["assets_id" => $result, "assets_tag" => $array['assets_tag']];
}
$DBLIB->commit();

finish(true, null, ["assets_id" => $created[0]['assets_id'], "assets_tag" => $created[0]['assets_tag'], "assetTypes_id" => $array['assetTypes_id'], "assets" => $created]);

/** @OA\Post(
 *     path="/assets/newAssetFromType.php", 
 *     summary="Create Asset From Type", 
 *     description="Creates an asset from an asset type
Requires Instance Permission 17 ASSETS:CREATE
Optional assets_quantity (1-500) in formData creates that many assets with generated tags (a custom assets_tag only with 1), all or nothing. The response adds assets: [{assets_id, assets_tag}] for every asset created.
", 
 *     operationId="createAssetFromType", 
 *     tags={"assets"}, 
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
 *                     property="assets_id", 
 *                     type="integer", 
 *                     description="The ID of the asset",
 *                 ),
 *                 @OA\Property(
 *                     property="assets_tag", 
 *                     type="string", 
 *                     description="The tag of the asset",
 *                 ),
 *                 @OA\Property(
 *                     property="assetTypes_id", 
 *                     type="integer", 
 *                     description="The ID of the asset type",
 *                 ),
 *         ),
 *         ),
 *     ), 
 *     @OA\Response(
 *         response="default", 
 *         description="Error",
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
 *                     property="code", 
 *                     type="string", 
 *                     description="The error code",
 *                 ),
 *                 @OA\Property(
 *                     property="error", 
 *                     type="array", 
 *                     description="An Array containing an error code and a message",
 *                 ),
 *         ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="formData",
 *         in="query",
 *         description="The data to create the asset from",
 *         required="true", 
 *         @OA\Schema(
 *             type="object", 
 *             @OA\Property(
 *                 property="assetTypes_id", 
 *                 type="integer", 
 *                 description="The ID of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assets_tag", 
 *                 type="string", 
 *                 description="The tag of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_notes", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="assets_storageLocation",
 *                 type="integer",
 *                 description="The ID of the location the asset is stored at (optional)",
 *             ),
 *             @OA\Property(
 *                 property="assets_assetGroups",
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_1", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_2", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_3", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_4", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_5", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_6", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_7", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_8", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_9", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_10", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *         ),
 *     ), 
 * )
 */
