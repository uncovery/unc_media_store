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
        expired datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
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
        price DECIMAL(13,2) DEFAULT 0 NOT NULL
        UNIQUE KEY `id` (`id`)
    ) $charset_collate;";
    dbDelta($sql_sales);

    add_option( "ums_media_store_db_version", "5" );
}

/**
 * Removes the specified tables from the database.
 */
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

/**
 * Reads the database and returns an array of file data.
 *
 * @return array An array of file data, where the keys are the full paths and the values are the file objects.
 */
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

/**
 * Fetches the unique start dates from the given database.
 *
 * @param array $ums_db The database containing the records.
 * @return array An array of unique start dates.
 */
function data_fetch_dates($ums_db) {
    $dates = array();

    foreach ($ums_db as $D) {
        $dates[$D->start_date] = $D->start_date;
    }

    return $dates;
}

/**
 * Fetches recordings for a specific date.
 *
 * @param string $date The date for which to fetch recordings.
 * @return array An array of recordings for the specified date.
 */
function data_fetch_date_recordings($date, $expired = false) {
    global $wpdb;

    $recordings = array();

    $exp_sql = '';
    if (!$expired) {
        $exp_sql = 'AND expired = 0000-00-00 00:00:00';
    }

    $table = $wpdb->prefix . "ums_files";
    $sql = "SELECT * FROM $table WHERE start_date = '$date' $exp_sql GROUP BY start_time ORDER BY start_time";
    $file_data = $wpdb->get_results($sql);
    foreach ($file_data as $D) {
        $recordings[] = $D;
    }
    return $recordings;
}

/**
 * Fetches a single recording from the database based on its ID.
 *
 * @param int $id The ID of the recording to fetch.
 * @return object The fetched recording data.
 */
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

/**
 * Finalizes the sales session by updating the sales data in the database.
 *
 * @param string $session_id The session ID of the sales session.
 * @param string $username The username associated with the sales session.
 * @param string $email The email associated with the sales session.
 * @param string $nc_link The Nextcloud link associated with the sales session.
 * @param string $expiry The expiry date of the sales session.
 * @return void
 */
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

/**
 * Give a new link to an existing sale
 *
 * @param string $session_id The session ID of the sales session.
 * @param string $username The username associated with the sales session.
 * @param string $email The email associated with the sales session.
 * @param string $nc_link The NextCloud link associated with the sales session.
 * @param string $expiry The expiry date of the sales session.
 * @return void
 */
function data_update_nc_share($sales_id, $nc_link, $expiry) {
    global $wpdb;

    $wpdb->update(
        $wpdb->prefix . "ums_sales",
        array(
            'nextcloud_link' => $nc_link,
            'expiry' => $expiry,
        ),
        array(
            'id' => $sales_id,
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

/**
 * Retrieves the file path associated with a given session ID.
 *
 * @param string $session_id The session ID to retrieve the file path for.
 * @return string|false The file path if found, false otherwise.
 */
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

/**
 * Retrieves sales data from the database.
 *
 * potential issue: maybe this shows only sales that have a file attached?
 * What happens if the file expires?
 *
 * @return array The sales data.
 */
function data_get_sales() {
    global $wpdb, $UMS;

    $files_table = $wpdb->prefix . "ums_files";
    $sales_table =  $wpdb->prefix . "ums_sales";

    $filter = '';
    if ($UMS['stripe_mode'] == 'live') {
        $filter = "WHERE mode LIKE 'live'";
    }

    $sql = "SELECT *, $sales_table.id as sales_id FROM $sales_table
        LEFT JOIN $files_table ON $sales_table.file_id=$files_table.id
        $filter
        ORDER BY sales_time DESC;";
    $D = $wpdb->get_results($sql);
    return $D;
}

/**
 * checks for nextcloud links that are expired and removes the links from the DB
 *
 */
function data_cleanup_expired_links() {
    global $wpdb;

    // Prepare the SQL statement
    $table_name_sales = $wpdb->prefix . "ums_sales";
    $sql = $wpdb->prepare("UPDATE $table_name_sales SET nextcloud_link = '' WHERE expiry < %s", date('Y-m-d'));

    // Execute the query
    $wpdb->query($sql);
}

function data_file_has_active_nextcloud_share($file_path) {
    global $wpdb;

    $files_table = $wpdb->prefix . "ums_files";
    $sales_table =  $wpdb->prefix . "ums_sales";
    $sql = "SELECT nextcloud_link FROM $sales_table
        LEFT JOIN $files_table ON $sales_table.file_id=$files_table.id
        WHERE $files_table.full_path = '%s';";
    $D = $wpdb->get_results($wpdb->prepare($sql , $file_path), ARRAY_A);
    if (isset($D[0]['nextcloud_link']) && $D[0]['nextcloud_link'] <> '') {
        return true;
    }
    return false;
}


/**
 * remove files not on the nextcloud instance from the database.
 *
 * @global \ums\type $wpdb
 * @param type $old_timestamps
 * @return type
 */
function data_clean_db($old_timestamps) {
    global $wpdb;

    $deleted = 0;
    foreach ($old_timestamps as $time_stamp) {
        $deleted_files = $wpdb->update(
            $wpdb->prefix . "ums_files",
            array('expired' => 'NOW()'), // field => value to update
            array('verified' => $time_stamp),  // field to match
            array('%s',), // string format of timestamp
        );
        if ($deleted_files) {
            $deleted += $deleted_files;
        }
    }

    return $deleted;
}

function data_get_file_id_from_sales_id($sales_id) {
    global $wpdb;
    
    $files_table = $wpdb->prefix . "ums_files";
    $sales_table = $wpdb->prefix . "ums_sales";    
    
    $sql = "SELECT $files_table.id as file_id FROM $sales_table
        LEFT JOIN $files_table 
        ON $sales_table.file_id=$files_table.id 
        WHERE $sales_table.id=%s;"; 
    $D = $wpdb->get_results($wpdb->prepare($sql , $sales_id), ARRAY_A);
    
    if (count($D) == 0) {
        return false;
    } else {
        return $D[0]['file_id'];
    }
}

function data_get_file_path_from_sales_id($sales_id) {
    global $wpdb;
    
    $files_table = $wpdb->prefix . "ums_files";
    $sales_table = $wpdb->prefix . "ums_sales";    
    
    $sql = "SELECT full_path FROM $sales_table
        LEFT JOIN $files_table 
        ON $sales_table.file_id=$files_table.id 
        WHERE $sales_table.id=%s;"; 
    $D = $wpdb->get_results($wpdb->prepare($sql , $sales_id), ARRAY_A);
    
    if (count($D) == 0) {
        return false;
    } else {
        return $D[0]['full_path'];
    }
}