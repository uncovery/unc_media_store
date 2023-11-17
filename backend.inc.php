<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * create the admin menu optionally in the admin bar or the settings
 *
 * @global type $UMS
 */
function admin_menu() {
    global $UMS;
    // the main page where we manage the options

    // in the settings page
    if (isset($UMS['settings_location']) && $UMS['settings_location'] == 'submenu') {
        $main_options_page_hook_suffix = add_options_page(
            'Media Store Options',
            'Media store',
            'manage_options',
            'ums_admin_menu',
            'ums\admin_settings'
        );
    } else {  // main admin menu
        $main_options_page_hook_suffix = add_menu_page(
            'Media Store Options', // $page_title,
            'Media Store', // $menu_title,
            'manage_options', // $capability,
            'ums_admin_menu', // $menu_slug,
            'ums\admin_settings' // $function, $icon_url, $position
        );
    }
    add_action('admin_print_scripts-' . $main_options_page_hook_suffix, 'ums\enqueue_jquery');
}

/**
 * This function adds the WordPress features for the admin pages.
 *
 * @global type $UMS The global variable for the plugin settings.
 */
function admin_init() {
    global $UMS;

    add_settings_section(
        'ums_pluginPage_section', // string
        __('Settings', 'wordpress'),
        'ums\settings_section_callback', // function name
        'ums_settings_page' // need to match menu_slug
    );

    // we iterate the plugin settings and creat the menus dynamically from there
    foreach ($UMS['user_settings'] as $setting => $D) {
        $prefix = $UMS['settings_prefix'];
        register_setting('ums_settings_page', $prefix . $setting);
        $setting_value = get_option($prefix . $setting, $D['default']);
        $args = array(
            'setting' => $prefix . $setting,
            'value'=> $setting_value,
            'help'=> $D['help'],
            'default' => $D['default'],
        );
        if ($D['type'] == 'text') {
            $callback = 'ums\setting_text_field_render';
        } else if ($D['type'] == 'longtext') {
            $callback = 'ums\setting_longtext_field_render';
        } else if ($D['type'] == 'dropdown') {
            $callback = 'ums\setting_drodown_render';
            $args['options'] = $D['options'];
        } else if ($D['type'] == 'multiple'){
            $callback = 'ums\setting_multiple_render';
            $args['options'] = $D['options'];
        } else if ($D['type'] == 'wp_page'){
            $callback = 'ums\setting_pages_render';
        }

        if (isset($D['validator'])) {
            $check = settings_validation($D['validator'], $setting_value);
            if (!$check) {
                $args['value'] = $D['default'];
                $args['message'] = 'Invalid Value, Default used!';
            }
        }

        add_settings_field (
            $prefix . $setting,
            __($D['title'], 'wordpress'),
            $callback,
            'ums_settings_page', // string
            'ums_pluginPage_section', // string
            $args
        );
    }
}

/**
 * Validates a setting value based on a given validator.
 *
 * @param array $validator The validator array containing the field and its corresponding value.
 * @param mixed $setting_value The value of the setting to be validated.
 * @return bool Returns true if the setting value is valid, false otherwise.
 */
function settings_validation($validator, $setting_value) {
    // validate setting
    $field = key($validator);
    $value = current($validator);

    switch ($field) {
        case 'relative_date_time':
            if (($timestamp = strtotime($setting_value)) === false) {
                return false;
            }
        case 'length':
            if (strlen($setting_value) > $value) {
                return false;
            }
        case 'integer':
            if (intval($setting_value) == 0 ) {
                return false;
            }
    }
    return true;
}

/**
 * Display the settings page
 * @global type $UMS
 */
function admin_settings() {
    global $UMS;
    remove_filter('the_content', 'wpautop');

    echo '<div class="wrap unc_gallery unc_gallery_admin">
    <h2>Uncovery Nextcloud Media Store</h2>
    <script type="text/javascript">
        jQuery(document).ready(function() {
        // Initialize jquery-ui tabs
        jQuery(\'.unc_jquery_tabs\').tabs();
        // Fade in sections that we wanted to pre-render
        jQuery(\'.unc_fade_in\').fadeIn(\'fast\');
        });
    </script>
    <div class="unc_jquery_tabs unc_fade_in">
    <ul>' . "\n";

    # Set up tab titles
    $debug_tab = '';
    if ($UMS['debug_mode'] == 'on') {
        $debug_tab = "<li><a href='#tab4'><span>Debug</span></a></li>";
    }

    echo "<li><a href='#tab1'><span>Settings</span></a></li>
        <li><a href='#tab2'><span>Files</span></a></li>
        <li><a href='#tab3'><span>Sales</span></a></li>
        $debug_tab
    </ul>\n";

    echo "<div class=''>
        <div id='tab1'>
        <form method=\"post\" action=\"options.php\">\n";

    // if nexctcloud or stripe do not work, let the user know.
    $settings_ok = true;
    if (stripe_test_login() === false)  {
        $settings_ok = false;
    }


    if (nc_curl_read_folder() === false) {
        $settings_ok = false;
    }

    settings_fields('ums_settings_page');
    do_settings_sections('ums_settings_page');
    submit_button();

    if ($settings_ok === false) {
        return;
    }

    echo "</form>
        </div>
        <div id='tab2'>\n";

    $schedule_last_run = get_option($UMS['settings_prefix'] . "hourly_cron_lastrun");
    if ($schedule_last_run) {
        echo "Automatic hourly file scan ran last: $schedule_last_run<br>\n";
    }

    // show a form button to update the files instead of doing it manually
    echo "<form style=\"margin:5px;\" method=\"POST\">
        <div>
            <input name=\"update_files\" type=\"submit\" value=\"Update Files now\">
        </div>
    </form>\n";

    $read_files = filter_input(INPUT_POST, 'update_files', FILTER_SANITIZE_ADD_SLASHES);
    if ($read_files) {
        echo "reading files...<br>";
        echo read_all_files();
    }

    echo "<hr>\n";

    echo list_files();
    echo "</div>
        <div id='tab3'>\n";
    echo list_sales();
    echo "</div>\n";

    # Set up tab titles
    if ($UMS['debug_mode'] == 'on') {
        echo "<div id='tab4'>" . debug_display() . "</div>\n";
    }
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
        Please note that only entries with filled-in user details are concluded sales.
        Bots can accidentally do the first step in the sales process and this will create an entry here.
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
        // we show not completed sales only in debug mods
        if ($UMS['debug_mode'] == 'off' && $D->expiry = '0000-00-00') {
            continue;
        }

        $link = "No sales concluded";
        if (strlen($D->nextcloud_link) > 1) {
            $link = "<a href=\"$D->nextcloud_link\">Nextcloud link</a>";
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

    // we check the time now and add the time to all found entries,
    // then delete the rest since those must have been removed from nextcloud
    $time_stamp = date_format(date_Create("now", timezone_open(wp_timezone_string())), 'Y-m-d H:i:s');

    // read already known files from the DB to compare
    $db_files = read_db();

    // let's get all the existing time stamps from the DB that are different from
    // the one created above
    $old_timestamps = array();
    foreach ($db_files as $file) {
        $old_timestamps[$file->verified] = $file->verified;
    }

    $new_file = 0;
    foreach ($files_filtered as $F) {

        // process this file
        $check = process_single_file($F, $db_files, $time_stamp);
        if ($check == 'new') {
            $new_file++;
        }
    }

    // now we delete all the not updated files from the DB based on the timestamp
    $deleted = 0;
    foreach ($old_timestamps as $time_stamp) {
        $deleted_files = $wpdb->delete(
            $wpdb->prefix . "ums_files",
            array('verified' => $time_stamp),  // field to update
            array('%s',), // string format of timestamp
        );
        if ($deleted_files) {
            $deleted += $deleted_files;
        }
    }

    $result = "
    Files added to DB: $new_file<br>
    Files removed from DB: $deleted<br>
    ";

    return $result;
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

    // 2023-09-13_21-18_22-28.mp4

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
    // we add new files
    if (!isset($db_files[$file_path])) {
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
 * Generic function to render a text input for WP settings dialogues
 * called by unc_gallery_admin_init
 * @param type $A
 */
function setting_text_field_render($A) {
    $def_text = str_replace(" ", '&nbsp;', $A['default']);
    $out = "<input class='textinput' type='text' name='{$A['setting']}' value='{$A['value']}'></td><td>{$A['help']} <strong>Default:</strong>&nbsp;'$def_text'\n";
    echo $out;
}

/**
 * Generic function to render a long text input for WP settings dialogues
 * called by unc_gallery_admin_init
 * @param type $A
 */
function setting_longtext_field_render($A) {
    $def_text = str_replace(" ", '&nbsp;', $A['default']);
    $out = "<textarea name='{$A['setting']}' rows=4>{$A['value']}</textarea></td><td>{$A['help']} <strong>Default:</strong>&nbsp;'$def_text'\n";
    echo $out;
}

/**
 * Generic function to render a dropdown input for WP settings dialogues
 * called by unc_gallery_admin_init
 * @param type $A
 */
function setting_drodown_render($A) {
    $out = "<select name=\"{$A['setting']}\">\n";
    foreach ($A['options'] as $option => $text) {
        $sel = '';
        if ($option == $A['value']) {
            $sel = 'selected';
        }
        $out .= "<option value=\"$option\" $sel>$text</option>\n";
    }
    $def_text = str_replace(" ", '&nbsp;', $A['options'][$A['default']]);
    $out .= "</select></td><td>{$A['help']} <strong>Default:</strong>&nbsp;'$def_text'\n";
    echo $out;
}

/**
 * Generic function to render a checkkbox input for WP settings dialogues
 * called by unc_gallery_admin_init
 * @param type $A
 */
function setting_multiple_render($A) {
    $out = '';
    if (!is_array($A['value'])) {
        $A['value'] = $A['default'];
    }
    asort($A['options']);
    foreach ($A['options'] as $option => $text) {
        $sel = '';
        if (in_array($text, $A['value'])) {
            $sel = 'checked="checked"';
        }
        $out .= "<input type=\"checkbox\" name=\"{$A['setting']}[$option]\" value=\"$text\" $sel>&nbsp;$text<br>\n";
    }
    $def_arr = array();
    foreach ($A['default'] as $def) {
        $def_arr[] = $A['options'][$def];
    }
    $defaults = implode("', '", $def_arr);
    $def_text = str_replace(" ", '&nbsp;', $defaults);
    $out .= "</td><td>{$A['help']} <strong>Default:</strong>&nbsp;'$def_text'\n";
    echo $out;
}

/**
 * Renders a selection dropdown for setting pages.
 *
 * @param array $A An array containing the setting, value, options, and default values.
 * @return void
 */
function setting_pages_render($A) {

    $out = "<select name=\"{$A['setting']}\">\n";

    // lets get all pages
    $pages = get_pages();
    foreach ($pages as $P) {
        $option = $P->ID;
        $text = $P->post_title;
        $sel = '';
        if ($option == $A['value']) {
           $sel = 'selected';
        }
        $out .= "<option value=\"$option\" $sel>$text</option>\n";
    }
    $def_text = str_replace(" ", '&nbsp;', $A['options'][$A['default']]);
    $out .= "</select></td><td>{$A['help']} <strong>Default:</strong>&nbsp;'$def_text'\n";
    echo $out;

}

/**
 * Callback for the Settings-section. Since we have only one, no need to use this
 * Called in unc_gallery_admin_init
 */
function settings_section_callback() {
    // echo __( 'Basic Settings', 'wordpress' );
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

    $email_body = "

    Dear admin, there is a new recording online available. The recrding was done at

    {$D['start_date']}, {$D['start_time']}
    and lasted until {$D['end_time']}.

    If you can identify the performer, you can send them the following text:


    Hi there!
    We recorded your latest show! You can buy a high-quailty video file for only $costs_video {$UMS['currency']}!
    You can see a preview of the video here: <a href=\"{$D['thumbnail_url']}\">{$D['thumbnail_url']}</a>
    You can by the video buy clicking on this link: <a href=\"$url?id=$id\">$url?id=$id</a>

    Thanks!";

    wp_mail($UMS['new_file_admin_email'], "New video recorded: {$D['start_date']}, {$D['start_time']} until {$D['end_time']}", nl2br($email_body));
}