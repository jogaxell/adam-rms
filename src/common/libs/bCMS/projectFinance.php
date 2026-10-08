<?php

use Money\Money;
use Money\Currency;

class projectFinance
{
  public function durationMathsByDates($start, $end)
  {
    $start = strtotime(date("d F Y 00:00:00", strtotime($start)));
    $end = strtotime(date("d F Y 23:59:59", strtotime($end)));
    $diff = ceil(($end - $start) / 86400);
    if ($diff < 1) $diff = 1;
    return ["days" => $diff, "weeks" => 0, "calendarDays" => $diff];
  }
  public function durationMaths($projects_id)
  {
    global $DBLIB;
    $DBLIB->where("projects_id", $projects_id);
    $project = $DBLIB->getone("projects", ["projects_dates_finances_days", "projects_dates_finances_weeks", "projects_dates_deliver_start", "projects_dates_deliver_end"]);
    if (!$project) return false;

    if ($project['projects_dates_finances_days'] !== NULL and $project['projects_dates_finances_weeks'] !== NULL) {
      $rawDays = $this->durationMathsByDates($project['projects_dates_deliver_start'], $project['projects_dates_deliver_end']);
      return ["days" => $project['projects_dates_finances_days'], "weeks" => $project['projects_dates_finances_weeks'], "calendarDays" => $rawDays['days']];
    } else {
      return $this->durationMathsByDates($project['projects_dates_deliver_start'], $project['projects_dates_deliver_end']);
    }
  }
  /**
   * Updates a project's finance cache when an assignment moves from one asset to another of the same type.
   * Either asset can have its own rates, value and mass, so the old asset's are taken off and the new one's added.
   * @param int $projects_id The project the assignment belongs to
   * @param array $assignment assetsAssignments_customPrice and assetsAssignments_discount of the assignment
   * @param array $oldAsset assets_dayRate, assets_weekRate, assets_value and assets_mass of the asset it moves from
   * @param array $newAsset The same fields for the asset it moves to
   * @param array $assetType assetTypes_dayRate, assetTypes_weekRate, assetTypes_value and assetTypes_mass of their type
   * @return bool Whether the finance cache saved
   */
  public function swapAssignmentAsset($projects_id, $assignment, $oldAsset, $newAsset, $assetType)
  {
    global $AUTH;
    $currency = new Currency($AUTH->data['instance']['instances_config_currency']);
    $priceMaths = $this->durationMaths($projects_id);
    $projectFinanceCacher = new projectFinanceCacher($projects_id);
    foreach ([[$oldAsset, true], [$newAsset, false]] as [$asset, $subtract]) {
      $projectFinanceCacher->adjust('projectsFinanceCache_mass', ($asset['assets_mass'] !== null ? $asset['assets_mass'] : $assetType['assetTypes_mass']), $subtract);
      $projectFinanceCacher->adjust('projectsFinanceCache_value', new Money(($asset['assets_value'] !== null ? $asset['assets_value'] : $assetType['assetTypes_value']), $currency), $subtract);
      if ($assignment['assetsAssignments_customPrice'] == null) { // A custom price stays the same whichever asset it's for
        $price = new Money(null, $currency);
        $price = $price->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $assetType['assetTypes_dayRate']), $currency))->multiply($priceMaths['days']));
        $price = $price->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $assetType['assetTypes_weekRate']), $currency))->multiply($priceMaths['weeks']));
        $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price, $subtract);
        if ($assignment['assetsAssignments_discount'] > 0) $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($assignment['assetsAssignments_discount'] / 100))), $subtract);
      }
    }
    return $projectFinanceCacher->save();
  }
}
class projectFinanceCacher
{
  //This class assumes that the projectid has been validated as within the instance
  private $data, $projectid;
  private $changesMade = false;
  public function __construct($projectid)
  {
    global $AUTH;
    //Reset the data
    $this->projectid = $projectid;
    $this->data = [
      "projectsFinanceCache_equipmentSubTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_equiptmentDiscounts" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_salesTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_staffTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_externalHiresTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_paymentsReceived" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_value" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),
      "projectsFinanceCache_mass" => 0.0
    ];
  }
  public function save()
  {
    //Process the changes at the end of the script
    global $DBLIB;
    if ($this->changesMade) {
      $dataToUpload = [];
      foreach ($this->data as $key => $value) {
        //Put it into a format for mysql
        if ($key != 'projectsFinanceCache_mass') $value = $value->getAmount();
        if ($value != 0) $dataToUpload[$key] = $DBLIB->inc($value);
      }
      $dataToUpload['projectsFinanceCache_timestampUpdated'] = date("Y-m-d H:i:s");
      $dataToUpload['projectsFinanceCache_equiptmentTotal'] = $DBLIB->inc($this->data["projectsFinanceCache_equipmentSubTotal"]->subtract($this->data['projectsFinanceCache_equiptmentDiscounts'])->getAmount());
      $dataToUpload['projectsFinanceCache_grandTotal'] = $DBLIB->inc((($this->data["projectsFinanceCache_equipmentSubTotal"]->subtract($this->data['projectsFinanceCache_equiptmentDiscounts']))->add($this->data['projectsFinanceCache_salesTotal'], $this->data['projectsFinanceCache_staffTotal'], $this->data["projectsFinanceCache_externalHiresTotal"])->subtract($this->data['projectsFinanceCache_paymentsReceived']))->getAmount());
      $DBLIB->where("projects_id", $this->projectid);
      $DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
      return $DBLIB->update("projectsFinanceCache", $dataToUpload, 1); //Update the most recent cache datapoint
    } else return true;
  }
  public function adjust($key, $value, $subtract = false)
  {
    if ($key == 'projectsFinanceCache_mass' and ($value !== 0 or $value !== null)) {
      $this->changesMade = true;
      if ($subtract) $value = -1 * $value;
      $this->data[$key] += $value;
    } else {
      $this->changesMade = true;
      //Ensure value is a Money object
      if (!($value instanceof Money)) {
        global $AUTH;
        $value = new Money($value, new Currency($AUTH->data['instance']['instances_config_currency']));
      }
      if ($subtract) {
        $this->data[$key] = $this->data[$key]->subtract($value);
      } else {
        $this->data[$key] = $this->data[$key]->add($value);
      }
    }
  }
  public function adjustPayment($paymentType, $value, $subtract = false)
  {
    switch ($paymentType) {
      case 1:
        $key = 'projectsFinanceCache_paymentsReceived';
        break;
      case 2:
        $key = 'projectsFinanceCache_salesTotal';
        break;
      case 3:
        $key = 'projectsFinanceCache_externalHiresTotal';
        break;
      case 4:
        $key = 'projectsFinanceCache_staffTotal';
        break;
      default:
        return false;
    }
    return $this->adjust($key, $value, $subtract);
  }
}
