<?php

// Fetches all the updates from Connec! since last synchronization timestamp

if(!Maestrano::param('connec.enabled')) { return false; }

set_time_limit(0);

// Set default user for entities creation
global $current_user;
if(is_null($current_user)) { $current_user = (object) array(); }
if(!isset($current_user->id)) {
  $current_user->id = '1';
  $current_user->date_format = 'Y-m-d';
}

// Last update timestamp
$timestamp = lastDataUpdateTimestamp();
$date = date('c', $timestamp);
$current_timestamp = round(microtime(true));

error_log("Fetching data updates since $date");

// Fetch updates
$client = new Maestrano_Connec_Client();
$subscriptions = Maestrano::param('webhook.connec.subscriptions');
foreach ($subscriptions as $entity => $enabled) {
  if(!$enabled) { continue; }
  
  // Fetch first page of entities since last update timestamp
  $params = array("\$filter" => "updated_at gte '$date'");
  $result = fetchData($client, $entity, $params);
  // Fetch next pages
  while(array_key_exists('pagination', $result) && array_key_exists('next', $result['pagination'])) {
    $result = fetchData($client, $result['pagination']['next']);
  }
}

// Set update timestamp
setLastDataUpdateTimestamp($current_timestamp);

// Fetches and import data from specified entity
function fetchData($client, $entity, $params=array(), $retries=0) {
  // Retry 5 times before failing
  if($retries >= 5) {
    error_log('Cannot fetch data due to previous errors, exiting');
    exit(1);
  }

  try {
    $msg = $client->get($entity, $params);
    $code = $msg['code'];
    $body = $msg['body'];

    if($code != 200) {
      throw new Exception("Cannot fetch connec entities=$entity, code=$code, retries=$retries");
    } else {
      error_log("Received entities=$entity, code=$code");
      $result = json_decode($body, true);

      // Dynamically find mappers and map entities
      foreach(BaseMapper::getMappers() as $mapperClass) {
        if (class_exists($mapperClass)) {
          $test_class = new ReflectionClass($mapperClass);
          if($test_class->isAbstract()) { continue; }

          $mapper = new $mapperClass();
          $mapper->persistAll($result[$mapper->getConnecResourceName()]);
        }
      }

      return $result;
    }
  } catch (Exception $e) {
    // Retry with an incremental delay
    error_log("Cannot update entities: " . $e->getMessage());
    sleep($retries * 5);
    return fetchData($client, $entity, $params, $retries+1);
  }
}