<?php
namespace ums;

/*
Plugin Name: Uncovery Media Store
Plugin URI:  https://uncovery.net/about
Description: Plugin to sell media files (obs recordings) from a nextcloud storage via Stripe
Version:     2.7
Author:      Uncovery
Author URI:  http://uncovery.net
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die();
}
global $UMS, $NC, $STRP;
$UMS['debug'] = 'off';
$UMS['debug_info'] = array();
$UMS['start_time'] = microtime(true);

require_once( plugin_dir_path( __FILE__ ) . "config.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "backend.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "settings.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "frontend.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "data.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "libraries/stripe.php");
require_once( plugin_dir_path( __FILE__ ) . "libraries/nextcloud.php");

// actions on activating and deactivating the plugin
register_activation_hook( __FILE__, 'ums\plugin_activate');
register_deactivation_hook( __FILE__, 'ums\plugin_deactivate');
register_uninstall_hook( __FILE__, 'ums\plugin_uninstall');

if (is_admin() === true){ // admin actions
    add_action('admin_init', 'ums\admin_init');
    // add an admin menu
    add_action('admin_menu', 'ums\admin_menu');
}

// get the settings from the system and set the global variables
// this iterates the user settings that are supposed to be in the wordpress config
// and gets them from there, setting the default if not available
// inserts them into the global
foreach ($UMS['user_settings'] as $setting => $D) {
    $UMS[$setting] = get_option($UMS['settings_prefix'] . $setting, $D['default']);
}

// prepare the nextcloud connection
$lib_debug = 'off';
if ($UMS['debug'] == 'on') {
    $lib_debug = 'web';
}

$NC = new \nextcloud(
    $UMS['nextcloud_url'],
    $UMS['nextcloud_username'],
    $UMS['nextcloud_password'],
    $lib_debug,
);

$STRP = new \stripe(
    $UMS['stripe_mode'],
    $UMS['stripe_api_secret_key'],
    $UMS['stripe_api_test_secret_key'],
    $lib_debug,
);

// add shortcode for the frontend
add_action('init', 'ums\register_shortcodes');

function register_shortcodes(){
   add_shortcode('ums_show_interface', 'ums\show_interface');
}

// we load the jquery datepicker UI
function enqueue_jquery() {
    // Load the datepicker script (pre-registered in WordPress).
    wp_enqueue_script('ums_datepicker_js', plugin_dir_url( __FILE__ ) . '/js/datepicker.js');
    wp_enqueue_style('unc_gallery_css', plugin_dir_url( __FILE__ ) . '/css/ums_styles.css');
    wp_enqueue_script('jquery-ui');
    wp_enqueue_style('jquery-ui');
    wp_enqueue_script('jquery-form');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery_ui_css', plugin_dir_url( __FILE__ ) . 'css/jquery-ui.css');
    // You need styling for the datepicker. For simplicity I've linked to the jQuery UI CSS on a CDN.
    wp_register_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css');
}
add_action('wp_enqueue_scripts', 'ums\enqueue_jquery');

add_filter('widget_text', 'do_shortcode');

// set the email content type to HTML
function set_content_type(){
    return "text/html";
}
add_filter('wp_mail_content_type','ums\set_content_type' );

/**
 * This is a specific redirect that checks if we have as purchase and then forwards
 * the user to stripe to conclude
 */
add_action('template_redirect', 'ums\execute_purchase');

/**
 * standard WordPress function to activate the plugin.
 * creates the uploads folder
 *
 */
function plugin_activate() {
    global $UMS;
    data_db_create();

    // let's create the thumbs folder
    $dir = $UMS['settings']['thumbs_folder'];
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
}

/**
 * Deactivates the plugin by unregistering all settings.
 *
 * @global type $UMS The global variable for the plugin.
 */
function plugin_deactivate() {
    global $UMS;

    // deactivate all settings
    $prefix = $UMS['settings_prefix'];
    foreach ($UMS['user_settings'] as $setting => $D) {
        unregister_setting('ums_settings_page', $prefix . $setting);
    }
}

/**
 * Activates the file scan cron job.
 *
 * This function schedules the file scan cron job to run hourly if it is not already scheduled.
 * It also adds an option to store the last run time of the cron job.
 *
 * @global array $UMS The global variable for the plugin settings.
 * @return void
 */
function file_scan_cron_activate() {
    global $UMS;
    if (!wp_next_scheduled ('ums_hourly_filescan')) {
    	wp_schedule_event(time(), 'hourly', 'ums_hourly_filescan');
        add_option($UMS['settings_prefix'] . "hourly_cron_lastrun", 'never');
    }
}
register_activation_hook( __FILE__, 'ums\file_scan_cron_activate');

/**
 * Deactivates the file scan cron job.
 */
function file_scan_cron_deactivate() {
    wp_clear_scheduled_hook( 'ums_hourly_filescan' );
}
register_deactivation_hook( __FILE__, 'ums\file_scan_cron_deactivate');


/**
 * Executes the hourly cron job.
 * Updates the last run timestamp in the options table.
 * Reads all files in the media store.
 */
function hourly_run() {
    global $UMS;
    $time_stamp = date_format(date_Create("now", timezone_open(wp_timezone_string())), 'Y-m-d H:i:s');
    update_option($UMS['settings_prefix'] . "hourly_cron_lastrun", $time_stamp);
    read_all_files();
}
add_action('ums_hourly_filescan', 'ums\hourly_run', 10, 2);

/**
 * Function to handle plugin uninstallation.
 * Deletes all images (optional), deletes all settings properly,
 * deletes the thumbnails folder, and removes data from the database.
 */
function plugin_uninstall() {
    global $UMS;

    // delete all images optional

    // delete all settings properly
    $prefix = $UMS['settings_prefix'];
    foreach ($UMS['user_settings'] as $setting => $D) {
        delete_option($prefix . $setting);
    }

    delete_directory($UMS['settings']['thumbs_folder']);
    data_db_remove();
}


/**
 * Deletes a directory and all its contents recursively.
 *
 * @param string $directory The path to the directory to be deleted.
 * @return void
 */
function delete_directory($directory) {
    $it = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($directory);
}


/**
 * Validates a date string against a given format.
 *
 * @param string $date The date string to validate.
 * @param string $format The format to validate the date string against. Defaults to 'Y-m-d'.
 * @return bool Returns true if the date string is valid and matches the given format, false otherwise.
 */
function validate_date($date, $format = 'Y-m-d') {
    $d = \DateTime::createFromFormat($format, $date);
    // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
    return $d && $d->format($format) === $date;
}

/**
 * Converts a multidimensional array into a string representation of key-value pairs.
 *
 * This function takes an array as input and iterates through its elements. If an element is an array itself,
 * it further iterates through its sub-elements and appends the key-value pairs to the resulting string.
 * If an element is not an array, it simply appends the key-value pair to the resulting string.
 *
 * @param array $data_array The input array to be converted.
 * @return string The string representation of the key-value pairs.
 */
function make_array_string($data_array) {
    $data_pairs = array();
    foreach ($data_array as $key => $value) {
        if (is_array($value) && count($value) > 0) {
            foreach ($value as $sub_key => $sub_value) {
                $data_pairs[] = $key . "[" . $sub_key . "]=$sub_value";
            }
        } else {
            $data_pairs[] = "$key=$value";
        }

    }
    $data_string = implode("&", $data_pairs);
    return $data_string;
}


/**
 * Converts bytes to a human-readable format.
 *
 * @param int $bytes The number of bytes to convert.
 * @return string The converted value in a human-readable format.
 */
function byteConvert($bytes) {
    $bytes_fix = floatval($bytes);

    if ($bytes_fix == 0) {
        return "0.00 B";
    }

    $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $e = floor(log($bytes_fix, 1024));

    return round($bytes_fix/pow(1024, $e), 2) . " " . $s[$e];
}

/**
 * display an alert to the user
 * @param type $string
 * @return string
 */
function user_alert($string) {
    $out = '<div style="border: 1px solid red; margin:30px; padding:10px">' . $string . "</div>";
    return $out;
}

/**
 * Logs debug information if debug mode is on.
 *
 * @param mixed $info The information to log.
 * @param string $location The location where the information is logged from.
 * @param bool|string $format Optional. The format of the information. Defaults to false.
 * @return void
 */
function debug_info($info, $location, $format = false) {
    global $UMS;

    if ($UMS['debug_mode'] == 'on') {
        if ($format == 'xml') {
            $domxml = new \DOMDocument('1.0');
            $domxml->preserveWhiteSpace = false;
            $domxml->formatOutput = true;
            /* @var $xml SimpleXMLElement */
            $domxml->loadXML($info->asXML());
            $info = $domxml->save();
        }

        $UMS['debug_info'][] = array(
            'time' => microtime(true),
            'function' => $location,
            'content' => var_export($info, true),
        );
    }
}

/**
 * Displays debug information in an HTML table format.
 *
 * @return string The HTML table containing debug information.
 *
 * @global array $UMS The global variable containing debug information.
 */
function debug_display() {
    global $UMS;

    $end_time = microtime(true);
    $execution_time = $end_time - $UMS['start_time'];
    $execution_time_formatted = number_format($execution_time, 6, ".", "'") . " sec";

    $out = "<table class='ums_admin_table'>
        <tr><th>Time</th><th>Function</th><th>Value</th>\n";
    $last_time = false;
    foreach ($UMS['debug_info'] as $I) {
        $time_str = microtime_diff($I['time'], $last_time);
        $content = htmlspecialchars($I['content']);
        $out .= "<tr><td>$time_str</td><td>{$I['function']}</td><td><pre>$content</pre></td></tr>\n";
        $last_time = $I['time'];
    }

    $out .= "<tr><td>_POST</td><td></td><td>". var_export($_POST, true) ."</td></tr>\n";
    $out .= "<tr><td>_GET</td><td></td><td>". var_export($_GET, true) ."</td></tr>\n";
    $out .= "<tr><td>Execution time</td><td>$execution_time_formatted</td></tr>\n";
    $out .= "</table>\n";

    return $out;
}

/**
 * Calculates the difference between two microtime values and returns it as a formatted string.
 *
 * @param float $time The current microtime value.
 * @param float $last_time The previous microtime value.
 * @return string The difference between the two microtime values as a formatted string.
 */
function microtime_diff($time, $last_time) {
    global $UMS;

    if (!$last_time) {
        $time_val = $time - $UMS['start_time'];
    } else {
        $time_val = $time - $last_time;
    }

    $time_str = number_format($time_val, 6, ".", "'") . " sec";
    return $time_str;
}

/**
 * Convert microtime to string with timezone and milliseconds.
 *
 * @return string The formatted time string.
 */
function microtime2string() {
    $microtime = microtime(true);

    $date_obj = \DateTime::createFromFormat('0.u00 U', microtime());
    $date_obj->setTimezone(new \DateTimeZone(wp_timezone_string()));

    $time_str = $date_obj->format('Y-m-d H:i:s u') . substr((string)$microtime, 1, 8) . "ms";
    return $time_str;
}
