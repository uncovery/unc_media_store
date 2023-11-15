<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * This function checks if the Stripe login works before processing any further actions.
 *
 * @return bool Returns true if the Stripe login works, false otherwise.
 */
function stripe_test_login() {
    $result = stripe_curl_command('products');

    if (isset($result->error)) {
        echo user_alert("STRIPE CONNECTION ERROR: " . $result->error->{'message'});
        return false;
    } else {
        return true;
    }
}

/**
 * Creates a new product in Stripe.
 *
 * @param string $product_name The name of the product.
 * @param string $description (Optional) A description of the product.
 * @param array $images (Optional) An array of image URLs for the product.
 * @return array The product object returned by Stripe.
 */
function stripe_create_product(string $product_name, string $description = '', array $images = []) {
    global $UMS;

    $data = array(
        'name' => $product_name,
        'shippable' => 'false',
        'statement_descriptor' => $UMS['statement_descriptor'],
        'description' => $description,
        'images' => $images,
    );

    // echo ums_debug_show($product_object);
    return stripe_curl_command('products', $data);
}

/**
 * Queries a Stripe product by its ID.
 *
 * @param string $product_id The ID of the product to query.
 * @return mixed The result of the Stripe API call.
 */
function stripe_query_product(string $product_id) {

    return stripe_curl_command('products/' . $product_id);
}

/**
 * Creates a new price for a given product ID in Stripe.
 *
 * @param string $product_id The ID of the product to create a price for.
 * @return array The response from the Stripe API.
 */
function stripe_create_price(string $product_id) {
    global $UMS;

    $data = array(
        'unit_amount' => $UMS['media_price'],
        'currency' => 'hkd',
        'product' =>  $product_id,
    );

    return stripe_curl_command('prices', $data);
}

/**
 * Creates a payment link for a given price object.
 *
 * @param object $price_object The price object to create the payment link for.
 * @return string The payment link.
 */
function stripe_create_payment_link(object $price_object) {
    $data = array(
        'line_items[0][price]' => $price_object->id,
        'line_items[0][quantity]' => 1,
    );

    return stripe_curl_command('payment_links', $data);
}

/**
 * Creates a new Stripe checkout session for the given price ID.
 *
 * @param string $price_id The ID of the price to use for the checkout session.
 * @return string The response from the Stripe API.
 */
function stripe_create_session(string $price_id) {
    global $wp;

    $data = array(
        'success_url' => urlencode(home_url($wp->request). '?session_id={CHECKOUT_SESSION_ID}'),
        'line_items[0][price]' => $price_id,
        'line_items[0][quantity]' => 1,
        'mode' => 'payment',
    );

    return stripe_curl_command('checkout/sessions', $data);
}

/**
 * list the active products on stripe
 * @return type
 */
function stripe_list_products() {

    return stripe_curl_command('products');
}

/**
 * Retrieves session data from Stripe API using session ID.
 *
 * @param string $session_id The ID of the session to retrieve data for.
 * @return mixed Returns the session data as an array or null if session not found.
 */
function stripe_get_session_data(string $session_id) {

    return stripe_curl_command('checkout/sessions/' . $session_id);
}

/**
 * execute a stripe cURL command and return the result
 *
 * @param string $path
 * @param type $data
 * @param type $request
 * @return type
 */
function stripe_curl_command(string $path, $data = array(), $request = false) {
    $K = stripe_keys();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERPWD, "{$K['secret']}:");
    if ($request) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request);
    }
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (count($data) > 0) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, make_array_string($data));
    }
    // POST content type application/x-www-form-urlencoded
    $output = curl_exec($ch);
    curl_close($ch);

    return json_decode($output);
}


/**
 * Returns the Stripe API key and secret based on whether the website is in live or test mode.
 *
 * @global array $UMS An array containing the Stripe API keys and the mode (live or test).
 * @return array An array containing the Stripe API key and secret, and the URL for the Stripe dashboard.
 */
function stripe_keys() {
    global $UMS;

    $key_data = array(
        'live' => array(
            'secret' => $UMS['stripe_api_secret_key'],
            'url' => 'https://dashboard.stripe.com/',
        ),
        'test' => array(
            'secret' => $UMS['stripe_api_test_secret_key'],
            'url' => 'https://dashboard.stripe.com/test/',
        ),
    );

    $mode = $UMS['stripe_mode'];

    return $key_data[$mode];
}