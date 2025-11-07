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

/**
 * This file contains an array of user settings used to dynamically create the settings menu.
 * Each setting has a help text, default value, type, title, and optional validator.
 * The settings include debug mode, Nextcloud server URL, username, password, root folder, folder depth, content types, share expiry, media and audio prices, bank statement descriptor, Stripe mode, and API keys for both live and test environments.
 * There are also success texts for the user and admin emails.
 *
 * @var array $UMS
 */

$UMS['user_settings'] = array(
    'debug_mode' => array(
        'help' => 'Select if you want to see debug output. In the backend, there will be a new tab here. For the user page, debug info will be displayed at the end of the page. This includes usernames and passwords. Be careful not to expose your info.',
        'default' => 'off',
        'type' => 'dropdown',
        'options' => array('off' => 'Off', 'on' => 'On'),
        'title' => 'Debug Mode',
    ),
    'nextcloud_url' => array(
        'help' => 'The base URL of the nextcloud server REQUIRES trailing slash!',
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
    'nextcloud_content_types' => array(
        'help' => 'Which content types do you want to look for on nextcloud?',
        'default' => array('video/mp4'),
        'options' => array('video/mp4' => 'video/mp4', 'audio/mp4' => 'audio/mp4'), // , 'image/jpeg' => 'image/jpeg'
        'type' => 'multiple',
        'title' => 'Nextcloud Content Type',
    ),
    'nextcloud_share_time' => array(
        'help' => 'How long should the file sharing link work? See the "Relative formats" in the <a href="https://www.php.net/manual/en/datetime.formats.php">PHP Manual</a> for details. Results in a date only, not time.',
        'default' => '1 month',
        'type' => 'text',
        'title' => 'Nextcloud Share Expiry',
        'validator' => array('relative_date_time' => true),
    ),
    'nextcloud_empty_trash' => array(
        'help' => 'Shall we always empty the trashbin? The trashbin will be emptied after a file expired. This will delete ALL files in the trashbin.',
        'default' => 'false',
        'options' => array('true' => 'always empty', 'false' => 'never empty'), // , 'image/jpeg' => 'image/jpeg'
        'type' => 'dropdown',
        'title' => 'Nextcloud Trashbin',
    ),
    'nextcloud_file_cleanup' => array(
        'help' => 'After how much time should file be deleted from Nextcloud? See the "Relative formats" in the <a href="https://www.php.net/manual/en/datetime.formats.php">PHP Manual</a> for details. Results in a date only, not time.',
        'default' => '1 month',
        'type' => 'text',
        'title' => 'Nextcloud File Expiry',
        'validator' => array('relative_date_time' => true),
    ),
    'media_base_price' => array(
        'help' => 'The base price for one video to buy. Needs to be in CENTS! 500 would be 5$',
        'default' => 100,
        'type' => 'text',
        'title' => 'Video Price',
    ),
    'multiplicator_time' => array(
        'help' => 'The number of minutes that causes a video to become more expensive.',
        'default' => 60,
        'type' => 'text',
        'title' => 'Time Multiplier',
    ),
    'multiplicator_price' => array(
        'help' => 'The added price when a video has a multiple of the above minutes. Needs to be in CENTS! 500 would be 5$',
        'default' => 200,
        'type' => 'text',
        'title' => 'Price Multiplier',
    ),
//    'audio_price' => array(
//        'help' => 'The price for one Audio file to download. Needs to be in CENTS! 500 would be 5$',
//        'default' => 300,
//        'type' => 'text',
//        'title' => 'Audio Price',
//    ),
    'currency' => array(
        'help' => 'What currency are you dealing with? This will be used in emails along with prices.',
        'default' => 'USD',
        'type' => 'text',
        'title' => 'Your Currency',
    ),
    'sales_page'  => array(
        'help' => 'Select the page where you inserted the shortcode to display the shop. The users will be sent here after a successful sales',
        'default' => '',
        'type' => 'wp_page',
        'title' => 'Your Frontend page',
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
    'success_text_email' => array(
        'help' => 'Please enter the text users see when after they bought a file. Available variables: {{username}}, {{link}} {{expiry}}',
        'default' => 'Dear {{username}},

You can now download the file here: {{link}}.
This link will be active until {{expiry}}. Please download it as soon as possible.
Thanks a lot!

Website Admin',
        'type' => 'longtext',
        'title' => 'Purchase Text',
    ),
    'success_admin_email' => array(
        'help' => 'Where do you want to send an email when a file was sold?',
        'default' => 'admin@website.com',
        'type' => 'text',
        'title' => 'Admin Email where sales results are sent to.',
    ),
    'new_file_admin_email' => array(
        'help' => 'The system will send a copy-paste ready email to this address each time a new file is added? Leave ampty to disable.',
        'default' => '',
        'type' => 'text',
        'title' => 'Admin Email address for new file notifications.',
    ),
    'new_file_email_text' => array(
        'help' => 'How should the content of the above email look like?  Available variables: {{video_datetime}}, {{video_price}}, {{thumbnail_link}}, {{purchase_link}}',
        'default' => 'Dear admin,
    there is a new recording online available. The recrding was done at

    {{video_datetime}}

    If you can identify the performer, you can send them the following text:

    Hi there!
    We recorded your latest show! You can buy a high-quality video file for only {{video_price}}!
    You can see a preview screenshot of the video here: {{thumbnail_link}}
    You can by the video buy clicking on this link: {{purchase_link}}

    Thanks!',
        'type' => 'longtext',
        'title' => 'Admin Email text for new file notifications.',
    ),
);