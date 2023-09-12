<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * we need to check if the stripe login works before we can process stuff
 * 
 * @return bool
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
 * 
 * @param type $product_name
 * @param type $description
 * @param type $images
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

function stripe_query_product(string $product_id) {
    
    return stripe_curl_command('products/' . $product_id);
}

function stripe_create_price(string $product_id) {
    global $UMS;

    $data = array(
        'unit_amount' => $UMS['media_price'],
        'currency' => 'hkd',
        'product' =>  $product_id,
    );
 
    return stripe_curl_command('prices', $data);
}
    
function stripe_create_payment_link(object $price_object) {
    $data = array(
        'line_items[0][price]' => $price_object->id,
        'line_items[0][quantity]' => 1,
    );

    return stripe_curl_command('payment_links', $data);
}

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
    curl_setopt($ch, CURLOPT_URL, curl_fix_path('https://api.stripe.com/v1/' . $path));
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
 * returns the stripe key and secret, depending of we are live or testing
 * 
 * @global type $UMS
 * @return type
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