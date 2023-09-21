<?php

global $root, $debug, $timezone, $video_date_format, $video_date_sample_string;
$root = '/home/uncovery/Nextcloud/recording';
$timezone = 'Asia/Hong_Kong';
$debug = true;
// Define the filename format here
// the length of the sample string needs to match the date format below
$video_date_sample_string = '2023-09-20 18-01';
$video_date_format = 'Y-m-d H-i';
$valid_file_extensions = array('mp4');

date_default_timezone_set($timezone);
read_files();


function read_files() {
    global $root, $video_date_sample_string, $valid_file_extensions;

    $di = new RecursiveDirectoryIterator($root);
    foreach (new RecursiveIteratorIterator($di) as $file_path => $file) {
        // exclude invalid file extensions and directories
        if ($file->isDir() || in_array($file->getExtension(), $valid_file_extensions)) {
            continue;
        }
        debug_info( '------------- START FILE -------------');
        debug_info( "FOUND FILE: $file_path ");
        $filename = basename($file_path);
        $start_date = substr($filename, 0, 10);
        // OBS file names start with the date
        $folder = dirname($file_path);

        // check the file date so that we delete old files
        $age = calculate_date_age($start_date);
        if ($age->m > 2) {

            debug_info( "File is old, deleting it! ");
            // unlink($file_path);
            continue;
        }

        // echo "filesize : " . filesize($file_path) . "\n";
        // 100 GB limit, delete the file
        if (filesize($file_path) > 100000000000) {
            debug_info( "File is too big, deleting it! ");
            // unlink($file_path);
            continue;
        }

        // check if we need to move the file
        if ($folder == $root) {
            debug_info( "File is in root, moving it!");
            $duration = get_video_length($file_path);
            if (!$duration) {
                continue;
            }

            // 2023-09-20 18-01
            $start_time = substr($filename, 0, strlen($video_date_sample_string));

            debug_info("Start time: $start_time");

            $end_time = calculate_end_time($start_time, $duration);

            debug_info("End time: $end_time");

            $target_filename = str_replace(" ", "_", $start_time) . "_" . $end_time . ".mp4";

            debug_info("target filename: $target_filename");

            $target_folder = $root . "/" . date_folder($start_date);

            if (!file_exists($target_folder)) {
                mkdir($target_folder, 0777, true);
            }

            $target_path = "$target_folder/$target_filename";

            debug_info("Moving $file_path to $target_path");
            $rename_check = rename($file_path, $target_path);
            create_gallery($target_path);
            return;
        } else {
            debug_info("File is not root, skipping moving file... ");
        }
    }
}

/**
 * calculate the age of a date
 *
 * @global string $timezone
 * @param type $file_date
 * @return type
 */
function calculate_date_age(string $file_date) {
    // let's determine the current month
    global $timezone;
    $date_obj_now = new DateTime();
    $date_obj_now->setTimezone(new DateTimeZone($timezone));

    $date_obj_file = new DateTime($file_date);
    $date_obj_file->setTimezone(new DateTimeZone($timezone));
    $interval = $date_obj_now->diff($date_obj_file);
    return $interval;
}

/**
 * use FFMPEG to calculate a video length
 *
 * @param string $file_path
 * @return bool
 */
function get_video_length(string $file_path)  {
    $command = 'ffmpeg -i "' . $file_path . '" 2>&1 | grep "Duration"';
    $return = shell_exec($command);

    if (is_null($return)) {
        echo "ERROR: Could not determine video length!";
        return false;
    }

    debug_info("Video length check result:" . var_export($return, true));

    //  Duration: 01:09:22.11, start: 0.000000, bitrate: 5648 kb/s
    $hours = substr($return, 12, 2);
    $minutes = substr($return, 15,2);

    $final = array(
        'hours' => intval($hours),
        'minutes' => intval($minutes),
    );

    return $final;
}

/**
 * calculate the end time of a video based on the duration result of get_video_length()
 *
 * @global string $timezone
 * @global string $video_date_format
 * @param string $start_time
 * @param array $duration
 * @return type
 */
function calculate_end_time(string $start_time, array $duration) {
    global $timezone, $video_date_format;
    $hours = $duration['hours'];
    $minutes = $duration['minutes'];

    $date = date_create_from_format($video_date_format, $start_time, new DateTimeZone($timezone));

    $timestamp = date_format($date, "U");

    $end_time_raw = strtotime("+$hours hour +$minutes minute", $timestamp);

    $end_time = date('H-i', $end_time_raw);

    debug_info("adding $hours h and $minutes m to $start_time: result is $end_time");

    return $end_time;
}

/**
 * create a video gallery with VCS
 * The filename will simply have a '.jpg' added to the original file
 * https://www.baeldung.com/linux/generate-video-thumbnails-gallery
 * https://p.outlyer.net/vcs#links
 * @param string $video_path
 */
function create_gallery(string $video_path) {
    $gallery_path = "$video_path.jpg";

    if (!file_exists($gallery_path)) {
        debug_info("creating gallery for $gallery_path");

        $command = "vcs '$video_path' -U0 -n 4 -c 2 -H 200 -o $gallery_path";
        shell_exec($command);

        // delete empty galleries and return
        if (filesize($gallery_path) == 0) {
            unlink($gallery_path);
            echo "ERROR CREATING GALLERY: $gallery_path filesize is null\n";
        }
    }
}

/**
 * get a string date and create a year/month folder out of it
 *
 * @param string $start_date
 * @return bool
 */
function date_folder(string $start_date) {
    $unix_time = strtotime($start_date);
    if (!$unix_time) {
        return false;
    }
    $folder = date("Y/m", $unix_time);

    return $folder;
}

/**
 * support function to output debug info to the command line
 * @global bool $debug
 * @param string $text
 */
function debug_info(string $text) {
    global $debug;

    if ($debug) {
        echo "DBG: $text\n";
    }
}
