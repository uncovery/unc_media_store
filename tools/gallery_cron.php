<?php

/**
 * This script is used to move video files from a source directory to a target directory.
 * The script reads all the video files from the source directory and moves them to the target directory.
 * The video files are renamed based on the date and time they were created.
 * The script also checks the available disk space before moving the files.
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
$root_folder = '/mnt/d';
$source = '/mnt/c/Recordings';
$target = '/mnt/c/NextCloud/recording';
$logs = __DIR__ . '/logs';
$timezone = 'Asia/Hong_Kong';
date_default_timezone_set($timezone);
$debug = true;
$log = false;
$error = false;
$error_report = array();
$error_files_location = '/mnt/c/Recordings Bad Files';

// Define the filename format here
// the length of the sample string needs to match the date format below
$video_date_sample_string = '2023-09-20 18-01';
$video_date_format = 'Y-m-d H-i';
$valid_file_extensions = array('mp4');

if ($log) {
    $date = new DateTime();
    $date_str = $date->format('Y-m-d_H-i');
    $logfile = fopen($logs . "/log_$date_str.txt", "a+") or die("Unable to open logifle!");
    fwrite($logfile, "Logfile start\n");
}

debug_info( '============== PROCESS START ============');

check_volume_space();
read_files();

debug_info( '============== PROCESS END ============');

email_report();

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
            debug_info("Invalid file, skipping: $file_path");
            continue;
        }
        debug_info( "\n------------- FOUND FILE: $file_path");

		// check if we can access the file or if it's still written to (recording right now)
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
            quarantine_files($file_path, "File is too big!");
            continue;
        }
		// check if the file has audio
        $check_volume = check_valid_volume($file_path);
        if (!$check_volume) {
            quarantine_files($file_path, "File volume is invalid!");
            continue;
        }
		// retrieve the video duration
        $duration = get_video_length($file_path);
        if (!$duration) {
            quarantine_files($file_path, "File length is invalid!");
            continue;
        }

        // check if we need to move the file
        $target_path = target_path($file_path, $duration);
        if (!$target_path) {
            continue;
        }

        if (!file_exists($target_path)) {
            debug_info("Copying $file_path to $target_path");
            $rename_check = copy($file_path, $target_path);
            if (!$rename_check) {
                error_info("ERROR COPYING FILE $file_path to $target_path!!");
                continue;
            }
        }

        $gallery_result = create_gallery($file_path, $target_path);
        if (!$gallery_result) {
            error_info("There was an error greating the gallery.");
            quarantine_files($file_path, "Error creating the gallery!");
            continue;
        }        
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
    $re = '/  (?<space>[\d]*)% \/mnt\/c/m';
    preg_match_all($re, $result, $matches, PREG_SET_ORDER, 0);
    $check = intval($matches[0]['space']);

    if ($check > 80) {
        error_info("ERROR, SPACE USAGE ABOVE 80%");
    }
    debug_info("$check% space used on device /mnt/d");
}

/**
 * let's delete old files, video and screenshot
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
        debug_info("WARNING File $file_path is old, deleting it!");
        // deletes the video file
        trash_file($file_path);
        // now delete the screenshot gallery
        $image_path = str_replace('.mp4', '.jpg', $file_path);
        trash_file($image_path);
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
function target_path(string $file_path, $duration) {
    global $video_date_sample_string, $target;

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
        debug_info("Creating directory $target_folder");
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
    debug_info("checking video length.. ");
    $command = 'ffmpeg -hide_banner -i "' . $file_path . '" 2>&1 | grep "Duration"';
    $return = shell_exec($command);

    if (is_null($return)) {
        error_info("ERROR: Could not determine video length of $file_path!");
        return false;
    }

	debug_info("video length string is: $return");
	// return string looks like this (some spaces padded in front):
    // Duration: 01:09:22.11, start: 0.000000, bitrate: 5648 kb/s
	// need to check if this looks different if the video is below one minute?
    $hours = intval(substr($return, 12, 2));
    $minutes = intval(substr($return, 15,2));

    $final = array(
        'hours' => $hours,
        'minutes' => $minutes,
    );

    if ($hours > 3) {
        error_info("ERROR! Video length of $file_path is above 3 hours ($hours long)!");
        return false;
    }

    if ($hours == 0 && $minutes < 5) {
        error_info("ERROR! Video length of $file_path is below 5 minutes! ($hours:$minutes minutes long)!");
        return false;
    }

    debug_info("Video length result: $hours hours and $minutes minutes");

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
    $command = "ffmpeg -hide_banner -t 10 -i \"$file_path\" -af \"volumedetect\" -f null /dev/nullc 2>&1 | grep max_volume";
    $return = shell_exec($command);

    $pattern = "/max_volume: (.*) dB/m";
    $matches = array();
    preg_match_all($pattern, $return, $matches, PREG_SET_ORDER, 0);

    if (!isset($matches[0][1])) {
        error_info("ERROR checking volume of $file_path!");
        return false;
    }
    $volume = floatval($matches[0][1]);

    debug_info("Video volume is $volume");

    return $volume;
}


/**
 * Checks if the volume of a video file is valid.
 *
 * @param string $file_path The path of the video file to check.
 * @return bool Returns true if the volume is valid, false otherwise.
 */
function check_valid_volume(string $file_path) {
    $target = -40;
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
function create_gallery(string $source_path, string $target_path) {
    $target_gallery_path = "$target_path.jpg";

    if (!file_exists($target_gallery_path)) {
        debug_info("creating gallery at $target_gallery_path");

        $command = "vcs '$target_path' -U0 -n 4 -c 2 -H 200 -o $target_gallery_path";
        $result = shell_exec($command);
        debug_info("Gallery script result: $result");

        // delete empty galleries and return
        if (filesize($target_gallery_path) == 0) {
            trash_file($target_gallery_path);
            error_info("ERROR CREATING GALLERY: $target_gallery_path filesize is null");
            return false;
        } else {
            return true;
        }
    } else {
        debug_info("Gallery exists already.");
        return true;
    }
}

/**
 * Creates an audio file from a given video file path using ffmpeg.
 *
 * @param string $video_path The path of the video file.
 * @return void
 */
//function create_audio(string $video_path) {
//    $m4a_path = "$video_path.m4a";
//    $mp3_path = "$video_path.mp3";
//
//    if (!file_exists($m4a_path) && !file_exists($mp3_path)) {
//        debug_info("extracting audio at $m4a_path");
//        $command = "ffmpeg -hide_banner -i $video_path -vn -acodec copy $video_path.m4a";
//        shell_exec($command);
//    }
//    if (file_exists($m4a_path) && !file_exists($mp3_path)) {
//        debug_info("converting m4a to mp3 audio at $mp3_path");
//        $command = "ffmpeg -hide_banner -i $video_path.m4a -codec:a libmp3lame -qscale:a 320k $video_path.mp3";
//        shell_exec($command);
//        unlink($m4a_path);
//    }
//
//    // delete empty galleries and return
//    if (filesize($mp3_path) == 0) {
//        trash_file($mp3_path);
//        error_info("ERROR CREATING AUDIO FILE: $mp3_path filesize is null");
//    }
//}


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
    global $debug, $log, $logfile, $error;

    $time = microtime2string();
    
    if ($debug) {
        echo "DBG: $text\n";
        $error .= "\n$time : $text";
    }
    if ($log) {
        fwrite($logfile, "$time $text\n");
    }
}

/**
 * support function to output debug info to the command line
 * @global bool $debug
 * @param string $text
 */
function error_info(string $text) {
    global $error_report;
    $time = microtime2string();

    $error_report[$time] = $text;
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

function quarantine_files($filepath, $reason = '') {
    global $error_files_location, $error_report;    
    
    $filename = basename($filepath);
    
    if (!isFileOlderThan3Hours($filename)) {
        $error_report = array();
        //error_info("Not quarantining file, it's too new.");
        return;
    }

    $target = $error_files_location . "/" . $filename;
    file_put_contents($target . "reason.txt", $reason);
    rename($filepath, $target);
    error_info("Quarantined file! $filepath for reason $reason");
}

/**
 * Sends an error report via email in case there are errors in the process.
 * @global array $error_report
 */
function email_report() {
    global $error_report;
    
    if (count($error_report) > 0) {
        $content = var_export($error_report, true);
        mail("oliver@thewanch.hk", "Media Store error report", $content);
        echo "Sent email with error report\n";
    } else {
        echo "No errors, no email report sent.\n";
    }
}

function isFileOlderThan3Hours($filename) {
    global $timezone;
    // Extract date and time from filename
    $matches = array();
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}-\d{2})\.mp4$/', $filename, $matches)) {
        throw new InvalidArgumentException("Invalid filename format. Expected: YYYY-MM-DD HH-MM.mp4");
    }
    
    $datePart = $matches[1];
    $timePart = str_replace('-', ':', $matches[2]);
    
    // Create DateTime object from filename in the given timezone
    $fileTimezone = new DateTimeZone($timezone);
    $fileDateTime = DateTime::createFromFormat('Y-m-d H:i', $datePart . ' ' . $timePart, $fileTimezone);
    
    if (!$fileDateTime) {
        throw new InvalidArgumentException("Could not parse date/time from filename");
    }
    
    // Get current time in the same timezone
    $currentDateTime = new DateTime('now', $fileTimezone);
    
    // Calculate the difference
    $difference = $currentDateTime->getTimestamp() - $fileDateTime->getTimestamp();
    
    // Check if more than 3 hours (3 * 60 * 60 = 10800 seconds)
    return $difference > 10800;
}
