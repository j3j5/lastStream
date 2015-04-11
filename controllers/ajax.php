<?php

use \Dandelionmood\LastFm\LastFm;
use \Illuminate\Cache\CacheManager;
use \Illuminate\Filesystem\Filesystem;


	if(empty($username)) {
		if(!isset($_REQUEST['username'])){
			$response = array('response_type' => 'json', 'response' => json_encode(array('result' => 401, 'error' => 'Username missing.')));
			return $response;
		}
		$username = $_REQUEST['username'];
	}


// run_example();

	$app = array(
			'config' => array(
				'cache.driver' => 'file',
				'cache.path' => dirname(__DIR__) . '/data/cache',
				'cache.prefix' => '_'
			),
			'files' => new Filesystem(),
	);

	$cacheManager = new CacheManager($app);
	$cache = $cacheManager->driver();

	$cache_keys = array(
		'chart_list' => 'weeklychartlist.',
		'artist_data' => 'artist.data.',
	);
	$cache_expiration_time = 60*60*24*7;

	$artist_data = $cache->get($cache_keys['artist_data'] . $username);

	if(!empty($artist_data)) {
		$streamChartData = get_streamchart_data($artist_data);
		$response = array('response_type' => 'json', 'response' => json_encode($streamChartData));
		return $response;
	}

	$api = new LastFm($last_fm_key, $last_fm_secret);

	$chart_list = $cache->get($cache_keys['chart_list'] . $username);

	if(empty($chart_list)) {
		try {
			$chart_list = $api->user_getweeklychartlist(
				array(
					'user' => $username,
				)
			);
		} catch(Exception $e) {
			handle_lastfm_exceptions($e);
		}
	}

	if(!isset($chart_list->weeklychartlist->chart) OR !is_array($chart_list->weeklychartlist->chart) OR empty($chart_list->weeklychartlist->chart)) {
		var_dump('empty chart list');
		exit;
	}

	$cache->put($cache_keys['chart_list'] . $username, $chart_list, $cache_expiration_time);

	$chart_data = array(
		'fields' => array(),
		'data' => array(),
		'options' => array(
			'chart_types' => array(),
			'colors' => array(),
			'timeFormat' => "unix-timestamp",
			'dataFrequency' => 'hourly'	//TODO: change to 'weekly'
		)
	);
	$artist_data = array();

	$first_chart = TRUE;
	foreach($chart_list->weeklychartlist->chart AS $chart) {
		if($first_chart) {
			$initial_ts = $chart->from;
			$ts = $initial_ts;
			// What is this?
			$ts_step = 60 * 5;

			$first_chart = FALSE;
		}

		$date_format = "Y-m-d H:i:s";
		var_dump("FROM: " . date($date_format, $chart->from));
		var_dump("TO: " . date($date_format, $chart->to));
		var_dump('</br>');
		var_dump('</br>');

		try {
			$weekly_list = $api->user_getweeklyartistchart(
				array(
					'user'	=> $username,
					'from'	=> $chart->from,
					'to'	=> $chart->to,
				),
				FALSE
			);
		} catch(Exception $e) {
			handle_lastfm_exceptions($e);
		}
		if(!isset($weekly_list->weeklyartistchart->artist) OR !is_array($weekly_list->weeklyartistchart->artist) OR empty($weekly_list->weeklyartistchart->artist)) {
			var_dump('empty_list');
			continue;
		}

		$chart_list_ts = get_midlle_point_ts($chart->from, $chart->to);

		// Fill chart data with this chart's info
		foreach($weekly_list->weeklyartistchart->artist AS $artist) {
			$artist_data[$artist->name][] = array('count' => $artist->playcount, 'date' => $chart_list_ts);
		}
	}
	$cache->put($cache_keys['artist_data'] . $username, $artist_data, $cache_expiration_time);

	$streamChartData = get_streamchart_data($artist_data);
	$response = array('response_type' => 'json', 'response' => json_encode($streamChartData));
	return $response;
/**
 *
 *    2 : Invalid service - This service does not exist
 *    3 : Invalid Method - No method with that name in this package
 *    4 : Authentication Failed - You do not have permissions to access the service
 *    5 : Invalid format - This service doesn't exist in that format
 *    6 : Invalid parameters - Your request is missing a required parameter
 *    7 : Invalid resource specified
 *    8 : Operation failed - Something else went wrong
 *    9 : Invalid session key - Please re-authenticate
 *    10 : Invalid API key - You must be granted a valid key by last.fm
 *    11 : Service Offline - This service is temporarily offline. Try again later.
 *    13 : Invalid method signature supplied
 *    16 : There was a temporary error processing your request. Please try again
 *    26 : Suspended API key - Access for your account has been suspended, please contact Last.fm
 *    29 : Rate limit exceeded - Your IP has made too many requests in a short period
 *
 *
 */

	function handle_lastfm_exceptions($e) {
		$error_pattern = "/\[(\d{0,2})\|.*\]/";
		$matches = array();
		if(preg_match($error_pattern, $e->getMessage(), $matches)){
			if(!isset($matches[1]) OR empty($matches[1])) {
				var_dump('UNKNOWN PATTERN ERROR');
				var_dump($e->getMessage());
				exit;
			}

			switch($matches[1]) {
				case 8:
					// Esto peeeetaaaa (error that the API throws on some legit calls, just ignore it)
					return;
				case 2:
				case 3:
				case 4:
				case 5:
				case 6:
				case 7:
				case 9:
				case 10:
				case 11:
				case 13:
				case 16:
				case 26:
				case 29:
				default:
					var_dump('UNKNOWN ERROR');
					var_dump($e->getMessage());
					exit;
					break;
			}
		}
		var_dump($e->getMessage());
		exit;
	}

	function get_streamchart_data($artist_data) {
		$date_data = array();
		foreach($artist_data AS $name => $artist_data_points) {
			$stream_chart_data['fields'][] = $name;
			$stream_chart_data['options']['chart_types'][] = 'streamGraph';

			$ts_data = array();
			foreach($artist_data_points AS $data_point) {
				$date_data[$data_point['date']][$name] = (int)$data_point['count'];
			}
		}

		ksort($date_data);

		foreach($date_data AS $ts => $artists) {
			$stream_chart_data['data'][] = array('date' => $ts, 'data' => $artists);
		}
		return $stream_chart_data;
	}

	function get_midlle_point_ts($from, $to) {
		return $to - (($to - $from) / 2);
	}

	function run_example() {
		$tracker_ids = array('a', 'b', 'c', 'd');
		$streamChartData = array(
			'fields' => array(),
			'data' => array(),
			'options' => array(
				'chart_types' => array(),
				'colors' => array(),
				'timeFormat' => "unix-timestamp",
				'dataFrequency' => 'hourly'
			)
		);

		$event_start = new DateTime('2015-02-01 18:30:00', new DateTimeZone('America/New_York')); // America/New_York == EST
		$event_start->sub(new DateInterval('PT1H')); // Tracking starts 1 hour before event, removing 1 hour.

		$initial_ts = $event_start->getTimestamp();

		$ts = $initial_ts;
		$ts_step = 60 * 5;
		$num_points = 84;

		foreach ( $tracker_ids as $tracker_id ) {
			$streamChartData['fields'][] = (string)$tracker_id;
			$streamChartData['options']['chart_types'][] = 'streamGraph';
		}

		for ( $i = 0 ; $i < $num_points ; $i++ ) {
			$ts_data = array(
				'date' => $ts,
				'data' => array()
			);
			foreach ( $tracker_ids as $tracker_id ) {
				$ts_data['data'][$tracker_id] = rand(100,200);
			}
			$streamChartData['data'][] = $ts_data;
			$ts += $ts_step;
		}


		header("HTTP/1.1 200 Ok");
		header('Content-type: application/json');
		print_r( json_encode($streamChartData) );

		exit(0);
	}
