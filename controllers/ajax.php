<?php

	if(empty($username)) {
		if(!isset($_REQUEST['username'])){
			$response = array('response_type' => 'json', 'response' => json_encode(array('result' => 401, 'error' => 'Username missing.')));
			return $response;
		}
		$username = $_REQUEST['username'];
	}


// run_example();

	$cache_keys = array(
		'artist_data' => 'artist.data.',
	);
	$cache_expiration_time = 60*60*24*7;

	$artist_data = $cache->get($cache_keys['artist_data'] . $username);

	if(!empty($artist_data)) {
		$streamChartData = get_streamchart_data($artist_data);
		$response = array('response_type' => 'json', 'response' => json_encode($streamChartData));
		return $response;
	}

	$last_stream = new LastStream($cache, $last_fm_key, $last_fm_secret);
	$artist_data = $last_stream->get_artist_data($username);


	$cache->put($cache_keys['artist_data'] . $username, $artist_data, $cache_expiration_time);

	$streamChartData = get_streamchart_data($artist_data);
	$response = array('response_type' => 'json', 'response' => json_encode($streamChartData));
	return $response;


	function get_streamchart_data($artist_data) {
		$date_data = array();
		$stream_chart_data = array(
			'fields' => array(),
			'data' => array(),
			'options' => array(
				'chart_types' => array(),
				'colors' => array(),
				'timeFormat' => "unix-timestamp",
				'dataFrequency' => 'hourly'	//TODO: change to 'weekly'
			)
		);


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
			// Fill each time point with all the artists
// 			foreach($stream_chart_data['fields'] AS $artist) {
// 				if(!isset($artists[$artist])) {
// 					$artists[$artist] = 0;
// 				}
// 			}
			$stream_chart_data['data'][] = array('date' => $ts, 'data' => $artists);
		}
		return $stream_chart_data;
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
