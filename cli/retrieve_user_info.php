<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;


	$log->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));

	if(empty($username)) {
		$log->addError("Empty username, please fill one.");
		exit;
	}

	$cache_keys = array(
		'artist_data' => 'artist.data.',
	);
	$cache_expiration_time = 60*60*24*1;

	if(!empty($from)) {
		$from_ts = strtotime($from);
	} else {
		$from_ts = 0;
	}

	if(!empty($to)) {
		$to_ts = strtotime($to);
	} else {
		$to_ts = time();
	}

// 	$artist_data = $cache->get($cache_keys['artist_data'] . $username);
	$artist_data = FALSE;
	if(!empty($artist_data)) {
		$log->addInfo("The user is on the cache.");
// 		$log->addInfo(print_r($artist_data, TRUE));
// 		$data = get_streamchart_data($artist_data);
// 		echo json_encode($data['data']);
		exit;
	}

	$log->addInfo("The user is NOT on the cache, retrieving...");
	$last_stream = new LastStream($cache, $last_fm_key, $last_fm_secret);
	$artist_data = $last_stream->get_artist_data($username, $from_ts, $to_ts);


	$cache->put($cache_keys['artist_data'] . $username, $artist_data, $cache_expiration_time);
	$log->addInfo("Done");
// 	echo json_encode(get_streamchart_data($artist_data));
	exit;

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
// 			foreach($stream_chart_data['fields'] AS $artist) {
// 				if(!isset($artists[$artist])) {
// 					$artists[$artist] = 0;
// 				}
// 			}
			$stream_chart_data['data'][] = array('date' => $ts, 'data' => $artists);
		}
		return $stream_chart_data;
	}
