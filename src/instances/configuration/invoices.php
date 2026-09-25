<?php
require_once __DIR__ . '/../../common/headSecure.php';

$PAGEDATA['pageConfig'] = ["TITLE" => "Business Invoice Settings", "BREADCRUMB" => false];

if (!$AUTH->instancePermissionCheck("BUSINESS:BUSINESS_SETTINGS:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));

$PAGEDATA['DOCUMENTDEFAULTS'] = [
    "types" => bCMS::PROJECT_DOCUMENT_TYPES,
    "options" => $bCMS->projectDocumentOptions(),
    "current" => $bCMS->projectDocumentDefaults($AUTH->data['instance']),
];


echo $TWIG->render('instances/configuration/instances_configuration_invoices.twig', $PAGEDATA);
?>
