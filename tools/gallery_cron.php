<?php

/**
 * This script is used to move video files from a source directory to a target directory.
 * The script reads all the video files from the source directory and moves them to the target directory.
 * The video files are renamed based on the date and time they were created.
 * The script also checks the available disk space before moving the files.
 *
 * FILEPATH: /c:/Sync/Code/Wordpress/unc_media_store/tools/gallery_cron.php
 *
 * @global string $source The source directory path.
 * @global string $target The target directory path.
 * @global bool $debug A flag to enable/disable debug information.
 * @global string $timezone The timezone to be used for date/time operations.
 * @global string $video_date_format The format of the date/time string to be used in the filename.
 * @global string $video_date_sample_string A sample date/time string to be used in the filename.
 * @global bool $log A flag to enable/disable logging.
 * @global resource $logfile The file resource for the log file.
 * @global array $valid_file_extensions An array of valid file extensions.
 */

global $source, $target, $debug, $timezone, $video_date_format, $video_date_sample_string, $log, $logfile, $error;
$source = '/home/uncovery/Videos';
$target = '/home/uncovery/Nextcloud/recording';
$timezone = 'Asia/Hong_Kong';
date_default_timezone_set($timezone);
$debug = false;
$log = true;
$error = false;


// Define the filename format here
// the length of the sample string needs to match the date format below
$video_date_sample_string = '2023-09-20 18-01';
$video_date_format = 'Y-m-d H-i';
$valid_file_extensions = array('mp4');

if ($log) {
    $date = new DateTime();
    $date_str = $date->format('Y-m-d');
    $logfile = fopen("/home/scripts/logs/log_$date_str.txt", "a+") or die("Unable to open logifle!");
    fwrite($logfile, "Logfile start\n");
}

debug_info( '============== PROCESS START ============');

check_volume_space();
read_files();

debug_info( '============== PROCESS END ============');

if ($error && isset($argv[1])) {
    mail($argv[1], 'OBS File Scan', $error);
}

/**
 * Reads all mp4 files in a directory and performs various operations on them, including:
 * - Checking file date and deleting old files
 * - Checking file size and deleting files over 100 GB
 * - Checking if file is part of a valid volume and skipping if not
 * - Moving the file to a target directory and creating a gallery and audio file for it
 *
 * @return void
 */
function read_files() {
    global $source;

    $di = new RecursiveDirectoryIterator($source);
    foreach (new RecursiveIteratorIterator($di) as $file_path => $file) {
        // exclude invalid file extensions and directories
        if ($file->isDir() || $file->getExtension() <> 'mp4') {
            continue;
        }
        debug_info( "------------- FOUND FILE: $file_path\n");

        $fp = fopen($file_path, "r+");
        if (!flock($fp, LOCK_SH | LOCK_NB)) {
            error_info("ERROR, file $file_path is locked");
            continue;
        }

        // check the file date so that we delete old files

        $check = old_file_cleanup($file_path);
        if (!$check) {
            continue;
        }
        // echo "filesize : " . filesize($file_path) . "\n";
        // 100 GB limit, delete the file
        if (filesize($file_path) > 100000000000) {
            error_info("File is too big, deleting it!");
            // trash_file($file_path);
            continue;
        }
        $check_volume = check_valid_volume($file_path);
        if (!$check_volume) {
            continue;
        }

        // check if we need to move the file
        $target_path = target_path($file_path);
        if (!$target_path) {
            continue;
        }

        if (file_exists($target_path)) {
            debug_info("Target file already exists, skipping");
            continue;
        }

        debug_info("Copying $file_path to $target_path");
        $rename_check = copy($file_path, $target_path);
        if (!$rename_check) {
            error_info("ERROR COPYING FILE $file_path to $target_path!!");
            continue;
        }

        create_gallery($target_path);
        create_audio($target_path);

    }
}

/**
 * Check the available space on the hard drive and alert if it's above 90%.
 *
 * @return void
 */
function check_volume_space() {
    $matches = false;
    $command = "df -h";
    $result = shell_exec($command);
    $re = '/  (?<space>\d\d)% \/home/m';
    preg_match_all($re, $result, $matches, PREG_SET_ORDER, 0);
    $check = intval($matches[0]['space']);

    if ($check > 80) {
        error_info("ERROR, SPACE USAGE ABOVE 80%");
    }
    debug_info("$check% space free on device");
}

/**
 * let's delete old files, but not the audio
 *
 * @param type $file_path
 * @return bool
 */
function old_file_cleanup(string $file_path) {
    $filename = basename($file_path);
    $start_date = substr($filename, 0, 10);
    $age = calculate_date_age($start_date);
    // echo var_export($age, true);
    if ($age->m >= 1) {
        error_info("WARNING File $file_path is old, deleting it!");
        trash_file($file_path);
        return false;
    }
    return true;
}

/**
 * Determines the target path for a given file path.
 *
 * @param string $file_path The path of the file.
 *
 * @return string|false The target path if successful, false otherwise.
 */
function target_path(string $file_path) {
    global $video_date_sample_string, $target;

    $duration = get_video_length($file_path);

    if (!$duration) {
        return false;
    }

    $filename = basename($file_path);
    $start_date = substr($filename, 0, 10);
    $start_time = substr($filename, 0, strlen($video_date_sample_string));
    debug_info("Start time: $start_time");
    $end_time = calculate_end_time($start_time, $duration);
    debug_info("End time: $end_time");
    $target_filename = str_replace(" ", "_", $start_time) . "_" . $end_time . ".mp4";
    debug_info("target filename: $target_filename");
    $target_folder = $target . "/" . date_folder($start_date);

    if (!file_exists($target_folder)) {
        mkdir($target_folder, 0777, true);
    }

    $target_path = "$target_folder/$target_filename";
    return $target_path;
}


/**
 * Calculates the age of a given date.
 *
 * @global string $timezone The timezone to use for the calculation.
 * @param string $file_date The date to calculate the age for.
 * @return DateInterval The difference between the current date and the given date.
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
 * @param string $file_path the path of the video file
 * @return bool|array returns an array with the video length in hours and minutes or false if an error occurs
 */
function get_video_length(string $file_path)  {
    $command = 'ffmpeg -i "' . $file_path . '" 2>&1 | grep "Duration"';
    $return = shell_exec($command);

    if (is_null($return)) {
        error_info("ERROR: Could not determine video length of $file_path !");
        return false;
    }

    //  Duration: 01:09:22.11, start: 0.000000, bitrate: 5648 kb/s
    $hours = intval(substr($return, 12, 2));
    $minutes = intval(substr($return, 15,2));

    $final = array(
        'hours' => $hours,
        'minutes' => $minutes,
    );

    if ($hours > 3) {
        error_info("ERROR! Video lenght of $file_path is above 3 hours!");
        return false;
    }

    debug_info("Video length result: $hours:$minutes");

    return $final;
}

/**
 * Calculates the volume of a video file using FFmpeg.
 *
 * @param string $file_path The path to the video file.
 *
 * @return float|false The volume of the video in decibels, or false if an error occurred.
 */
function get_video_volume(string $file_path) {
    $command = "ffmpeg -t 10 -i \"$file_path\" -af \"volumedetect\" -f null /dev/nullc 2>&1 | grep max_volume";
    $return = shell_exec($command);

    $pattern = "/max_volume: (.*) dB/m";
    $matches = array();
    preg_match_all($pattern, $return, $matches, PREG_SET_ORDER, 0);

    if (!isset($matches[0][1])) {
        error_info("ERROR checking volume of $file_path!");
        return false;
    }
    debug_info("Video volume is ". floatval($matches[0][1]));

    return floatval($matches[0][1]);
}


/**
 * Checks if the volume of a video file is valid.
 *
 * @param string $file_path The path of the video file to check.
 * @return bool Returns true if the volume is valid, false otherwise.
 */
function check_valid_volume(string $file_path) {
    $target = -90;
    $volume = get_video_volume($file_path);

    if ($volume < $target) {
        error_info("INVALID VOLUME ($volume) FOR FILE $file_path");

        //trash_file($file_path);
        //trash_file($file_path . ".jpg");
        //trash_file($file_path . ".m4a");
        return false;
        // return false;
    } else {
        return true;
    }
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
        debug_info("creating gallery at $gallery_path");

        $command = "vcs '$video_path' -U0 -n 4 -c 2 -H 200 -o $gallery_path";
        shell_exec($command);

        // delete empty galleries and return
        if (filesize($gallery_path) == 0) {
            trash_file($gallery_path);
            error_info("ERROR CREATING GALLERY: $gallery_path filesize is null");
        }
    }
}

/**
 * Creates an audio file from a given video file path using ffmpeg.
 *
 * @param string $video_path The path of the video file.
 * @return void
 */
function create_audio(string $video_path) {
    $audio_path = "$video_path.m4a";

    if (!file_exists($audio_path)) {
        debug_info("creating audio at $audio_path");

        $command = "ffmpeg -i $video_path -vn -acodec copy $video_path.m4a";
        shell_exec($command);

        // delete empty galleries and return
        if (filesize($audio_path) == 0) {
            trash_file($audio_path);
            error_info("ERROR CREATING AUDIO FILE: $audio_path filesize is null");
        }
    } else {
        debug_info("Audio exists...");
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
    global $debug, $log, $logfile;

    if ($debug) {
        echo "DBG: $text\n";
    }
    if ($log) {
        fwrite($logfile, microtime2string() . " " . $text. "\n");
    }
}

/**
 * support function to output debug info to the command line
 * @global bool $debug
 * @param string $text
 */
function error_info(string $text) {
    global $error;

    $error .= "\n$text";
}

/**
 * Converts microtime to a formatted string with timezone and milliseconds.
 *
 * @return string The formatted time string.
 */
function microtime2string() {
    global $timezone;
    $microtime = microtime(true);

    $date_obj = \DateTime::createFromFormat('0.u00 U', microtime());
    $date_obj->setTimezone(new DateTimeZone($timezone));
    $time_str = $date_obj->format('Y-m-d H:i:s u') . substr((string)$microtime, 1, 8) . "ms";
    return $time_str;
}

/**
 * Moves a file to the trash using the gio command.
 *
 * @param string $filepath The path of the file to be trashed.
 * @return void
 */
function trash_file($filepath) {
    echo "Deleting file $filepath\n";
    exec('gio trash "' . $filepath . '"');
}
