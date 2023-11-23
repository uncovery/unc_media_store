<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * This function shows the date picker and the files that the customer can buy.
 *
 * @return string The HTML output of the interface.
 */
function show_interface() {
    global $UMS;

    debug_info($_POST, 'show_interface POST');
    debug_info($_GET, 'show_interface GET');

    // check if we had a sale:
    $session_id = filter_input(INPUT_GET, 'session_id');
    if (!is_null($session_id)) {
        return show_sales_result($session_id);
    }

    // get the data from the DB
    $files_data = read_db();
    $all_dates = data_fetch_dates($files_data);

    if (count($all_dates) == 0) {
        return "Sorry, there are no files for purchase at this moment. Please check back later.";
    }

    // get form subission
    // step 1: Select a date
    $selected_date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_ADD_SLASHES);
    // step 2: select recording on that date
    $selected_file_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

    // validate selected date
    if (is_null($selected_date) ||  !validate_date($selected_date, $format = 'Y-m-d') || !isset($all_dates[$selected_date])) {
        $selected_date = array_key_last($all_dates);
    }

    // create the datepicker JS
    $out = recording_date_picker($selected_date, $all_dates);

    // we have a date, let's show the recordings that we have
    if (!isset($all_dates[$selected_date])) {
        $out .= "There are no recordings for this date!";
        return $out;
    }

    $date_recordings = data_fetch_date_recordings($selected_date);
    // if there are several recordings, show the dropdown
    $recordings_count = count($date_recordings);
    if ($recordings_count > 1) {
        $out .= "There are $recordings_count recording on $selected_date. Please choose:";
        $out .= recording_list($selected_date, $date_recordings, $selected_file_id);

        // if there is only one, show that one directly.
    } else if (count($date_recordings) == 1) {
        $out .= "There is only one recording on $selected_date:<br>";
        $selected_file_id = $date_recordings[0]->id;
    }

    // now show the either only one recording or the one selected from the dropdown
    if (!is_null($selected_file_id)) {
        $one_recording = data_fetch_one_recording($selected_file_id);
        $out .= recording_details($one_recording);
    }

    if ($UMS['debug_mode'] == 'on') {
        $out .= "<div id='tab4'>" . debug_display() . "</div>";
    }

    return $out;
}

/**
 * Display the date picker for the available dates
 *
 * @global type $wp
 * @param string $last_date The last selected date
 * @param array $data An array of available dates
 * @return string The HTML markup for the date picker
 */
function recording_date_picker(string $last_date, array $data) {
    global $wp;
    $data_field = 'ums_datepicker';
    $out = "\n     <script type=\"text/javascript\">
    ums_available_dates = [\"" . implode("\",\"", array_keys($data)) . "\"];
    jQuery(document).ready(function($) {
        ums_datepicker_ready('$last_date', '$data_field');
    });
    var ajaxurl = \"" . home_url($wp->request) . "\";
    </script>";
    $out .= "<form method=\"GET\" id=\"ums_datepicker_form\">
        <div id=\"ums_datepicker_wrap\">Please select the date your gig was recorded:
        <input id=\"$data_field\" name=\"date\" type=\"text\" value=\"$last_date\" size=\"10\">
            </div></form>\n";
    return $out;
}

/**
 * Display the available recordings for a specific date
 *
 * @param string $date The date for which the recordings are being displayed
 * @param array $selected_date_data An array containing the data for the selected date
 * @param mixed $selected_file_id The ID of the selected file (optional)
 * @return string The HTML code for the recording selection form
 */
function recording_list(string $date, array $selected_date_data, $selected_file_id = null) {

    $selected_nothing = '';
    if (is_null($selected_file_id)) {
        $selected_nothing = 'selected';
    }
    $out = "<form method=\"GET\" id=\"ums_timeslot_form\">
        <input name=\"date\" type=\"hidden\" value=\"$date\">
        <select id=\"recording\" name=\"id\" onchange=\"this.form.submit()\">\n
        <option disabled $selected_nothing value>Please select the timeslot of the recording</option>\n";

    foreach ($selected_date_data as $filedata) {
        if ($selected_file_id == $filedata->id) {
            $selected = 'selected';
        } else {
            $selected = '';
        }
        $short_start_time = substr($filedata->start_time, 0, 5);
        $short_end_time = substr($filedata->end_time, 0, 5);
        $out .= "<option $selected value=\"$filedata->id\">$short_start_time until $short_end_time</option>\n";
    }
    $out .= "</select>\n</form>\n";
    return $out;
}

/**
 * Display the actual recording and the buy button.
 *
 * @param mixed $D The recording details.
 * @return string The HTML output.
 */
function recording_details($D) {
    global $UMS;

    $short_start_time = substr($D->start_time, 0, 5);
    $short_end_time = substr($D->end_time, 0, 5);

    $costs_video = $UMS['media_price'] / 100;
    // $costs_audio = $UMS['audio_price'] / 100;

    $out = "
        <form method=\"POST\" id=\"ums_buy_form\" rel=\"nofollow\">
            <img src=\"$D->thumbnail_url\"><br>
            <b>File name:</b> $D->file_name<br>
            <b>Recording time:</b> $short_start_time until $short_end_time<br>
            <b>File Size:</b> $D->size<br>
            <b>Price: </b> 500.- HKD<br>
            You will receive a download link via email after payment.
            The link will be active for 1 month.<br>
            <input id=\"special_field\" type=\"text\" name=\"special_field\" value=\"\">
            <input name=\"launch_sales_id\" type=\"hidden\" value=\"$D->id\">";
    $out .= "        <input name=\"buynow_video\" type=\"submit\" value=\"Buy Video ($costs_video HKD)\">";
    // $out .= "        <input name=\"buynow_audio\" type=\"submit\" value=\"Buy Audio only ($costs_audio HKD)\">";
    $out .= "        <script>document.getElementById('special_field').style.display = 'none';</script>
        </form>
    ";

    return $out;
}

/**
 * Executes the purchase process.
 *
 * This function is responsible for processing the purchase request.
 * It retrieves the necessary data from the POST request, performs validation checks,
 * creates the Stripe product and price objects if necessary, creates a new Stripe session,
 * and redirects the user to the payment page.
 *
 * This function is executed by an add_action in the main file.
 * It's executed on every call, otherwise the redirect would not work
 *
 * @return void
 */
function execute_purchase() {
    $purchase_file_id = filter_input(INPUT_POST, 'launch_sales_id', FILTER_SANITIZE_NUMBER_INT);
    // this is a honey-pot field to deter bots. IT's hidden via JS,
    // but if there is content, we know the user is fake.
    $special_field = filter_input(INPUT_POST, 'special_field');

    $check_honeypot = (is_null($special_field) || $special_field == '');

    $purchase_type = filter_input(INPUT_POST, 'buynow_video', FILTER_SANITIZE_NUMBER_INT);

    if ($check_honeypot && intval($purchase_file_id) > 0) {
        // let's get the product ID from the DB
        $P = data_fetch_one_recording($purchase_file_id);

        // first create the stripe product
        if ($P->stripe_product_id == '') {
            $product_object = stripe_create_product($P->file_name, $P->description, array($P->thumbnail_url));
            $product_id = $product_object->id;
        } else {
            $product_id = $P->stripe_product_id;
        }
        // second create the stripe price object
        if ($P->stripe_price_id == '') {
            $price_object = stripe_create_price($product_id);
            $price_id = $price_object->id;
        } else {
            $price_id = $P->stripe_price_id;
        }
        // lastly let's create the stripe session, that is always new
        $session = stripe_create_session($price_id);

        data_prime_sales_session($purchase_file_id, $session->id, $product_id, $price_id);

        // now send the user to the payment page
        wp_redirect($session->url);
    }
}


/**
 * once a sales transaction is completed on stripe, we get all the session data
 * from stripe, create the share on the nextcloud server and send everything to
 * the customer
 *
 * @global \ums\type $UMS
 * @param type $session_id
 * @return string
 */
function show_sales_result($session_id) {
    global $UMS;
    // get the sesson data

    $session_object = stripe_get_session_data($session_id);

    $user_email = $session_object->customer_details->email;
    $user_name = $session_object->customer_details->name ;
    $payment_status = $session_object->payment_status; // paid
    $status = $session_object->status; // complete

    if ($payment_status == 'paid' && $status == 'complete') {
        $file_path = data_get_file_from_session($session_id);

        $date_obj = date_create($UMS['nextcloud_share_time'], new \DateTimeZone(wp_timezone_string()));
        $expiry = date_format($date_obj, 'Y-m-d');

        $share_url = nc_create_share($file_path, $expiry);

        data_finalize_sales_session($session_id, $user_name, $user_email, $share_url, $expiry);

        $config_text= nl2br($UMS['success_text_email']);

        // replace the variables:

        $searches = array( '{{username}}', '{{link}}', '{{expiry}}');
        $html_url = "<a href=\"$share_url\">$share_url</a>";
        $replacements = array( $user_name, $html_url, $expiry);

        $out = str_replace($searches, $replacements, $config_text);

        wp_mail($user_email, "Your Media Purchase", $out);

        $test_warning = '';
        if ($UMS['stripe_mode'] == 'test') {
            $test_warning = "This was not a proper purchase but only done in test mode.<Br>";
        }

        // send email to admin
        $message = "Hi,<br><br>
            A media file on your website was sold.<br>
            $test_warning
            customer name: $user_name<br>
            customer email: $user_email<br>
            File Path: $file_path<br>
            File share Link: $html_url<br>
            File Share link will expire: $expiry<br>
        ";

        wp_mail($UMS['success_admin_email'], "Media File Sales report", $message);
    } else {
        $out = "There was an issue with the transaction. Please contact us if you have trouble.";
    }

    if ($UMS['debug_mode'] == 'on') {
        $out .= "<div id='tab4'>" . debug_display() . "</div>";
    }

    return $out;
}
