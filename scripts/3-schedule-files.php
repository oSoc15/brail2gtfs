<?php
/**
 * This script populates the following GTFS files: routes.txt, trips.txt, stop_times.txt
 *
 * @author Brecht Van de Vyvere <brecht@iRail.be>
 * @author Pieter Colpaert <pieter@iRail.be>
 * @license MIT
 */
require("vendor/autoload.php");

use iRail\brail2gtfs\RouteFetcher;

$file_routes = "dist/routes.txt";
$file_trips = "dist/trips.txt";
$file_stop_times = "dist/stop_times.txt";

$language = "nn"; // Dutch
// $language = "fr"; // French
// $language = "en"; // English

// Returns hashmap. Key: route_id - Value: array of dates with a distinct service_id
function getRoutesWithDates() {
	$hashmap = array(); // holds route_short_name => array of dates

	if(($handle = fopen('dist/routes_info.tmp.txt', 'r')) !== false)
	{
	    // get the first row, which contains the column-titles (if necessary)
	    $header = fgetcsv($handle);

	    // loop through the file line-by-line
	    while(($line = fgetcsv($handle)) !== false)
	    {
			$route_short_name = $line[0]; // $line is an array of the csv elements
			$service_id = $line[1];
			$date = $line[2];
			
			if (!isset($hashmap[$route_short_name])) {
					$hashmap[$route_short_name] = array();
			}

			// Check if service_id has already been added
			if (!checkForServiceId($hashmap[$route_short_name], $service_id)) {
				$pair = array($date, $service_id);
				array_push($hashmap[$route_short_name], $pair);
			}

	        // I don't know if this is really necessary, but it couldn't harm;
	        // see also: http://php.net/manual/en/features.gc.php
	        unset($line);
	    }
	    fclose($handle);
	}

	return $hashmap;
}

function checkForServiceId($dateServiceIdPairs, $service_id) {
	$contains = false;

	foreach ($dateServiceIdPairs as $pair) {
		// $date = $pair[0];
		$id = $pair[1];
		// Already added
		if($service_id == $id) {
			$contains = true;
		}
	}

	return $contains;
}

function generateTrip($shortName, $service_id, $trip_id) {
	$trip_entry = [
        "@id" => $trip_id, //Sadly, this is only a local identifier
        "@type" => "gtfs:Trip",
        "gtfs:route" => "http://irail.be/routes/NMBS/" . $shortName,
        "gtfs:service" => $service_id, //Sadly, this is only a local identifier, and we use the same id as the trip for service rules
    ];

    return $trip_entry;
}

function appendCSV($dist, $csv) {
	file_put_contents($dist, trim($csv).PHP_EOL, FILE_APPEND);
}

function addRoute($route_entry) {
	$csv = "";
	$csv .= $route_entry["@id"] . ","; // route_id
	$csv .= $route_entry["gtfs:agency"] . ","; // agency_id
	$csv .= $route_entry["gtfs:shortName"] . ","; // route_short_name
	$csv .= $route_entry["gtfs:longName"] . ","; // route_long_name
	$csv .= $route_entry["gtfs:routeType"]; // route_type

	global $file_routes;
	appendCSV($file_routes,$csv);
}

function addTrip($trip) {
	$csv = "";
	$csv .= $trip["gtfs:route"] . ","; // route_id
	$csv .= $trip["gtfs:service"] . ","; // service_id
	$csv .= $trip["@id"]; // trip_id

	global $file_trips;
	appendCSV($file_trips,$csv);
}

function addStopTimes($stop_times) {
	foreach($stop_times as $stop_time) {
		$csv = "";
		$csv .= $stop_time["gtfs:trip"] . ","; // trip_id
		$csv .= $stop_time["gtfs:arrivalTime"] . ","; // arrival_time
		$csv .= $stop_time["gtfs:departureTime"] . ","; // departure_time
		$csv .= $stop_time["gtfs:stop"] . ","; // stop_id
		$csv .= $stop_time["gtfs:stopSequence"]; // stop_sequence

		global $file_stop_times;
		appendCSV($file_stop_times,$csv);
	}
}

function makeHeaders() {
	global $file_routes, $file_trips, $file_stop_times;

	// routes.txt
	$header = "route_id,agency_id,route_short_name,route_long_name,route_type";
	appendCSV($file_routes, $header);

	// trips.txt
	$header = "route_id,service_id,trip_id";
	appendCSV($file_trips, $header);

	// stop_times.txt
	$header = "trip_id,arrival_time,departure_time,stop_id,stop_sequence";
	appendCSV($file_stop_times, $header);
}

// header CSVs
makeHeaders();

$hashmap_route_serviceAndDate = getRoutesWithDates();

foreach ($hashmap_route_serviceAndDate as $route_short_name => $dates_serviceId_pairs) {

	$routeAdded = false; // Route needs to be added just once
	while (count($dates_serviceId_pairs) > 0) {
		$date_service_pair = array_shift($dates_serviceId_pairs);
		$date = $date_service_pair[0];
		$service_id = $date_service_pair[1];

		// 1 - 1 mapping
		$trip_id = $route_short_name . $service_id . '1';

		// processor
		list($route, $stop_times) = RouteFetcher::fetchRouteAndStopTimes($route_short_name, $date, $trip_id, $language);

		// content CSVs
		// routes.txt
		if (!$routeAdded && $route != null) {
			addRoute($route);
			$routeAdded = true;
		}

		// trips.txt
		$trip = generateTrip($route_short_name, $service_id, $trip_id);
        addTrip($trip);
    	
    	// stop_times.txt
    	if ($stop_times != null) {
        	addStopTimes($stop_times);
        }
	}
}
