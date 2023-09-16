<?php

global $root;
$root = '/home/uncovery/Nextcloud/recording';
$timezone = 'Asia/Hong_Kong';

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
        $age = file_age($start_date);
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
            $edit_date = get_file_modify_time($file_path);
            $date_only = substr($filename, 0, 16);
            $target_filename = str_replace(" ", "_", $date_only) . "_" . $edit_date . ".mp4";

            $target_folder = $root . "/" . date_folder($start_date);

            if (file_exists($target_folder)) {
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

function file_age($file_date) {
    // let's determine the current month
    global $timezone;
    $date_obj_now = new DateTime();
    $date_obj_now->setTimezone(new DateTimeZone($timezone));

    $date_obj_file = new DateTime($file_date);
    $date_obj_file->setTimezone(new DateTimeZone($timezone));
    $interval = $date_obj_now->diff($date_obj_file);
    return $interval;
}

function get_file_modify_time($file_path) {
    global $timezone;
    $date_obj = new DateTime();
    $date_obj->setTimestamp(filemtime($file_path));
    $date_obj->setTimezone(new DateTimeZone($timezone));
    $edit_date = date_format($date_obj, "H-i");
    return $edit_date;
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
    } else {
        echo "gallery already exists at $gallery_path\n";
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
