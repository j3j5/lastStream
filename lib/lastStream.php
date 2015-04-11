<?php
	/**
	 * Library wrapper for the Last.FM API library.
	 * To be used it needs a cache driver and at least the last.fm key
	 *
	 * @author Julio Foulquié
	 * @version 0.1.0
	 *
	 */

use \Dandelionmood\LastFm\LastFm;
use \Illuminate\Cache\CacheManager;
use \Illuminate\Filesystem\Filesystem;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;


class LastStream {

	private $api;
	private $cache;
	private $cache_expiration_time;
	private $cache_keys;
	private $log;
	private $users;

	public function __construct(&$cache, $last_fm_key, $last_fm_secret = '') {
		$this->api = new LastFm($last_fm_key, $last_fm_secret);
		$this->cache = $cache;
		$this->cache_expiration_time = 60*60*24*7;
		$this->cache_keys = array(
			'chart_list' => 'weeklychartlist.',
			'week_list' => 'weeklyartistlist.',
			'user_info' => 'userinfo.',
			'recent_tracks' => 'recenttracks.',
		);

		$this->users = array();

		$this->log = new Logger('last-stream-lib');
		if(PHP_SAPI == 'cli') {
			$this->log->pushHandler(new StreamHandler("php://stdout", Logger::DEBUG));
		} else {
			$this->log->pushHandler(new StreamHandler(dirname(__DIR__) . '/data/logs/last-stream.log', Logger::WARNING));
		}
	}

	public function get_artist_data($username, $from_ts = FALSE, $to_ts = FALSE) {

		if(!$to_ts) {
			$to_ts = time();
		}
		if(!$from_ts) {
			$from_ts = 0;
		}

		// This returns always the same 'from the past', so it just updates the new graphs, cache for a week
		$chart_list = $this->cache->get($this->cache_keys['chart_list'] . $username);

		if(empty($chart_list)) {
			$this->log->addInfo("Rertrieving chart list for $username.");
			try {
				$chart_list = $this->api->user_getweeklychartlist(
					array(
						'user' => $username,
					)
				);
			} catch(Exception $e) {
				$this->handle_lastfm_exceptions($e);
			}
		}

		if(!isset($chart_list->weeklychartlist->chart) OR !is_array($chart_list->weeklychartlist->chart) OR empty($chart_list->weeklychartlist->chart)) {
			$this->log->addError('empty chart list');
			$this->log->addError(print_r($chart_list, TRUE));
			exit;
		}
		$this->cache->put($this->cache_keys['chart_list'] . $username, $chart_list, $this->cache_expiration_time);

		$user_info = $this->get_user_info($username);
		$user_register_ts = $this->get_user_signup_ts($user_info);
		if(empty($user_register_ts)) {
			$this->cache->forget($this->cache_keys['user_info'] . $username);
		}
		$latest_track_ts = $this->get_latest_track_ts($username);

		$artist_data = array();

		$first_chart = TRUE;
		foreach($chart_list->weeklychartlist->chart AS $chart) {
			$date_format = "Y-m-d H:i:s";

			// Don't process dates earlier than user's sign up
			if($user_register_ts > $chart->to) {
				$this->log->addDebug("chart is earlier than the user's registration: " . date($date_format, $chart->to));
				continue;
			// Don't process dates later than user's last scrobble
			} elseif($chart->from > $latest_track_ts) {
				$this->log->addDebug("chart is older than the user's last scrobbled track: " . date($date_format, $chart->from));
				continue;
			// Don't process dates earlier than requested period
			} elseif($from_ts > $chart->to) {
				$this->log->addDebug("chart is earlier than requested period: " . date($date_format, $chart->to));
				continue;
			// Don't process dates later than requested period
			} elseif($chart->from > $to_ts) {
				$this->log->addDebug("chart is later than requested period: " . date($date_format, $chart->to));
				continue;
			}

			$this->log->addInfo("FROM: " . date($date_format, $chart->from));
			$this->log->addInfo("TO: " . date($date_format, $chart->to));

			$weekly_list = $this->cache->get($this->cache_keys['week_list'] . $username . '.' . $chart->from . '.' . $chart->to);
			if(empty($weekly_list)) {
				$this->log->addInfo("Rertrieving weekly artist chart for $username.");
				try {
					$weekly_list = $this->api->user_getweeklyartistchart(
						array(
							'user'	=> $username,
							'from'	=> $chart->from,
							'to'	=> $chart->to,
						),
						FALSE
					);
				} catch(Exception $e) {
					$this->handle_lastfm_exceptions($e);
					$weekly_list = 1;
				}
				// Cache the errors as well
				$this->cache->forever($this->cache_keys['week_list'] . $username . '.' . $chart->from . '.' . $chart->to, $weekly_list);
			}
			if(!isset($weekly_list->weeklyartistchart->artist) OR !is_array($weekly_list->weeklyartistchart->artist) OR empty($weekly_list->weeklyartistchart->artist)) {
				$this->log->addDebug('empty artist list, skipping');
				continue;
			}

			$chart_list_ts = $this->get_middle_point_ts($chart->from, $chart->to);

			$this->log->addInfo(count($weekly_list->weeklyartistchart->artist) . " artists found, storing...");

			// Fill chart data with this chart's info
			foreach($weekly_list->weeklyartistchart->artist AS $artist) {
				$artist_data[$artist->name][] = array('count' => $artist->playcount, 'date' => $chart_list_ts);
			}
		}
		return $artist_data;
	}

	private function get_user_info($username) {
		// Get user's info so we know when it signed up
		$user_info = $this->cache->get($this->cache_keys['user_info'] . $username);
		if(empty($user_info)) {
			$this->log->addInfo("Rertrieving user info for $username.");
			try {
				$user_info = $this->api->user_getinfo(
					array(
						'user' => $username,
						)
						);
						$this->cache->forever($this->cache_keys['user_info']. $username, $user_info);
			} catch(Exception $e) {
				$this->handle_lastfm_exceptions($e);
			}
		}
		return $user_info;
	}

	private function get_user_signup_ts($user_info) {
		if(!isset($user_info->user->registered->unixtime) OR empty($user_info->user->registered->unixtime)) {
			$username = $user_info->user->name;
			$this->log->addError("Unknown signup date for the user $username");
			return 0;
		}
		return $user_info->user->registered->unixtime;
	}

	private function get_latest_track_ts($username) {
		$recent_tracks = $this->cache->get($this->cache_keys['recent_tracks'] . $username);
		if(empty($recent_tracks)) {
			$this->log->addInfo("Rertrieving recent tracks for $username.");
			try {
				$recent_tracks = $this->api->user_getrecenttracks(
					array(
						'user' => $username,
					)
				);
				$this->cache->put($this->cache_keys['recent_tracks']. $username, $recent_tracks, $this->cache_expiration_time);
			} catch(Exception $e) {
				$this->handle_lastfm_exceptions($e);
			}
		}
		$latest_track_ts= time();
		$latest_track = reset($recent_tracks->recenttracks->track);
		if(isset($latest_track->date->uts) && !empty($latest_track->date->uts)) {
			$latest_track_ts = $latest_track->date->uts;
		}
		return $latest_track_ts;
	}

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
	private function handle_lastfm_exceptions($e) {
		$error_pattern = "/\[(\d{0,2})\|(.*)\][ \n]*(.*)/";
		$indexes = array('error_code' => 1, 'error_msg' => 2, 'uri' => 3);
		$matches = array();
		if(preg_match($error_pattern, $e->getMessage(), $matches)) {
			if(!isset($matches[$indexes['error_code']]) OR empty($matches[$indexes['error_code']])) {
				$this->log->addError('UNKNOWN PATTERN ERROR: ' . $e->getMessage() );
				exit;
			}

			switch($matches[$indexes['error_code']]) {
				case 8:
					// Esto peeeetaaaa (error that the API throws on some legit calls, just ignore it)
					$this->log->addError("API call failed: http://ws.audioscrobbler.com/2.0/?" . trim($matches[$indexes['uri']]));
					$this->log->addError($matches[$indexes['error_msg']]);
// 					$this->log->addError( $e->getMessage() );
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
					$this->log->addError('UNHANDLED ERROR: ' . $e->getMessage());
					$this->log->addError("API call failed: http://ws.audioscrobbler.com/2.0/?" . $matches[$indexes['uri']]);
					exit;
					break;
			}
		}
		$this->log->addError('UNKNOWN ERROR: ' . $e->getMessage());
		$this->log->addError("API call failed: http://ws.audioscrobbler.com/2.0/?" . $matches[$indexes['uri']]);
		exit;
	}

	private function get_middle_point_ts($from, $to) {
		return $to - (($to - $from) / 2);
	}

}
