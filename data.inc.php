<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * Creates the necessary database tables for the media store plugin.
 *
 * This function creates two tables: ums_files and ums_sales. The ums_files table stores information about the media files, 
 * such as the file name, full path, thumbnail path, folder, start and end times, size, description, and file type. 
 * The ums_sales table stores information about the sales, such as the file ID, buyer's full name and email, 
 * Stripe session ID, Nextcloud link, expiry date, sales time, and mode.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function data_db_create() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table_name_files = $wpdb->prefix . "ums_files";
    $sql_file = "CREATE TABLE $table_name_files (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        file_name varchar(128) NOT NULL,
        full_path varchar(256) NOT NULL,
        thumbnail_path varchar(256) NOT NULL,
        thumbnail_url varchar(256) NOT NULL,
        folder varchar(256) NOT NULL,
        start_date date DEFAULT '0000-00-00' NOT NULL,
        start_time time DEFAULT '00:00:00' NOT NULL,
        end_time time DEFAULT '00:00:00' NOT NULL,
        size varchar(64) NOT NULL,
        description varchar(256) DEFAULT '' NOT NULL,
        stripe_product_id varchar(256) DEFAULT '' NOT NULL,
        stripe_price_id varchar(256) DEFAULT '' NOT NULL,
        verified datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        file_type varchar(64) DEFAULT 'video/mp4' NOT NULL,
        UNIQUE KEY `id` (`id`),
        UNIQUE KEY `full_path` (`full_path`),
        UNIQUE KEY `start_date` (`start_date`,`full_path`)
    ) $charset_collate;";
    dbDelta($sql_file);

    $table_name_sales = $wpdb->prefix . "ums_sales";
    $sql_sales = "CREATE TABLE $table_name_sales (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        file_id mediumint(9) NOT NULL,
        fullname varchar(256) DEFAULT '' NOT NULL,
        email varchar(256) DEFAULT '' NOT NULL,
        stripe_session_id varchar(256) NOT NULL,
        nextcloud_link varchar(256) DEFAULT '' NOT NULL,
        expiry date DEFAULT '0000-00-00' NOT NULL,
        sales_time datetime DEFAULT NOW() NOT NULL,
        mode varchar(10) DEFAULT '' NOT NULL,
        UNIQUE KEY `id` (`id`)
    ) $charset_collate;";
    dbDelta($sql_sales);

    add_option( "ums_media_store_db_version", "3" );
}

function data_db_remove() {
    global $wpdb;
    $tables = array(
        "ums_files",
        "ums_sales",
    );
    foreach ($tables as $table) {
        $table_str = $wpdb->prefix . $table;
        $wpdb->query("DROP TABLE $table_str;");
    }
}

function read_db() {
    global $wpdb;
    $file_array = array();
    $table = $wpdb->prefix . "ums_files";
    $sql = "SELECT * FROM $table ORDER BY start_date";
    $file_data = $wpdb->get_results($sql);
    foreach ($file_data as $D) {
        $file_array[$D->full_path] = $D;
    }
    return $file_array;
}

function data_fetch_dates($ums_db) {
    $dates = array();

    foreach ($ums_db as $D) {
        $dates[$D->start_date] = $D->start_date;
    }

    return $dates;
}

function data_fetch_date_recordings($date) {
    global $wpdb;

    $recordings = array();

    $table = $wpdb->prefix . "ums_files";
    $sql = "SELECT * FROM $table WHERE start_date = '$date' GROUP BY start_time ORDER BY start_time";
    $file_data = $wpdb->get_results($sql);
    foreach ($file_data as $D) {
        $recordings[] = $D;
    }
    return $recordings;
}

function data_fetch_one_recording(int $id) {
    global $wpdb;

    $table = $wpdb->prefix . "ums_files";
    $sql = "SELECT * FROM $table WHERE id = '$id'";
    $file_data = $wpdb->get_results($sql);

    return $file_data[0];
}

/**
 * Inserts a new sales record into the database and updates the stripe product and price IDs for a given file.
 *
 * @param int $file_id The ID of the file being sold.
 * @param string $session_id The ID of the Stripe session associated with the sale.
 * @param string $product_id The ID of the Stripe product associated with the sale.
 * @param string $price_id The ID of the Stripe price associated with the sale.
 * @return void
 */
function data_prime_sales_session($file_id, $session_id, $product_id, $price_id) {
    global $wpdb, $UMS;

    $wpdb->insert(
    $wpdb->prefix . "ums_sales",
        array(
            'file_id' => $file_id,
            'stripe_session_id' => $session_id,
            'mode' => $UMS['stripe_mode'],
        )
    );

    $wpdb->update(
    $wpdb->prefix . "ums_files",
        array(
            'stripe_product_id' => $product_id,
            'stripe_price_id' => $price_id,
        ),
        array(
            'id' => $file_id,
        ),
	array(
            '%s',
            '%s',
	),
	array(
            '%s',
	),
    );
}

function data_finalize_sales_session($session_id, $username, $email, $nc_link, $expiry) {
    global $wpdb;

    $wpdb->update(
    $wpdb->prefix . "ums_sales",
        array(
            'fullname' => $username,
            'email' => $email,
            'nextcloud_link' => $nc_link,
            'expiry' => $expiry,
        ),
        array(
            'stripe_session_id' => $session_id,
        ),
	array(
            '%s',
            '%s',
            '%s',
            '%s',
	),
	array(
            '%s',
	),
    );
}

function data_get_file_from_session($session_id) {
    global $wpdb;

    $files_table = $wpdb->prefix . "ums_files";
    $sales_table =  $wpdb->prefix . "ums_sales";
    $D = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $sales_table
        LEFT JOIN $files_table ON $sales_table.file_id=$files_table.id
        WHERE $sales_table.stripe_session_id='%s';",
        $session_id
    ));

    if (count($D) < 0) {
        echo "ERROR finding session!";
        return false;
    }

    return $D[0]->full_path;
}

function data_get_sales() {
    global $wpdb;

    $files_table = $wpdb->prefix . "ums_files";
    $sales_table =  $wpdb->prefix . "ums_sales";
    $D = $wpdb->get_results(
        "SELECT * FROM $sales_table
        LEFT JOIN $files_table ON $sales_table.file_id=$files_table.id
        ORDER BY sales_time DESC
        ;",
    );

    return $D;
}
