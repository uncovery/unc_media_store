<?php

class nextcloud {
    private $hostname;
    private $username;
    private $password;
    private $rootfolder = '';
    private $debug = false; //options: web console log false

    /**
     * class constructor to get variables set only.
     *
     * @param type $in_host
     * @param type $in_user
     * @param type $in_pass
     * @param type $in_root
     * @param type $in_debug
     */
    public function __construct($in_host, $in_user, $in_pass, $in_root, $in_debug) {
        $this->hostname = $in_host;
        $this->username = $in_user;
        $this->password = $in_pass;
        $this->rootfolder = $in_root;
        $this->debug = $in_debug;
    }

    /**
     * Reads a Nextcloud folder recursively to a given depth and returns all the contents.
     *
     * @return type The contents of the Nextcloud folder.
     */
    public function read_folder($depth) {

        $url = "remote.php/dav/files/$this->username/$this->rootfolder";

        $output = $this->curl_prepare($url, "PROPFIND", array('Depth' =>$depth));
        $xml = simplexml_load_string($output);

        if ($xml === false) {
            throw new Exception("No files could be found on nextcloud");
        }

        $ns = $xml->getNamespaces(true);
        $files = $xml->children($ns['d']);

        $this->debug("found files:" . count($files));

        return $files;
    }

    /**
     * Filters all files that are not valid according to the settings.
     *
     * @param array $files The array of files to be filtered.
     * @return array The filtered array of files.
     */
    public function filter_files($files, array $content_types) {

        $files_copy = array();
        foreach ($files as $F) {
            $P = $F->propstat->prop;
            // let's skip directories
            if (isset($P->resourcetype->collection))  {
                $this->debug("skipping folder", 'nc_filter_files');
                continue;
            }
            // if we have a content type, check it against the config list
            if (isset($content_types[$P->getcontenttype->__toString()])) {
                $files_copy[] = $F;
            } else {
                $this->debug("skipping file of content type" . $P->getcontenttype->__toString(), 'filter_files');
            }
        }

        $this->debug("files left after filtering: " . count($files_copy));
        return $files_copy;
    }

    /**
     * Delete a file on the Nextcloud storage
     *
     * @global \ums\type $UMS
     * @param type $file
     */
    public function delete_file($file) {
        // then, file to be deleted
        $url_file = "remote.php/dav/files/$this->username/$this->rootfolder$file";

        $this->debug("deleting file on NC instance: $url_file");

        $this->curl_prepare($url_file, "DELETE", [], []);
    }

    /**
     * Move a file on the Nextcloud storage. Replaces spaces with underscores for all files.
     *
     * @global string $nc_auth
     * @global string $nc_url
     * @param string $file The name of the file to be moved.
     * @param string $target_folder The target folder where the file will be moved to.
     */
    public function move_file($file, $target_folder) {

        // first, we create the folder
        $url = "remote.php/dav/files/$this->username/$this->rootfolder$target_folder";
        $this->curl_prepare($url , "MKCOL");

        // then, move the file to the folder
        $url_file = "remote.php/dav/files/$this->username/$this->rootfolder$file";

        // make sure we replace spaces in the file
        $str_arr = array(' ', '%20');
        $fixed_file = str_replace($str_arr, "_", $file);

        $url_dest = "{$this->hostname}remote.php/dav/files/$this->username/$this->rootfolder$target_folder/$fixed_file";

        $this->debug("moving file on NC instance to $target_folder", 'move_file');

        $this->curl_prepare($url_file, "MOVE", array('Destination' => $url_dest));
    }

    /**
     * Downloads a file from Nextcloud WEBDAV via PHP CURL.
     *
     * @param string $path The path of the file to download.
     * @param string|false $target The target file path to save the downloaded file. If false, the file content will be returned.
     * @return string|false The file content if $target is false, otherwise returns true on successful download or false on failure.
     */
    public function download_file($path, $target = false) {
        $url = "remote.php/dav/files/$this->username/$this->rootfolder$path";
        $output = $this->curl_prepare($url);

        if (strlen($output) == 0) {
            return false;
        }

        if ($target) {
            $fp = fopen($target, "w");
            if ($fp) {
                fwrite($fp, $output);
                fclose($fp);
            } else {
                throw new Exception("Error creating file: $target!");
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
    public function create_share($path, $expiry) {
        $url = "ocs/v2.php/apps/files_sharing/api/v1/shares";

        $final_path = "/$this->rootfolder/$path";

        $post_fields = array(
            'path' => $final_path, 'shareType' => 3, 'Permission' => 1, 'expireDate' => $expiry,
        );

        $output = $this->curl_prepare($url, false, array('OCS-APIRequest' => 'true'), $post_fields);

        $this->debug($output, 'create_share -> output');

        // convert the resulting XML String to XML objects
        $xml = simplexml_load_string($output);

        $this->debug($xml, 'create_share -> xml');
        // convert it to JSON
        $json = json_encode($xml);
        // convert JSON to array
        $array = json_decode($json,TRUE);

        $this->debug($json, 'create_share -> json');

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
    private function curl_prepare(string $url, $request = false, array $headers = [], array $post_fields = [], bool $debug = false) {
        $options = array(
            CURLOPT_URL => $this->hostname . $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERPWD => "$this->username:$this->password",
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
        $output = $this->curl_execute($options);

        return $output;
    }

    /**
     * Executes a cURL request with the given options.
     *
     * @param array $options The cURL options to apply.
     * @param bool $debug (optional) Whether to enable debug mode. Default is false.
     * @return mixed The output of the cURL request, or false if there was an error.
     */
    private function curl_execute(array $options) {
        // open the connection
        $ch = curl_init();

        // apply all the options
        foreach ($options as $k => $v) {
            curl_setopt($ch, $k, $v);
        }

        $output = curl_exec($ch);

        // close the connection
        curl_close($ch);

        // check for error
        if ($output === false) {
            throw new Exception("The Nextcloud connection failed. Please check your URL. Options used: " . var_export($options, true));
        } else if (strstr($output, 'Sabre\\DAV\\Exception')) {
            throw new Exception("There was an error connecting to Nextcloud. The returned error was:<br><pre>$output</pre>");
        }
        return $output;
    }

    /**
     * debug info
     *
     * @param type $info
     * @param type $target
     */
    private function debug($info) {
        // check where debug was called
        $trace = debug_backtrace();
        $source = "{$trace[1]['function']}";
        if (isset($trace[1]['class'])) {
            $source . " in class {$trace[1]['class']}";
        }

        $text = "NextCloud Debug: " . var_export($info, true) . " Source: $source";

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