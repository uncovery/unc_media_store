<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * Reads a Nextcloud folder recursively to a given depth and returns all the contents.
 *
 * @global \ums\type $UMS The global UMS variable.
 * @return type The contents of the Nextcloud folder.
 */
function nc_curl_read_folder() {
    global $UMS;

    $url = "remote.php/dav/files/{$UMS['nextcloud_username']}/{$UMS['nextcloud_folder']}";

    $output = nc_curl($url, "PROPFIND", array('Depth' => $UMS['nextcloud_folder_depth']));
    $xml = simplexml_load_string($output);

    if ($xml === false) {
        debug_info($output, __FUNCTION__);
        echo user_alert("No files could be found on nextcloud");
        return;
    }

    $ns = $xml->getNamespaces(true);
    $files = $xml->children($ns['d']);

    debug_info("found files:" . count($files) , 'nc_curl_read_folder');

    return $files;
}

/**
 * Filters all files that are not valid according to the settings.
 *
 * @param array $files The array of files to be filtered.
 * @return array The filtered array of files.
 */
function nc_filter_files($files) {
    global $UMS;

    $files_copy = array();
    foreach ($files as $F) {
        $P = $F->propstat->prop;
        // let's skip directories
        if (isset($P->resourcetype->collection))  {
            debug_info("skipping folder", 'nc_filter_files');
            continue;
        }
        // if we have a content type, check it against the config list
        if (isset($UMS['nextcloud_content_types'][$P->getcontenttype->__toString()])) {
            $files_copy[] = $F;
        } else {
            debug_info("skipping file of content type" . $P->getcontenttype->__toString(), 'nc_filter_files');
        }
    }

    debug_info("files left after filtering: " . count($files_copy), 'nc_filter_files');

    return $files_copy;
}

/**
 * Delete a file on the Nextcloud storage
 *
 * @global \ums\type $UMS
 * @param type $file
 */
function nc_delete_file($file) {
    global $UMS;

    // then, file to be deleted
    $url_file = "remote.php/dav/files/" . $UMS['nextcloud_username'] . "/" .  $UMS['nextcloud_folder'] . "/$file";

    debug_info("deleting file on NC instance", 'nc_delete_file');

    nc_curl($url_file, "DELETE", [], [], true);
}

/**
 * Move a file on the Nextcloud storage. Replaces spaces with underscores for all files.
 *
 * @global string $nc_auth
 * @global string $nc_url
 * @param string $file The name of the file to be moved.
 * @param string $target_folder The target folder where the file will be moved to.
 */
function nc_move_file($file, $target_folder) {
    global $UMS;

    // first, we create the folder
    $url = "remote.php/dav/files/" . $UMS['nextcloud_username'] . "/" .  $UMS['nextcloud_folder'] . "/" . $target_folder;
    nc_curl($url , "MKCOL", array('Depth' => $UMS['nextcloud_folder_depth']));

    // then, move the file to the folder
    $url_file = "remote.php/dav/files/" . $UMS['nextcloud_username'] . "/" .  $UMS['nextcloud_folder'] . "/$file";

    // make sure we replace spaces in the file
    $str_arr = array(' ', '%20');
    $fixed_file = str_replace($str_arr, "_", $file);

    $url_dest = $UMS['nextcloud_url'] . "remote.php/dav/files/"
        . $UMS['nextcloud_username'] . "/" .  $UMS['nextcloud_folder'] . "/$target_folder/$fixed_file";

    debug_info("moving file on NC instance to $target_folder", 'nc_move_file');

    nc_curl($url_file, "MOVE", array('Destination' => $url_dest));
}

/**
 * Downloads a file from Nextcloud WEBDAV via PHP CURL.
 *
 * @param string $path The path of the file to download.
 * @param string|false $target The target file path to save the downloaded file. If false, the file content will be returned.
 * @return string|false The file content if $target is false, otherwise returns true on successful download or false on failure.
 */
function nc_download_file($path, $target = false) {
    global $UMS;

    $url = "remote.php/dav/files/{$UMS['nextcloud_username']}/{$UMS['nextcloud_folder']}$path";
    $output = nc_curl($url);

    if (strlen($output) == 0) {
        return false;
    }

    if ($target) {
        $fp = fopen($target, "w");
        if ($fp) {
            fwrite($fp, $output);
            fclose($fp);
        } else {
            echo "Error creating file $target during thumbnail download!<br>";
            return false;
        }
    } else {
        return $output;
    }
}

/**
 * Create a share on Nextcloud and return the share URL.
 *
 * @global type $UMS
 * @param type $path The path of the file or folder to be shared.
 * @param type $expiry The expiry date of the share.
 * @return type The share URL.
 */
function nc_create_share($path, $expiry) {
    global $UMS;

    $url = "ocs/v2.php/apps/files_sharing/api/v1/shares";

    $final_path = "/" . $UMS['nextcloud_folder'] . $path;

    $post_fields = array(
        'path' => $final_path, 'shareType' => 3, 'Permission' => 1, 'expireDate' => $expiry,
    );

    $output = nc_curl($url, false, array('OCS-APIRequest' => 'true'), $post_fields);

    debug_info($output, 'nc_create_share -> output');

    // convert the resulting XML String to XML objects
    $xml = simplexml_load_string($output);

    debug_info($xml, 'nc_create_share -> xml');
    // convert it to JSON
    $json = json_encode($xml);
    // convert JSON to array
    $array = json_decode($json,TRUE);

    debug_info($json, 'nc_create_share -> json');

    return $array['data']['url'];
}

/**
 * Prepare a cURL execution by assembling all the variables for the different use cases.
 *
 * @global \ums\type $UMS
 * @param string $url
 * @param type $request
 * @param array $headers
 * @param array $post_fields
 * @param bool $debug
 * @return type
 */
function nc_curl(string $url, $request = false, array $headers = [], array $post_fields = [], bool $debug = false) {
    global $UMS;

    $options = array(
        CURLOPT_URL => $UMS['nextcloud_url'] . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERPWD => "{$UMS['nextcloud_username']}:{$UMS['nextcloud_password']}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    );

    // custom requests such as 'PROPFIND' etc
    if ($request) {
        $options[CURLOPT_CUSTOMREQUEST] = $request;
    }

    // headers needs to be an array of strings in the format 'key: value'
    if (count($headers) > 0 ){
        foreach ($headers as $key => $value) {
            $headers[] = "$key: $value";
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    // post fields need to be in the form or a URL parameter
    // key1=value1&key2=value2
    if (count($post_fields) > 0) {
        $fields = array();
        foreach ($post_fields as $key => $value) {
            $fields[] = "$key=$value";
        }
        $post_fields = implode("&", $fields);
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $post_fields;
    }

    // actually exectute the CURL Command and check for issues
    $output = nc_curl_execute($options, $debug);

    return $output;
}

/**
 * Executes a cURL request with the given options.
 *
 * @param array $options The cURL options to apply.
 * @param bool $debug (optional) Whether to enable debug mode. Default is false.
 * @return mixed The output of the cURL request, or false if there was an error.
 */
function nc_curl_execute(array $options, bool $debug = false) {
    global $UMS;
    // open the connection
    $ch = curl_init();

    // apply all the options
    foreach ($options as $k => $v) {
        curl_setopt($ch, $k, $v);
    }

    if ($UMS['debug_mode'] == 'on'){
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $streamVerboseHandle = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $streamVerboseHandle);
    }

    // execture the cURL
    if ($debug) {
        $dbg = var_export($ch, true);
        debug_info($dbg , __FUNCTION__);
    } else {
        $output = curl_exec($ch);
    }
    // close the connection
    curl_close($ch);

    if ($UMS['debug_mode'] == 'on'){
        rewind($streamVerboseHandle);
        $verboseLog = stream_get_contents($streamVerboseHandle);
        debug_info($verboseLog , __FUNCTION__);
    }

    // check for errors
    if ($output === false) {
        echo user_alert("The Nextcloud connection failed. Please check your URL");
        return false;
    } else if (strstr($output, 'Sabre\\DAV\\Exception')) {
        echo user_alert("There was an error connecting to Nextcloud. The returned error was:<br><pre>$output</pre>");
        return false;
    }
    return $output;
}