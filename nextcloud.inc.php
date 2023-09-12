<?php
namespace ums;
if (!defined('WPINC')) {
    die;
}

/**
 * read a nextcloud folder recurseively to a given depth and return all the contents
 *
 * @global \ums\type $UMS
 * @return type
 */
function nc_curl_read_folder() {
    global $UMS;

    $url = "remote.php/dav/files/"
        . $UMS['nextcloud_username'] . "/" .  $UMS['nextcloud_folder'];


    $output = nc_curl($url , "PROPFIND", array('Depth' => $UMS['nextcloud_folder_depth']));
    $xml = simplexml_load_string($output);

    if ($xml === false) {
        echo user_alert("No files could be found on nextcloud");
        return;
    }

    $ns = $xml->getNamespaces(true);
    $files = $xml->children($ns['d']);

    return $files;
}

/**
 * filter all files that are not valid according to the settings
 *
 * @param type $files
 * @return type
 */
function nc_filter_files($files) {
    global $UMS;

    $files_copy = array();
    foreach ($files as $F) {
        $P = $F->propstat->prop;
        // let's skip directories
        if (isset($P->resourcetype->collection))  {
            continue;
        }
        // if we have a content type, use it
        if ((strtolower(trim($UMS['nextcloud_content_type'])) == 'false') || ($P->getcontenttype->__toString() == $UMS['nextcloud_content_type'])) {
            $files_copy[] = $F;
        }
    }
    return $files_copy;
}

/**
 *  move a file on the nextcloud storage. Replaces spaces with _ for all files.
 * @global string $nc_auth
 * @global string $nc_url
 * @param type $file
 * @param type $target_folder
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

    nc_curl($url_file, "MOVE", array('Destination' => $url_dest));
}

/**
 * download a file from nextcloud WEBDAV via PHP CURL
 * @global type $UMS
 * @param type $path
 * @param type $target
 * @return type
 */
function nc_download_file($path, $target = false) {
    global $UMS;

    $url = "remote.php/dav/files/{$UMS['nextcloud_username']}/{$UMS['nextcloud_folder']}$path";
    $output = nc_curl($url);

    if ($target) {
        $fp = fopen($target, "w");
        if ($fp) {
            fwrite($fp, $output);
            fclose($fp);
        } else {
            echo "Error creating file $target during thumbnail download!<br>";
        }
    } else {
        return $output;
    }
}

/**
 * Create a share on nextcloud and return the share URL.
 *
 * @global type $UMS
 * @param type $path
 * @param type $target
 * @return type
 */
function nc_create_share($path, $expiry) {
    global $UMS;

    $url = "ocs/v2.php/apps/files_sharing/api/v1/shares";

    $final_path = "/" . $UMS['nextcloud_folder'] . $path;

    $post_fields = array(
        'path' => $final_path, 'shareType' => 3, 'Permission' => 1, 'expireDate' => $expiry,
    );

    $output = nc_curl($url, "MOVE", array('OCS-APIRequest' => 'true'), $post_fields);

    // convert the resulting XML String to XML objects
    $xml = simplexml_load_string($output);
    // convert it to JSON
    $json = json_encode($xml);
    // convert JSON to array
    $array = json_decode($json,TRUE);

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
 * @return type
 */
function nc_curl(string $url, $request = false, array $headers = [], array $post_fields = []) {
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
    $output = nc_curl_execute($options);

    return $output;
}

function nc_curl_execute(array $options, bool $debug = false) {
    // open the connection
    $ch = curl_init();

    // apply all the options
    foreach ($options as $k => $v) {
        curl_setopt($ch, $k, $v);
    }

    if ($debug){
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $streamVerboseHandle = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $streamVerboseHandle);
    }

    // execture the cURL
    $output = curl_exec($ch);

    if ($debug){
        rewind($streamVerboseHandle);
        $verboseLog = stream_get_contents($streamVerboseHandle);

        debug_info("cUrl verbose information:\n",
             "<pre>", htmlspecialchars($verboseLog), "</pre>\n");
    }

    // check for errors
    if ($output === false) {
        echo user_alert("The Nextcloud connection failed. Please check your URL");
        return false;
    } else if (strstr($output, 'Sabre\\DAV\\Exception')) {
        echo user_alert("There was an error connecting to Nextcloud. The returned error was:<br><pre>$output</pre>");
        return false;
    }
    // close the connection
    curl_close($ch);
}