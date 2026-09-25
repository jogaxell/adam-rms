<?php
require_once __DIR__ . '/../apiHeadSecure.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;
if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_PAYMENTS:CREATE") or !isset($_POST['formData'])) die("404");

$array = [];
foreach ($_POST['formData'] as $item) {
    $array[$item['name']] = $item['value'];
}
if (!isset($array['payments_id']) or strlen($array['payments_id']) < 1) finish(false, ["code" => "PARAM-ERROR", "message"=> "No data for action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("payments.payments_deleted", 0);
$DBLIB->where("payments.payments_type", [2, 3, 4], "IN"); //Sales, additional hires and staff only
$DBLIB->join("projects", "payments.projects_id=projects.projects_id", "LEFT");
$DBLIB->where("payments.payments_id", $array['payments_id']);
$payment = $DBLIB->getone("payments", ["payments.payments_id", "payments.projects_id", "payments_type", "payments_amount", "payments_quantity"]);
if (!$payment) finish(false);

$currency = new Currency($AUTH->data['instance']['instances_config_currency']);
$moneyParser = new DecimalMoneyParser(new ISOCurrencies());
$update = [
    "payments_quantity" => ($array['payments_quantity'] ?? null) ?: 1,
    "payments_supplier" => $array['payments_supplier'] ?? null,
    "payments_comment" => $array['payments_comment'] ?? null,
    "payments_amount" => $moneyParser->parse(($array['payments_amount'] ?? null) ?: "0", $currency)->getAmount(),
];

$projectFinanceCacher = new projectFinanceCacher($payment['projects_id']);

$DBLIB->where("payments_id", $payment['payments_id']);
if (!$DBLIB->update("payments", $update)) finish(false);

$oldAmount = (new Money($payment['payments_amount'], $currency))->multiply($payment['payments_quantity']);
$newAmount = (new Money($update['payments_amount'], $currency))->multiply($update['payments_quantity']);
$projectFinanceCacher->adjustPayment($payment['payments_type'], $oldAmount, true);
$projectFinanceCacher->adjustPayment($payment['payments_type'], $newAmount, false);

$bCMS->auditLog("UPDATE", "payments", $payment['payments_id'], $AUTH->data['users_userid'], null, $payment['projects_id']);

if ($projectFinanceCacher->save()) finish(true);
else finish(false,["message"=>"Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/editPayment.php",
 *     summary="Edit Payment",
 *     description="Edit a project sales item, additional hire or staff cost
Requires Instance Permission PROJECTS:PROJECT_PAYMENTS:CREATE
",
 *     operationId="editPayment",
 *     tags={"projects"},
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
 *             ),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="formData",
 *         in="query",
 *         description="Form Data",
 *         required="true",
 *         @OA\Schema(
 *             type="object",
 *             @OA\Property(
 *                 property="payments_id",
 *                 type="number",
 *                 description="Payment ID",
 *             ),
 *             @OA\Property(
 *                 property="payments_quantity",
 *                 type="number",
 *                 description="Payment Quantity",
 *             ),
 *             @OA\Property(
 *                 property="payments_amount",
 *                 type="string",
 *                 description="Payment Amount",
 *             ),
 *             @OA\Property(
 *                 property="payments_supplier",
 *                 type="string",
 *                 description="Supplier",
 *             ),
 *             @OA\Property(
 *                 property="payments_comment",
 *                 type="string",
 *                 description="Description",
 *             ),
 *         ),
 *     ),
 * )
 */
