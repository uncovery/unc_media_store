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
    global $UMS, $NC;
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
        $debug_tab = "<li><a href='#tab4'><span>Debug</span></a></li>\n";
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
    $def_text = $A['default'];
    if (isset($A['options'])) {
        $def_text = str_replace(" ", '&nbsp;', $A['options'][$A['default']]);
    }
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
