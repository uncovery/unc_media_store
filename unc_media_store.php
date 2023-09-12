<?php
namespace ums;
/*
Plugin Name: Uncovery Media Store
Plugin URI:  https://uncovery.net/about
Description: Plugin to sell media files (obs recordings) from a nextcloud storage via Stripe
Version:     1.0
Author:      Uncovery
Author URI:  http://uncovery.net
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die();
}

global $UMS, $TMP_FOLDERS;
require_once( plugin_dir_path( __FILE__ ) . "config.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "backend.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "frontend.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "stripe.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "nextcloud.inc.php");
require_once( plugin_dir_path( __FILE__ ) . "data.inc.php");

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

// add shortcode for the frontend
add_action('init', 'ums\register_shortcodes');

function register_shortcodes(){
   add_shortcode('ums_show_interface', 'ums\show_interface');
}

// we load the jquery datepicker UI
function enqueue_jquery() {
    // Load the datepicker script (pre-registered in WordPress).
    wp_enqueue_script('ums_datepicker_js', plugin_dir_url( __FILE__ ) . 'datepicker.js');
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
 * standard wordpress function to deactivate the plugin.
 *
 * @global type $UNC_GALLERY
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
 * Uninstalling the plugin
 *
 * @global type $UNC_GALLERY
 */
function plugin_uninstall() {
    global $UMS;
    // delete all images optional

    //delete all settings properly
    $prefix = $UMS['settings_prefix'];
    foreach ($UMS['user_settings'] as $setting => $D) {
        delete_option($prefix . $setting);
    }
    delete_directory($UMS['settings']['thumbs_folder']);
    data_db_remove();
}

/**
 * recursive function to delete directory with all it's files in it
 * @param type $directory
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


function validate_date($date, $format = 'Y-m-d') {
    $d = \DateTime::createFromFormat($format, $date);
    // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
    return $d && $d->format($format) === $date;
}

/**
 * this converts an array into a string that can be used by cURL as a command
 * line attribute.
 * @param type $data_array
 * @return type
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


// convert bytes into human readable numbers
function byteConvert($bytes) {
    $bytes_fix = floatval($bytes);

    if ($bytes_fix == 0) {
        return "0.00 B";
    }

    $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $e = floor(log($bytes_fix, 1024));

    return round($bytes_fix/pow(1024, $e), 2) . " " . $s[$e];
}

// create a dated folder
function date_folder($start_date) {
    $unix_time = strtotime($start_date);
    if (!$unix_time) {
        return false;
    }
    $folder = date("Y/m", $unix_time);

    return $folder;
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

function debug_info($info) {
    global $UMS;

    if ($UMS['debug_mode'] == 'on') {
        $UMS['debug_values'][] = var_export($info, true);
    }
}