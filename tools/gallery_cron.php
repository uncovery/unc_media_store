<?php

global $root;
$root = '/home/uncovery/Nextcloud/recording';
$timezone = 'Asia/Hong_Kong';
date_default_timezone_set($timezone);

read_files();

function read_files() {
    global $root, $timezone;

    $di = new RecursiveDirectoryIterator($root);
    foreach (new RecursiveIteratorIterator($di) as $file_path => $file) {
        // exclude invalid
        if ($file->isDir() || $file->getExtension() <> 'mp4') {
            continue;
        }

        $filename = basename($file_path);
        $start_date = substr($filename, 0, 10);
        // OBS file names start with the date
        $folder = dirname($file_path);

        // check the file date so that we delete old files
        $age = delete_old_files($start_date);
        if ($age->m > 2) {
            unlink($file_path);
            continue;
        }

        // echo "filesize : " . filesize($file_path) . "\n";
        // 100 GB limit, delete the file
        if (filesize($file_path) > 100000000000) {
            unlink($file_path);
            continue;
        }

        // check if we need to move the file
        if ($folder == $root) {
            $duration = get_video_length($file_path);
            $start_time = substr($filename, 0, 16);
            $end_time = calculate_end_time($start_time, $duration);

            $target_filename = str_replace(" ", "_", $start_time) . "_" . $end_time . ".mp4";

            $target_folder = $root . "/" . date_folder($start_date);

            if (!file_exists($target_folder)) {
                mkdir($target_folder, 0777, true);
            }

            rename($file_path, $target_folder . "/" . $target_filename);
        }

        // do not operate on files in the root directory
        if ($file->getPath() !== $root) {
            create_gallery($file->getBasename(), $file->getPath());
        }
    }
}

function delete_old_files($file_date) {
    // let's determine the current month
    global $timezone;
    $date_obj_now = new DateTime();
    $date_obj_now->setTimezone(new DateTimeZone($timezone));

    $date_obj_file = new DateTime($file_date);
    $date_obj_file->setTimezone(new DateTimeZone($timezone));
    $interval = $date_obj_now->diff($date_obj_file);
    return $interval;
}

function get_video_length($file_path)  {
    $command = 'ffmpeg -i ' . $file_path . ' 2>&1 | grep "Duration"';
    $return = shell_exec($command);

    //  Duration: 01:09:22.11, start: 0.000000, bitrate: 5648 kb/s
    $hours = substr($return, 12, 2);
    $minutes = substr($return, 15,2);

    $final = array(
        'hours' => intval($hours),
        'minutes' => intval($minutes),
    );

    return $final;
}

function calculate_end_time($start_time, $duration) {
    global $timezone;
    $hours = $duration['hours'];
    $minutes = $duration['minutes'];

    $date = date_create_from_format('Y-m-d_H-i', $start_time, new DateTimeZone($timezone));

    $end_time = date('H-i',strtotime("+$hours hour +$minutes minutes", $date));

    return $end_time;
}

function create_gallery($filename, $folder) {
    $filename_fixed = str_replace(" ", "_", $filename);
    $gallery_path = "$folder/$filename_fixed" . ".jpg";

    if (!file_exists($gallery_path)) {
        echo "creating gallery for $gallery_path: ";
        // https://www.baeldung.com/linux/generate-video-thumbnails-gallery
        // https://p.outlyer.net/vcs#links
        $command = "vcs '$folder/$filename' -U0 -n 4 -c 2 -H 200 -o $gallery_path";
        shell_exec($command);

        // delete empty galleries and return
        if (filesize($gallery_path) == 0) {
            unlink($gallery_path);
            echo "NOT OK, filesoze is null\n";
        } else {
            echo "OK!\n";
        }
    }
}

function curl_fix_path($path) {
    $url_file = str_replace(" ", '%20', trim($path));
    return $url_file;
}

// create a dated folder
function date_folder($start_date) {
    $unix_time = strtotime($start_date);
    if (!$unix_time) {
        return false;
    }
    $folder = date("Y/m", $unix_time);

    return $folder;
}

