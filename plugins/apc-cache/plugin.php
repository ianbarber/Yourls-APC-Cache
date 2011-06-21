<?php
/*
Plugin Name: APC Cache
Plugin URI: http://virgingroupdigital.wordpress.com
Description: Caches most database traffic at the expense of some accuracy
Version: 0.3.1
Author: Ian Barber <ian.barber@gmail.com>
Author URI: http://phpir.com/
*/

// Verify APC is installed, suggested by @ozh
if( !function_exists( 'apc_exists' ) ) {
   yourls_die( 'This plugin requires the APC extension: http://pecl.php.net/package/APC' );
}

if(!defined('APC_WRITE_CACHE_TIMEOUT')) {
	define('APC_WRITE_CACHE_TIMEOUT', 120);
}
if(!defined('APC_READ_CACHE_TIMEOUT')) {
	define('APC_READ_CACHE_TIMEOUT', 360);
}
define('APC_CACHE_LOG_INDEX', 'cachelogindex');
define('APC_CACHE_LOG_TIMER', 'cachelogtimer');
define('APC_CACHE_ALL_OPTIONS', 'cache-get_all_options');
if(!defined('APC_CACHE_LONG_TIMEOUT')) {
	define('APC_CACHE_LONG_TIMEOUT', 86400);
}

yourls_add_action( 'pre_get_keyword', 'apc_cache_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'apc_cache_get_keyword_infos' );
yourls_add_filter( 'shunt_update_clicks', 'apc_cache_shunt_update_clicks' );
yourls_add_filter( 'shunt_log_redirect', 'apc_cache_shunt_log_redirect' );
yourls_add_filter( 'shunt_all_options', 'apc_cache_shunt_all_options' );
yourls_add_filter( 'get_all_options', 'apc_cache_get_all_options' );
yourls_add_filter( 'activated_plugin', 'apc_cache_plugin_statechange' );
yourls_add_filter( 'deactivated_plugin', 'apc_cache_plugin_statechange' );
yourls_add_filter( 'edit_link', 'apc_cache_edit_link' );

/**
 * Return cached options is available
 *
 * @param bool $false 
 * @return bool true
 */
function apc_cache_shunt_all_options($false) {
	global $ydb; 
	
	$key = APC_CACHE_ALL_OPTIONS; 
	if(apc_exists($key)) {
		$ydb->option = apc_fetch($key);
		return true;
	} 
	
	return false;
}

/**
 * Cache all_options data. 
 *
 * @param array $options 
 * @return array options
 */
function apc_cache_get_all_options($option) {
	apc_store(APC_CACHE_ALL_OPTIONS, $option, APC_READ_CACHE_TIMEOUT);
	return $option;
}

/**
 * Clear the options cache if a plugin is activated or deactivated
 *
 * @param string $plugin 
 */
function apc_cache_plugin_statechange($plugin) {
        apc_delete(APC_CACHE_ALL_OPTIONS);
}

/**
 * If the URL data is in the cache, stick it back into the global DB object. 
 * 
 * @param string $args
 */
function apc_cache_pre_get_keyword($args) {
	global $ydb;
	$keyword = $args[0];
	
	// Lookup in cache
	if(apc_exists($keyword)) {
		$ydb->infos[$keyword] = apc_fetch($keyword); 	
	}
}

/**
 * Store the keyword info in the cache
 * 
 * @param array $info
 * @param string $keyword
 */
function apc_cache_get_keyword_infos($info, $keyword) {
	// Store in cache
	apc_store($keyword, $info, APC_READ_CACHE_TIMEOUT);
	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 * 
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function apc_cache_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if($return['status'] != 'fail') {
		apc_delete($keyword);
	}
	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks. 
 * 
 * @param string $keyword
 */
function apc_cache_shunt_update_clicks($false, $keyword) {
	global $ydb;
	
	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	} 
	
	$keyword = yourls_sanitize_string( $keyword );
	$timer = $keyword . "-=-timer";
	$key = $keyword . "-=-clicks";
	
	if(apc_add($timer, time(), APC_WRITE_CACHE_TIMEOUT)) {
		// Can add, so write right away
		$value = 1;
		if(apc_exists($key)) {
			$value += apc_cache_key_zero($key);
		}
		// Write value to DB
		$ydb->query("UPDATE `" . 
						YOURLS_DB_TABLE_URL. 
					"` SET `clicks` = clicks + " . $value . 
					" WHERE `keyword` = '" . $keyword . "'");
		
	} else {
		// Store in cache
		$added = false; 
		if(!apc_exists($key)) {
			$added = apc_add($key, 1);
		}
		
		if(!$added) {
			apc_cache_key_increment($key);
		}
	}
	
	return true;
}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries. 
 * This is a good trade off for us, but may not be for everyone. 
 *
 * @param string $keyword
 */
function apc_cache_shunt_log_redirect($false, $keyword) {
	global $ydb;
	
	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	}
	
	$args = array(
		yourls_sanitize_string( $keyword ),
		( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' ),
		yourls_get_user_agent(),
		yourls_get_IP(),
		yourls_geo_ip_to_countrycode( $ip )
	);
	
	// Separated out the calls to make a bit more readable here
	$key = APC_CACHE_LOG_INDEX;
	$logindex = 0;
	$added = false;
	
	if(!apc_exists($key)) {
		$added = apc_add($key, 0);
	} 
	
	if(!$added) {
		$logindex = apc_cache_key_increment($key);
	}
	
	// We now have a reserved logindex, so lets cache
	apc_store(apc_cache_get_logindex($logindex), $args, APC_CACHE_LONG_TIMEOUT);
	
	// If we've been caching for over a certain amount do write
	if(apc_add(APC_CACHE_LOG_TIMER, time(), APC_WRITE_CACHE_TIMEOUT)) {
		// We can add, so lets flush the log cache
		$key = APC_CACHE_LOG_INDEX;
		$index = apc_fetch($key);
		$fetched = -1;
		$loop = true;
		$values = array();
		
		// Retrieve all items and reset the counter
		while($loop) {
			for($i = $fetched+1; $i <= $index; $i++) {
				$values[] = apc_fetch(apc_cache_get_logindex($i));
			}
			
			$fetched = $index;
			
			if(apc_cas($key, $index, 0)) {
				$loop = false;
			} else {
				usleep(500);
			}
		}

		// Insert all log message - we're assuming input filtering happened earlier
		$query = "";

		foreach($values as $value) {
			if(strlen($query)) {
				$query .= ",";
			}
			$query .= "(NOW(), '" . 
				$value[0] . "', '" . 
				$value[1] . "', '" . 
				$value[2] . "', '" . 
				$value[3] . "', '" . 
				$value[4] . "')";
		}

		$ydb->query( "INSERT INTO `" . YOURLS_DB_TABLE_LOG . "` 
					(click_time, shorturl, referrer, user_agent, ip_address, country_code)
					VALUES " . $query);
	} 
	
	return true;
}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key 
 * @return string
 */
function apc_cache_get_logindex($key) {
	return APC_CACHE_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to do an atomic increment to a variable, 
 * 
 *
 * @param string $key 
 * @return void
 */
function apc_cache_key_increment($key) {
	do {
		$result = apc_inc($key);
	} while(!$result && usleep(500));
	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key 
 * @return old value before the reset
 */
function apc_cache_key_zero($key) {
	$old = 0;
	do {
		$old = apc_fetch($key);
		if($old == 0) {
			return $old;
		}
		$result = apc_cas($key, $old, 0);
	} while(!$result && usleep(500));
	return $old;
}