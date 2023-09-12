<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}
/**
 * This shows the date picker and then the files that the customer can buy
 *
 * @return string
 */
function show_interface() {
    // check if we had a sale:
    $session_id = filter_input(INPUT_GET, 'session_id');
    if (!is_null($session_id)) {
        return show_sales_result($session_id);
    }

    // get the data from the DB
    $files_data = read_db();
    $all_dates = data_fetch_dates($files_data);

    // get form subission
    // step 1: Select a date
    $selected_date = filter_input(INPUT_POST, 'ums_date', FILTER_SANITIZE_STRING);
    // step 2: select recording on that date
    $selected_file_id = filter_input(INPUT_POST, 'file_id', FILTER_SANITIZE_NUMBER_INT);

    // validate selected date
    if (validate_date($selected_date, $format = 'Y-m-d') && isset($all_dates[$selected_date])) {
        $last_date = $selected_date;
    } else { //if there is no date slected, pick the latest one
        $last_date = array_key_last($all_dates);
    }

    // create the datepicker JS
    $out = recording_date_picker($last_date, $all_dates);

    // if there is no date selected, let's just send the date picker back
    if (!$selected_date) {
        $out .="</form>";
        return $out;
    }

    // we have a date, let's show the recordings that we have
    if (!isset($all_dates[$selected_date])) {
        $out .= "There are no recordings for this date!";
        return $out;
    }

    $out .= "<h2>Date: $selected_date</h2>\n";
    $date_recordings = data_fetch_date_recordings($last_date);
    // if there are several recordings, show the dropdown
    $recordings_count = count($date_recordings);
    if ($recordings_count > 1) {
        $out .= "There are $recordings_count recording on $selected_date. Please choose:<br>";
        $out .= recording_list($date_recordings, $selected_file_id);

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

    return $out;
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

        $out = "
            <h3> Congratulations! </h3>
            Dear $user_name,<br>
            <br>
            You can now donwload the file here: <a href=\"$share_url\">$share_url</a>.<br>
            This link will be <br>active until $expiry</b>. Please download it as soon as possible.<br>
            <br>
            Thanks a lot for your contribution to live music!<br>
            <br>
            The Wanch<br>
        ";

        wp_mail($user_email, "Your Media Purchase", $out);
    } else {
        $out = "There was an issue with the transaction. Please contact us if you have trouble.";
    }

    return $out;
}

/**
 * Display the date picker for the available dates
 *
 * @param type $data
 * @param type $last_date
 * @param type $selected_date
 * @return string
 */
function recording_date_picker($last_date, $data) {
    global $wp;
    $data_field = 'ums_datepicker';
    $out = "\n     <script type=\"text/javascript\">
    ums_available_dates = [\"" . implode("\",\"", array_keys($data)) . "\"];
    jQuery(document).ready(function($) {
        ums_datepicker_ready('$last_date', '$data_field');
    });
    var ajaxurl = \"" . home_url($wp->request) . "\";
    </script>";
    $out .= "<form method=\"POST\" id=\"ums_datepicker_form\">
        <div id=\"ums_datepicker_wrap\">Please select the date your gig was recorded:
        <input id=\"$data_field\" name=\"ums_date\" type=\"text\" value=\"$last_date\" size=\"10\">
            </div>";
    return $out;
}

/**
 * Display the available recordings for a specific date
 *
 * @param type $selected_date_data
 * @param type $selected_filename
 * @return string
 */
function recording_list($selected_date_data, $selected_file_id = null) {

    if (is_null($selected_file_id)) {
        $selected_nothing = 'selected';
    }
    $out = "<select id=\"recording\" name=\"file_id\" onchange=\"this.form.submit()\">\n
        <option disabled $selected_nothing value>Please select the timeslot of the recording</option>\n";

    foreach ($selected_date_data as $filedata) {
        if ($selected_file_id == $filedata->id) {
            $selected = 'selected';
        } else {
            $selected = '';
        }
        $out .= "<option $selected value=\"$filedata->id\">$filedata->start_date $filedata->start_time - $filedata->end_time}</option>\n";
    }
    $out .= "</select>\n</form>\n";
    return $out;
}

/**
 * display the actual recording and the buy button
 *
 * @global type $UMS
 * @param type $D
 * @param type $selected_date
 * @param type $selected_filename
 * @return string
 */
function recording_details($D) {

    $out = "
        <form method=\"POST\" id=\"ums_buy_form\" rel=\"nofollow\">
            <img src=\"$D->thumbnail_url\"><br>
            <b>File name:</b> $D->file_name<br>
            <b>Recording time:</b> $D->start_time - $D->end_time<br>
            <b>File Size:</b> $D->size<br>
            <b>Price: </b> 500.- HKD<br>
            You will receive a download link via email after payment.<br>
            <input id=\"special_field\" type=\"text\" name=\"special_field\" value=\"\">
            <input name=\"launch_sales_id\" type=\"hidden\" value=\"$D->id\">
            <input name=\"buynow\" type=\"submit\" value=\"Buy now (500 HKD)\">
            <script>document.getElementById('special_field').style.display = 'none';</script>
        </form>
    ";

    return $out;
}

/**
 * When the consumer presses the buy button, we get the file info from the DB,
 * Create the product, the price and the session on the stipe server, then retrieve
 * the session link and forward the customer there so that he can pay for the file
 *
 * This function is executed by an add_action in the main file.
 * It's executed on every call, otherwise the redirect would not work
 *
 */
function execute_purchase() {
    $purchase_file_id = filter_input(INPUT_POST, 'launch_sales_id', FILTER_SANITIZE_NUMBER_INT);
    // this is a honey-pot field to deter bots. IT's hidden via JS, but if there is content, we know the user is fake.
    $special_field = filter_input(INPUT_POST, 'special_field');

    $check_honeypot = (is_null($special_field) || $special_field == '');

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