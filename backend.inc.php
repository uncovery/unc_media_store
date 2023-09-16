<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * create the admin menu optionally in the admin bar or the settings benu
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
            'MEdia store',
            'manage_options',
            'ums\admin_menu',
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
 * This adds the Wordpress features for the admin pages
 *
 * @global type $UNC_GALLERY
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
            $callback = 'ums\longtext_field_render';
        } else if ($D['type'] == 'dropdown') {
            $callback = 'ums\setting_drodown_render';
            $args['options'] = $D['options'];
        } else if ($D['type'] == 'multiple'){
            $callback = 'ums\setting_multiple_render';
            $args['options'] = $D['options'];
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

    $settings_ok = true;

    if (stripe_test_login() === false)  {
        $settings_ok = false;
    }

    if (read_all_files() === false) {
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
        echo "Automatic hourly file scan ran last: $schedule_last_run<br><hr>";
    }

    echo list_files();
    echo "</div>
        <div id='tab3'>\n";
    echo list_sales();
    echo "</div>";

    # Set up tab titles
    if ($UMS['debug_mode'] == 'on') {
        echo "<div id='tab4'>" . debug_display() . "</div>";
    }
}


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

    $out .= "</table>";

    return $out;
}


function list_sales() {
    $out = "<h2> List of Sales </h2>
        <table class='ums_admin_table'>
        <tr><th>Mode</th><th>File</th><th>Customer Name</th><th>Customer email</th><th>Share link</th><th>Expiry</th></tr>
    ";
    $data = data_get_sales();
    foreach ($data as $D) {
        $link = "<a href=\"$D->nextcloud_link\">Nextcloud link</a>";
        $out .= "<tr><td>$D->mode</td><td>$D->full_path</td><td>$D->fullname</td><td>$D->email</td><td>$link</td><td>$D->expiry</dh></tr>\n";
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

    foreach ($files_filtered as $F) {
        // process this file
        process_single_file($F, $db_files, $time_stamp);
    }

    // now we delete all the not updated files from the DB based on the timestamp
    foreach ($old_timestamps as $time_stamp) {
        $wpdb->delete(
            $wpdb->prefix . "ums_files",
            array('verified' => $time_stamp,),  // field to update
            array('%s',), // string format of timestamp
        );
    }
}


/**
 * Process one individual file
 *
 * @global type $wpdb
 * @global type $UMS
 * @param type $F
 * @param type $db_files
 * @return type
 */
function process_single_file($F, $db_files, $time_stamp) {
    global $wpdb, $UMS;

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

    // get more variables from XML
    $filename = basename($file_path);

    // 2023-09-13_21-18_22-28.mp4

    $start_date = substr($filename, 0, 10);
    $folder = dirname($file_path);

    // check if we need to move the file
    $thumbs_url = plugin_dir_url(__FILE__) . "thumbs/" . md5($file_path) . ".jpg";


    $end_time = str_replace("-", ":", substr($filename, 17, 5)) . ":00";
    $file_size = byteConvert($P->getcontentlength->__toString());

    $start_time = str_replace("-", ":", substr($filename, 11, 8));
    // we add new files
    if (!isset($db_files[$file_path])) {
        $description = "Recording from $start_date $start_time until $modified_date";
        $wpdb->insert(
            $wpdb->prefix . "ums_files",
            array(
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
            ),
        );
    } else {
        // we still need to update the timestamp to mark the DB Entry as existing
        $wpdb->update(
            $wpdb->prefix . "ums_files",
            array('verified' => $time_stamp,),  // field to update
            array('full_path' => $file_path),  // where condition
            array('%s',), // string format of timestamp
            array('%s',), // string format of where condition
        );
    }
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
    global $UMS;
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
 * Callback for the Settings-section. Since we have only one, no need to use this
 * Called in unc_gallery_admin_init
 */
function settings_section_callback() {
    // echo __( 'Basic Settings', 'wordpress' );
}
