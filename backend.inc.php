<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}


/**
 * Generates an HTML table listing files with their details.
 *
 * @return string The generated HTML table.
 */
function list_files(){
    $files = read_db();

    $out = "<table class='ums_admin_table'>";
    $out .= "<tr>
        <th>Full Path</th>
        <th>Thumbnail</th>
        <th>Start</th>
        <th>End</th>
        <th>Size</th>
        <th>Stripe Product</th>
    </tr>\n";

    foreach ($files as $F) {
        $thumb_url =  $F->thumbnail_url;
        $stripe_data = stripe_keys();
        $url_html = '';
        if ($F->stripe_product_id != '') {
            $url =  $stripe_data['url'] . "products/$F->stripe_product_id";
            $url_html = "<a target=\"_blank\" href=\"$url\">Click here</a>";
        }

        $out .= "<tr>
            <td>$F->full_path</td>
            <td><a target=\"_blank\" href=\"$thumb_url\"><img width=\"100px\"src=\"$thumb_url\"></a></td>
            <td>$F->start_date $F->start_time</td>
            <td>$F->end_time</td>
            <td>$F->size</td>
            <td>$url_html</td>
        </tr>\n";
    }

    $out .= "</table>\n";

    return $out;
}


/**
 * Retrieves a list of sales and generates an HTML table to display the sales information.
 *
 * @return string The HTML code for the sales table.
 */
function list_sales() {
    global $UMS;

    $out = "<h2> List of Sales </h2>
        <table class='ums_admin_table'>
        <tr>
            <th>Date</th>
            <th>Mode</th>
            <th>File</th>
            <th>Customer Name</th>
            <th>Customer email</th>
            <th>Share link</th>
            <th>Expiry</th>
        </tr>\n";

    $data = data_get_sales();
    foreach ($data as $D) {
        $link = "No sales concluded";
        if (strlen($D->nextcloud_link) > 1) {
            $link = "<a href=\"$D->nextcloud_link\">Nextcloud link</a>";
        } else if ($UMS['debug_mode'] == 'off') {
			continue;
	}

        $out .= "<tr>
            <td>$D->sales_time</td>
            <td>$D->mode</td>
            <td>$D->full_path</td>
            <td>$D->fullname</td>
            <td>$D->email</td>
            <td>$link</td>
            <td>$D->expiry</td>
        </tr>\n";
    }


    $out .= "</table>\n";
    return $out;
}

/**
 * We read the files from nextcloud and process them to add them to the db
 * we also delete old files from the DB that do not exist on nextcloud anymore
 * TODO: also remove them from the strip database
 *
 * @global type $wpdb
 */
function read_all_files() {
    global $wpdb;

    debug_info("reading all files", 'read_all_files');

    // Read all files from the nextcloud server
    $nc_files = nc_curl_read_folder();

    $files_filtered = nc_filter_files($nc_files);

    // cleanup expired share links
    data_cleanup_expired_links();

    // read already known files from the DB to compare
    $db_files = read_db();

    // we check the time now and add the time to all found entries,
    // then delete the rest since those must have been removed from nextcloud
    $time_stamp = date_format(date_Create("now", timezone_open(wp_timezone_string())), 'Y-m-d H:i:s');

    // let's get all the existing time stamps from the DB that are different from
    // the one created above
    $old_timestamps = array();
    foreach ($db_files as $file) {
        $old_timestamps[$file->verified] = $file->verified;
    }

    $new_file = 0;
    $old_file = 0;
    foreach ($files_filtered as $F) {
        // process this file
        $check = process_single_file($F, $db_files, $time_stamp);
        if ($check == 'new') {
            $new_file++;
        } else if ($check == 'deleted') {
            $old_file++;
        }
    }

    // remove files not on the nextcloud instance from the database.
    $missing = clean_db($old_timestamps);

    $result = "\nFiles new on nextcloud, added to DB: $new_file<br>
    Files missing on nextcloud, removed from DB: $missing<br>
    Files deleted from nextcloud due to age: $old_file<br>
    ";

    return $result;
}

/**
 * remove files not on the nextcloud instance from the database.
 *
 * @global \ums\type $wpdb
 * @param type $old_timestamps
 * @return type
 */
function clean_db($old_timestamps) {
    global $wpdb;

    $deleted = 0;
    foreach ($old_timestamps as $time_stamp) {
        $deleted_files = $wpdb->delete(
            $wpdb->prefix . "ums_files",
            array('verified' => $time_stamp),  // field to check
            array('%s',), // string format of timestamp
        );
        if ($deleted_files) {
            $deleted += $deleted_files;
        }
    }

    return $deleted;
}


/**
 * Process one individual file
 *
 * This function processes a single file, extracting relevant information from the file's properties and updating the database accordingly.
 *
 * @global type $wpdb The WordPress database object.
 * @global type $UMS The global variable containing Nextcloud configuration settings.
 * @param type $F The file object to be processed.
 * @param type $db_files The array of existing file records in the database.
 * @param type $time_stamp The timestamp indicating the verification time.
 * @return type The result of the file processing: "new" if a new file record was inserted, "updated" if an existing file record was updated, or false if the file processing failed.
 */
function process_single_file($F, $db_files, $time_stamp) {
    global $wpdb, $UMS;

    $result = false;

    $P = $F->propstat->prop;

    // let's check for spaces in the filename
    if (strstr($F->href->__toString(), ' ')) {
        return false;
    }

    $server_path = '/remote.php/dav/files/' . $UMS['nextcloud_username'];

    // we need to strip '/remote.php/dav/files/username/nextcloud_folder' for the database
    $full_url = $server_path. "/" . $UMS['nextcloud_folder'];
    $strip_length = strlen($full_url);
    $file_path = substr($F->href->__toString(), $strip_length);

    debug_info("file found: $file_path", 'process_single_file');

    // get more variables from XML
    $filename = basename($file_path);

    // file name format is 2023-09-13_21-18_22-28.mp4
    $start_date = substr($filename, 0, 10);
    $folder = dirname($file_path);

    // let's not process files in the root directory
    if ($folder == '/') {
        return false;
    }

    // check if we need to move the file
    $thumbs_url = plugin_dir_url(__FILE__) . "thumbs/" . md5($file_path) . ".jpg";


    $end_time = str_replace("-", ":", substr($filename, 17, 5)) . ":00";
    $file_size = byteConvert($P->getcontentlength->__toString());

    $start_time = str_replace("-", ":", substr($filename, 11, 5)) . ":00";

    if (file_is_expired($start_date) && !data_file_has_active_nextcloud_share($file_path)) {
        // remove old files
        nc_delete_file($file_path);
        // echo "file $file_path is marked for deletion. Please cross-check if it's not shared anymore.\n";
        $result = 'deleted';
    } else if (!isset($db_files[$file_path])) {
        // add new files
        $description = "Recording from $start_date $start_time until $end_time";
        $db_data = array(
                'file_name' => $filename,
                'full_path' => $file_path,
                'thumbnail_path' => $file_path . ".jpg",
                'thumbnail_url' => $thumbs_url,
                'folder' => $folder,
                'start_date' => $start_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'size' => $file_size,
                'verified' => $time_stamp,
                'description' => $description,
        );
        $wpdb->insert(
            $wpdb->prefix . "ums_files",
            $db_data,
        );
        // let's notify the admin that there is a new file
        new_file_notification($db_data, $wpdb->insert_id);
        $result = "new";
    } else {
        // we still need to update the timestamp to mark the DB Entry as existing
        $wpdb->update(
            $wpdb->prefix . "ums_files",
            array('verified' => $time_stamp,),  // field to update
            array('full_path' => $file_path),  // where condition
            array('%s',), // string format of timestamp
            array('%s',), // string format of where condition
        );
        $result = 'updated';
    }
    download_thumbnail($file_path);
    return $result;
}

/**
 * Downloads the thumbnail for a given file path if it does not already exist.
 *
 * @param string $file_path The path of the file.
 * @return void
 */
function download_thumbnail($file_path) {
    global $UMS;
    // download the thumbnail if we do not have it.
    // this assumes that all files have thumbnails
    // fails silently if not
    $thumbnail_file = $UMS['settings']['thumbs_folder'] . "/" . md5($file_path) . ".jpg";
    if (!file_exists($thumbnail_file)) {
        nc_download_file($file_path . ".jpg", $thumbnail_file);
    }
}


/**
 * Sends a notification email to the admin about a new recording being available online.
 *
 * @param array $D The details of the recording (start date, start time, end time, thumbnail URL, etc.).
 * @param int $id The ID of the recording.
 * @return bool
 */
function new_file_notification($D, $id) {

    global $UMS;

    if (strlen($UMS['new_file_admin_email']) < 3) {
        return false;
    }

    $costs_video = $UMS['media_price'] / 100;

    $url = esc_url( get_page_link($UMS['sales_page']));

    $config_text= nl2br($UMS['new_file_email_text']);

    // replace the variables:
    $searches = array( '{{video_datetime}}', '{{video_price}}', '{{thumbnail_link}}', '{{purchase_link}}');
    $video_datetime = "{$D['start_date']}, {$D['start_time']} and lasted until {$D['end_time']}";
    $video_price = "$costs_video {$UMS['currency']}";
    $thumbnail_link = "<a href=\"{$D['thumbnail_url']}\">{$D['thumbnail_url']}</a>";
    $purchase_link = "<a href=\"$url?id=$id\">$url?id=$id</a>";
    $replacements = array( $video_datetime, $video_price, $thumbnail_link, $purchase_link);

    $email_body = str_replace($searches, $replacements, $config_text);

    wp_mail($UMS['new_file_admin_email'], "New video recorded: {$D['start_date']}, {$D['start_time']} until {$D['end_time']}", $email_body);
}

/**
 * return true if a file is older than the set config timespan
 * for nextcloud retention
 *
 * @global \ums\type $UMS
 * @param type $file_time
 * @return type
 */
function file_is_expired(string $file_time) {
    global $UMS;

    $datetime = \DateTime::createFromFormat('Y-m-d', $file_time);
    $monthAgo = new \DateTime();
    $monthAgo->modify("-" . $UMS['nextcloud_share_time']);

    return $datetime < $monthAgo;
}