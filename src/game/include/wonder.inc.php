<?php
/*
 * wonder.inc.php -
 * Copyright (c) 2004  OGP Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 */

/** ensure this file is being included by a parent file */
defined('_VALID_UA') or die('Direct Access to this location is not allowed.');

class WonderTarget {

  function getWonderTargets() {
    static $result = NULL;

    if ($result === NULL) {
      $result = array("same"  => _('Wirkungshöle'),
                      "own"   => _('eigene Höhlen'),
                      "other" => _('fremde Höhlen'),
                      "all"   => _('jede Höhle'));
    }

    return $result;
  }
}

init_Wonders();

function wonder_getActiveWondersForCaveID($caveID) {
  global $db;
  $sql = $db->prepare("SELECT * 
                       FROM ". EVENT_WONDER_END_TABLE . "
                       WHERE caveID = :caveID 
                       ORDER BY end");
  $sql->bindValue('caveID', $caveID, PDO::PARAM_INT);

  if (!$sql->execute() || $sql->rowCount() == 0) {
    return;
  }

  $wonders = array();
  while($row = $sql->fetch(PDO::FETCH_ASSOC)) {
    $row['end_time'] = time_formatDatetime($row['end']);
    $wonders[] = $row;
  }

  return $wonders;
}

function wonder_recalc($caveID) {
  global $db, $effectTypeList;

  $fields = array();
  foreach($effectTypeList AS $effectID => $data) {
    array_push($fields,
         "SUM(".$data->dbFieldName.") AS ".$data->dbFieldName);
  }

  $fields = implode(", ", $fields);

  $sql = $db->prepare("SELECT :fields
                       FROM ". EVENT_WONDER_END_TABLE . " 
                       WHERE caveID = :caveID");
  $sql->bindValue('fields', $fields, PDO::PARAM_STR);
  $sql->bindValue('caveID', $caveID, PDO::PARAM_INT);

  if (!$sql->execute()) {
    echo ("Error: Couldn't get Event_wonderEnd entries for the specified cave.");
    exit -1;
  }
  if (!($row = $sql->fetch(PDO::FETCH_ASSOC))) {
    echo ("Error: Result was empty when trying to get event.");
    exit -1;
  }

  $effects = array();
  foreach($effectTypeList AS $effectID => $data) {
    $effects[$effectID] = $row[$data->dbFieldName];
  }

  return $effects;
}

function wonder_processOrder($playerID, $wonderID, $caveID, $coordX, $coordY, $caveData) {

  global
    $defenseSystemTypeList,
    $unitTypeList,
    $buildingTypeList,
    $scienceTypeList,
    $resourceTypeList,
    $wonderTypeList,
    $WONDERRESISTANCE,
    $db;

  if ($wonderTypeList[$wonderID]->target == "same") {
    $targetID = $caveID;
    $sql = $db->prepare("SELECT * FROM ". CAVE_TABLE ." 
                         WHERE caveID = :targetID");
    $sql->bindValue('targetID', $targetID);
    
    if (!$sql->execute() || !($targetData = $sql->fetch(PDO::FETCH_ASSOC))) {
      return -3;
    }
    $sql->closeCursor();
    $coordX = $targetData['xCoord'];
    $coordY = $targetData['yCoord'];
  } else {

    // check the target cave
    $sql = $db->prepare("SELECT * FROM ". CAVE_TABLE . " 
                        WHERE xCoord = :coordX
                        AND yCoord = :coordY");
    $sql->bindValue('coordX', $coordX, PDO::PARAM_INT);
    $sql->bindValue('coordY', $coordY, PDO::PARAM_INT);

    if (!$sql->execute() || !($targetData = $sql->fetch(PDO::FETCH_ASSOC))) {
      return -3;
    }
    $sql->closeCursor();
    $targetID = $targetData['caveID'];
  }

  // check, if cave allowed

  if ($wonderTypeList[$wonderID]->target == "own") {
    $allowed = $playerID == $targetData['playerID'];
  }
  else if ($wonderTypeList[$wonderID]->target == "other") {
    $allowed = $playerID != $targetData['playerID'];
  }
  else {      // $wonderTypeList[$wonderID]->target == "all"  or == "same"
    $allowed = 1;
  }
  if (!$allowed) {
    return -2;
  }

  // take production costs from cave
  if (!processProductionCost($wonderTypeList[$wonderID], $caveID, $caveData))
    return 0;
  // calculate the chance and evaluate into $chance
  if ($chance_formula = $wonderTypeList[$wonderID]->chance) {
    $chance_eval_formula = formula_parseToPHP($chance_formula, '$caveData');

    $chance_eval_formula="\$chance=$chance_eval_formula;";
    eval($chance_eval_formula);
  }

  // if this wonder is offensive
  // calculate the wonder resistance and evaluate into $resistance
  // TODO: Wertebereich der Resistenz ist derzeit 0 - 1, also je höher desto resistenter
  if ($wonderTypeList[$wonderID]->offensiveness == "offensive"){
    $resistance_eval_formula = formula_parseToPHP($WONDERRESISTANCE, '$targetData');
    $resistance_eval_formula = "\$resistance=$resistance_eval_formula;";
    eval($resistance_eval_formula);
  } else {
    $resistance = 0.0;
  }

  // does the wonder fail?
  if (((double)rand() / (double)getRandMax()) > ($chance - $resistance)) {
    return 2;          // wonder did fail
  }

  // schedule the wonder's impacts

  // create a random factor between -0.3 and +0.3
  $delayRandFactor = (rand(0,getrandmax()) / getrandmax()) * 0.6 - 0.3;
  // now calculate the delayDelta depending on the first impact's delay
  $delayDelta =
    $wonderTypeList[$wonderID]->impactList[0]['delay'] * $delayRandFactor;

  foreach($wonderTypeList[$wonderID]->impactList AS $impactID => $impact) {
    $delay = (int)(($delayDelta + $impact['delay']) * WONDER_TIME_BASE_FACTOR);

    $now = time();
    $sql = $db->prepare("INSERT INTO ". EVENT_WONDER_TABLE ." (casterID, sourceID, targetID, 
                     wonderID, impactID, start, end) 
                     VALUES (:playerID, :caveID, :targetID, :wonderID, :impactID, :start, :end)");
    $sql->bindValue('playerID', $playerID, PDO::PARAM_INT);
    $sql->bindValue('caveID', $caveID, PDO::PARAM_INT);
    $sql->bindValue('targetID', $targetID, PDO::PARAM_INT);
    $sql->bindValue('wonderID', $wonderID, PDO::PARAM_INT);
    $sql->bindValue('impactID', $impactID, PDO::PARAM_INT);
    $sql->bindValue('start', time_toDatetime($now), PDO::PARAM_STR);
    $sql->bindValue('end', time_toDatetime($now + $delay), PDO::PARAM_STR);

    
    if (!$sql->execute()) {
      //give production costs back
      processProductionCostSetBack($wonderTypeList[$wonderID], $caveID, $caveData);
      return -1;
    }
  }

  // create messages
  $messageClass = new Messages;
  $sourceMessage =
    "Sie haben auf die H&ouml;hle in $coordX/$coordY ein Wunder ".
    $wonderTypeList[$wonderID]->name." erwirkt.";
  $targetMessage =
    "Der Besitzer der H&ouml;hle in {$caveData['xCoord']}/{$caveData['yCoord']} ".
    "hat auf Ihre H&ouml;hle in $coordX/$coordY ein Wunder gewirkt.";

  $messageClass->sendSystemMessage($playerID, 9,
           "Wunder erwirkt auf $coordX/$coordY",
           $sourceMessage);
  $messageClass->sendSystemMessage($targetData['playerID'], 9,
           "Wunder!",
           $targetMessage);

  return 1;
}

?>