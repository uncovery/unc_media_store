<?php

class stripe {
    public string $mode; // test or live
    public string $api_secret_key;
    public string $api_test_secret_key;
    public string $debug;

    /**
     * class constructor, needed to set all variables to opreate
     *
     * @param string $mode
     * @param string $api_secret_key
     * @param string $api_test_secret_key
     * @param string $debug
     */
    public function __construct(string $mode, string $api_secret_key, string $api_test_secret_key, string $debug) {
        $this->mode = $mode;
        $this->api_secret_key = $api_secret_key;
        $this->api_test_secret_key = $api_test_secret_key;
        $this->debug = $debug;
    }

    /**
     * This function checks if the Stripe login works without any further actions.
     *
     * @return string|bool Returns true if the Stripe login works, false otherwise.
     */
    public function test_login() {
        $result = $this->curl_command('products');

        if (isset($result->error)) {
            return $result->error->{'message'};
        } else {
            return true;
        }
    }

    /**
     * Creates a new product in Stripe.
     * @param string $product_name
     * @param string $description
     * @param string $statement_descriptor
     * @param array $image_urls
     * @return type
     */
    public function create_product(string $product_name, string $description = '', string $statement_descriptor = '', array $image_urls = []) {
        $data = array(
            'name' => $product_name,
            'shippable' => 'false',
            'statement_descriptor' => $statement_descriptor,
            'description' => $description,
            'images' => $image_urls,
        );

        $product_object = $this->curl_command('products', $data);
        return $product_object;
    }

    /**
     * Queries a Stripe product by its ID.
     *
     * @param string $product_id The ID of the product to query.
     * @return mixed The result of the Stripe API call.
     */
    public function query_product(string $product_id) {

        $product_object = $this->curl_command('products/' . $product_id);

        return $product_object;
    }

    /**
     * Creates a new price object for a given product ID in Stripe.
     *
     * @param string $product_id
     * @param int $price
     * @param string $currency
     * @return type
     */
    public function create_price(string $product_id, int $price, string $currency) {
        $data = array(
            'unit_amount' => $price,
            'currency' => $currency,
            'product' =>  $product_id,
        );

        $price_object = $this->curl_command('prices', $data);

        return $price_object;
    }

    /**
     * Creates a payment link for a given price object.
     *
     * @param object $price_object
     * @param int $quantity
     * @return string the payment link
     */
    public function create_payment_link(object $price_object, int $quantity = 1) {
        $data = array(
            'line_items[0][price]' => $price_object->id,
            'line_items[0][quantity]' => $quantity,
        );

        $payment_link = $this->curl_command('payment_links', $data);

        return $payment_link;
    }

    /**
     * Creates a new Stripe checkout session for the given price ID.
     *
     * @param string $success_url the URL Where the user will be sent to after a successful checkout
     * @param string $price_id The Price object ID to be used
     * @param int $quantity how many items of the above price ID will be bought
     * @return type
     */
    public function create_session(string $success_url, string $price_id, int $quantity = 1) {
        $data = array(
            'success_url' => urlencode($success_url. '?session_id={CHECKOUT_SESSION_ID}'),
            'line_items[0][price]' => $price_id,
            'line_items[0][quantity]' => $quantity,
            'mode' => 'payment',
        );

        $session_object = $this->curl_command('checkout/sessions', $data);

        return $session_object;
    }

    /**
     * list the active products on stripe
     * @return type
     */
    public function list_products() {

        return $this->curl_command('products');
    }

    /**
     * Retrieves session data from Stripe API using session ID.
     *
     * @param string $session_id The ID of the session to retrieve data for.
     * @return mixed Returns the session data as an array or null if session not found.
     */
    public function get_session_data(string $session_id) {

        return $this->curl_command('checkout/sessions/' . $session_id);
    }

    /**
     * execute a stripe cURL command and return the result
     *
     * @param string $path
     * @param type $data
     * @param type $request
     * @return type
     */
    public function curl_command(string $path, $data = array(), $request = false) {
        $K = $this->stripe_key();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERPWD, "$K:");
        if ($request) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request);
        }
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        if (count($data) > 0) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->make_array_string($data));
        }
        // POST content type application/x-www-form-urlencoded
        $output = curl_exec($ch);
        curl_close($ch);

        return json_decode($output);
    }

    /**
     * Returns the Stripe API key and secret based on whether the website is in live or test mode.
     *
     * @return array An array containing the Stripe API key.
     */
    public function stripe_key() {
        $key_data = array(
            'live' => $this->api_secret_key,
            'test' => $this->api_test_secret_key,
        );

        return $key_data[$this->mode];
    }

    /**
     * Returns the Stripe dashboard URL based on whether the website is in live or test mode.
     *
     * @return array An array containing the URL for the Stripe dashboard.
     */
    public function stripe_url() {
        $key_data = array(
            'live' => 'https://dashboard.stripe.com/',
            'test' => 'https://dashboard.stripe.com/test/',
        );

        return $key_data[$this->mode];
    }

    /**
     * Converts a multidimensional array into a string representation of key-value pairs.
     *
     * This function takes an array as input and iterates through its elements. If an element is an array itself,
     * it further iterates through its sub-elements and appends the key-value pairs to the resulting string.
     * If an element is not an array, it simply appends the key-value pair to the resulting string.
     *
     * @param array $data_array The input array to be converted.
     * @return string The string representation of the key-value pairs.
     */
    private function make_array_string($data_array) {
        $data_pairs = array();
        foreach ($data_array as $key => $value) {
            if (is_array($value) && count($value) > 0) {
                foreach ($value as $sub_key => $sub_value) {
                    $data_pairs[] = $key . "[" . $sub_key . "]=$sub_value";
                }
            } else {
                $data_pairs[] = "$key=$value";
            }

        }
        $data_string = implode("&", $data_pairs);
        return $data_string;
    }

    /**
     * debug function
     *
     * @param string $info
     * @return string
     * @throws Exception
     */
    private function debug(string $info) {
        // check where debug was called
        $trace = debug_backtrace();
        $source = "{$trace[1]['function']}";
        if (isset($trace[1]['class'])) {
            $source . " in class {$trace[1]['class']}";
        }

        $text = "Stripe Debug: " . var_export($info, true) . " Source: $source";

        switch ($this->debug) {
            case 'web':
                echo "$text<br>";
                break;
            case 'console':
                echo "$text\n";
                break;
            case 'log':
                error_log($text);
                break;
            case false:
                return;
            default:
                throw new Exception("Invalid debug format: $this->debug");
        }
    }
}
