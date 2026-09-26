<?php
require_once __DIR__ . '/common/headSecure.php';

if (!$AUTH->instancePermissionCheck("ASSETS:CREATE")) die($TWIG->render('404.twig', $PAGEDATA));
$PAGEDATA['pageConfig'] = ["TITLE" => "Add Asset", "BREADCRUMB" => false];


$DBLIB->where("(manufacturers.instances_id IS NULL OR manufacturers.instances_id = '" . $AUTH->data['instance']['instances_id'] . "')");
$DBLIB->orderBy("manufacturers_name", "ASC");
$PAGEDATA['manufacturers'] = $DBLIB->get('manufacturers', null, ["manufacturers.manufacturers_id", "manufacturers.manufacturers_name"]);

$DBLIB->orderBy("assetCategoriesGroups.assetCategoriesGroups_order", "ASC");
$DBLIB->orderBy("assetCategories.assetCategories_rank", "ASC");
$DBLIB->where("assetCategories.assetCategories_deleted", 0);
$DBLIB->where("(assetCategories.instances_id IS NULL OR assetCategories.instances_id = '" . $AUTH->data['instance']["instances_id"] . "')");
$DBLIB->where("assetCategoriesGroups.assetCategoriesGroups_deleted", 0);
$DBLIB->where("(assetCategoriesGroups.instances_id IS NULL OR assetCategoriesGroups.instances_id = '" . $AUTH->data['instance']["instances_id"] . "')");
$DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
$PAGEDATA['categories'] = $DBLIB->get('assetCategories');

//Storage locations, flattened into tree order with a tier for indenting and a full path so same-named sub-locations can be told apart
$PAGEDATA['locations'] = [];
if ($AUTH->instancePermissionCheck("LOCATIONS:VIEW")) {
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("locations_deleted", 0);
    $DBLIB->where("locations_archived", 0);
    $DBLIB->orderBy("locations_name", "ASC");
    $locations = $DBLIB->get("locations", null, ["locations_id", "locations_name", "locations_subOf"]);
    $locationIds = array_flip(array_column($locations, "locations_id"));
    $locationsBySubOf = [];
    foreach ($locations as $location) {
        //A location whose parent is archived or deleted is listed at the top level rather than hidden
        $subOf = ($location['locations_subOf'] !== null and isset($locationIds[$location['locations_subOf']])) ? $location['locations_subOf'] : 0;
        $locationsBySubOf[$subOf][] = $location;
    }
    $addLocations = function ($subOf, $tier, $parentPath) use (&$addLocations, &$locationsBySubOf, &$PAGEDATA) {
        foreach ($locationsBySubOf[$subOf] ?? [] as $location) {
            $location['tier'] = $tier;
            $location['path'] = $parentPath === null ? $location['locations_name'] : $parentPath . " › " . $location['locations_name'];
            $PAGEDATA['locations'][] = $location;
            $addLocations($location['locations_id'], $tier + 1, $location['path']);
        }
    };
    $addLocations(0, 0, null);
}

$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$assetCapacity = $DBLIB->getvalue("instances", "instances_assetLimit");
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assets_deleted", 0);
$assetUsed = $DBLIB->getValue("assets", "COUNT(assets_id)");
if ($assetCapacity > 0 and $assetUsed >= $assetCapacity) {
    $PAGEDATA['NOASSETCAPACITY'] = [
        "CAPACITY" => $assetCapacity,
        "USED" => $assetUsed
    ];
}

echo $TWIG->render('newAsset.twig', $PAGEDATA);
?>
