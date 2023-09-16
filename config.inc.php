<?php
namespace ums;
/*
 * ATTENTION: These settings are not to be changed by the user.
 * These are simply constants and other items used by the plugin.
 * Use the config in the admin screen instead.
 */

if (!defined('WPINC')) {
    die;
}

global $UMS;

$UMS['settings_prefix'] = 'ums_';   // internal prefix for the config storage.

$UMS['operators'] = array(
    'l' => 'is larger',
    's' => 'is smaller',
    'e' => 'is equal',
    'ne' => 'is not equal',
    'c' => 'contains',
    'nc' => 'does not contain'
);

$UMS['settings'] = array(
    'thumbs_folder' => plugin_dir_path( __FILE__ ) . "thumbs",
);


// This is used to automatically / dynamically create the settings menu
$UMS['user_settings'] = array(
    'debug_mode' => array(
        'help' => 'Select if you want to see debug output. In the backend, there will be a new tab here. For the user page, debug info will be displayed at the end of the page. This includes usernames and passwords. Be careful not to expose your info.',
        'default' => 'off',
        'type' => 'dropdown',
        'options' => array('off' => 'Off', 'on' => 'On'),
        'title' => 'Debug Mode',
    ),
    'nextcloud_url' => array(
        'help' => 'The base URL of the nextcloud server',
        'default' => 'https://your_nextcloud_server.domain/',
        'type' => 'text',
        'title' => 'Nextcloud URL',
    ),
    'nextcloud_username' => array(
        'help' => 'The username for the nextcloud server',
        'default' => 'username',
        'type' => 'text',
        'title' => 'Nextcloud Username',
    ),
    'nextcloud_password' => array(
        'help' => 'The password for the nextcloud server',
        'default' => 'password',
        'type' => 'text',
        'title' => 'Nextcloud password',
    ),
    'nextcloud_folder' => array(
        'help' => 'The root folder of your media files, no leading or trailing slashes!',
        'default' => 'recording',
        'type' => 'text',
        'title' => 'Nextcloud Folder',
    ),
    'nextcloud_folder_depth' => array(
        'help' => 'How many subfolder levels shall we scan for files?',
        'default' => '4',
        'type' => 'text',
        'title' => 'Nextcloud Folder Depth',
        'validator' => array('integer' => true),
    ),
    'nextcloud_content_type' => array(
        'help' => 'Which content type do you want to look for on nextcloud? Set to "FALSE" to list all files. See <a href=https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types">this manual</a>',
        'default' => 'video/mp4',
        'type' => 'text',
        'title' => 'Nextcloud Content Type',
    ),
    'nextcloud_share_time' => array(
        'help' => 'How long should the file sharing link work? See the "Relative formats" in the <a href="https://www.php.net/manual/en/datetime.formats.php">PHP Manual</a> for details. Results in a date only, not time.',
        'default' => '+1 month',
        'type' => 'text',
        'title' => 'Nextcloud Share Expiry',
        'validator' => array('relative_date_time' => true),
    ),
    'media_price' => array(
        'help' => 'The price for one media file to download. Needs to be in CENTS!',
        'default' => 50000,
        'type' => 'text',
        'title' => 'Media Price',
    ),
    'statement_descriptor' => array(
        'help' => 'An arbitrary string to be displayed on your customer’s credit card or bank statement. While most banks display this information consistently, some may display it incorrectly or not at all. This may be up to 22 characters. The statement description may not include <, >, \, ", ’ characters, and will appear on your customer’s statement in capital letters. Non-ASCII characters are automatically stripped. It must contain at least one letter.',
        'default' => "Media sales",
        'type' => 'text',
        'title' => 'Bank Statement Descriptor',
        'validator' => array('length' => 22),
    ),
    'stripe_mode' => array(
        'help' => 'Chose if you want to use the test or the live environment on stripe',
        'default' => 'test',
        'type' => 'dropdown',
        'options' => array('test' => 'Test', 'live' => 'Live'),
        'title' => 'Stripe Mode',
    ),
    'stripe_api_secret_key' => array(
        'help' => 'The secret for the LIVE Stripe account. You can use restricted keys. The permissions need to be WRITE for Proructs, Checkout Sessions, Prices and Payment Links.',
        'default' => 'rk_live....',
        'type' => 'text',
        'title' => 'Stripe Secret LIVE Key',
    ),
    'stripe_api_test_secret_key' => array(
        'help' => 'The API secret for the TEST Stripe account. You can use restricted keys. The permissions need to be WRITE for Proructs, Checkout Sessions, Prices and Payment Links.',
        'default' => 'rk_test....',
        'type' => 'text',
        'title' => 'Stripe Secret TEST Key',
    ),
);