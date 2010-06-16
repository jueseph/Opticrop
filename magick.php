<?php
define('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT']);
// location of cached images (with trailing /)
define('CACHE_PATH', 'imagecache/');
// location of imagemagick's convert utility
define('CONVERT_PATH', 'convert');//'/usr/local/bin/convert';
define('LOG_PATH', 'log.magick.txt');
// toggle output of dprint() function
define('DEBUG', 0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// cropping algorithms
include_once('functions.php');

// execute the script
main();

function main() {
    // start timer
    $timeparts = explode(' ',microtime());
    $starttime = $timeparts[1].substr($timeparts[0],1);
    
    // open log file
    $lf = fopen(LOG_PATH, 'a');

    // prep image path
    $image = get_image($_GET['src']);

    // extract the commands from the query string
    // eg.: ?resize(....)+flip+blur(...)
    if (isset($_GET['cmd'])) {
        preg_match_all('/\+*(([a-z\-]+[0-9]*)(\(([^\)]*)\))?)\+*/', $_GET['cmd'],
            $cmds, PREG_SET_ORDER);
    }
    // no commands specified
    else {
        $cmds = Array();
    }

    // prep cache path
    $cache = get_cache($image, $cmds);

    // compute image if needed
    $result = 0;
    if (!file_exists($cache)) {
        $result = dispatch($image, $cache, $cmds);
    }

    // show source image for comparison
    if (DEBUG == 1) {
        render(end(explode('/', $image)), true);
        echo "<br/>";
    }
    // serve out results
    if ($result == 0) {
        render($cache, (DEBUG==1)?true:false);
    }

    // end timer
    $timeparts = explode(' ',microtime());
    $endtime = $timeparts[1].substr($timeparts[0],1);
    $elapsed = bcsub($endtime,$starttime,6);
    dprint("<br/>Script execution time (s): ".$elapsed);
    $logstring = date(DATE_RFC822)."\n".
        $_SERVER["QUERY_STRING"]."\n$elapsed s\n\n";
    fwrite($lf, $logstring);
    fclose($lf);
}

function get_image($url) {
    // Images must be local files, so for convenience we strip the domain if it's there
    $image = preg_replace('/^(s?f|ht)tps?:\/\/[^\/]+/i', '', (string) $url);

    // For security, directories cannot contain ':', images cannot contain '..' or '<', and
    // images must start with '/'
    if ($image{0} != '/' || strpos(dirname($image), ':') || preg_match('/(\.\.|<|>)/', $image))
    {
        header('HTTP/1.1 400 Bad Request');
        echo 'Error: malformed image path. Image paths must begin with \'/\'';
        exit();
    }

    // check if an image location is given
    if (!$image)
    {
        header('HTTP/1.1 400 Bad Request');
        echo 'Error: no image was specified';
        exit();
    }

    // Strip the possible trailing slash off the document root
    $docRoot	= rtrim(DOCUMENT_ROOT,'/');
    $image = $docRoot . $image;

    // check if the file exists
    if (!file_exists($image))
    {
        header('HTTP/1.1 404 Not Found');
        echo 'Error: image does not exist: ' . $image;
        exit();
    }
    return $image;
}

function get_cache($image, $cmds) {
    // concatenate commands for use in cache file name
    $cache = ltrim($image, '/');
    foreach ($cmds as $cmd) {
        $cache .= '%'.$cmd[2].'-'.$cmd[4];
    }
    //$cache = str_replace('/','_',$cache);
    $path = explode('/',$cache);
    // remove filename
    $cache_dirs = CACHE_PATH.implode('/', array_slice($path, 2, -1)); 
    $cache_file = end($path);
    $cache = $cache_dirs.'/'.$cache_file;
    $cache = escapeshellcmd($cache);

    // create cache directory path if doesn't exist
    if (!is_dir($cache_dirs)) {
        mkdir($cache_dirs, 0777, true);
    }

    // prepare cache
    if (isset($_GET["cache"])) {
        dprint('cache: '.$_GET["cache"]);
        switch($_GET["cache"]) {
        case 'no': 
            $cache = CACHE_PATH."temp.jpg";
            // no break;
        case 'refresh': // or 'no':
            if (file_exists($cache)) {
                unlink($cache);
            }
            break;
        }
    }
    return $cache;
}

function dispatch($image, $cache, $cmds) {
    // there is no cached image yet, so we'll need to create it first
    // convert query string to an imagemagick command string
    $commands = '';

    foreach ($cmds as $cmd) {
        // $cmd[2] is the command name
        // $cmd[4] the parameter

        // check input
        if (!preg_match('/^[a-z\-]+[0-9]*$/',$cmd[2])) {
            die('ERROR: Invalid command.');
        }
        if (!preg_match('/^[a-z0-9\/{}+-<>!@%]+$/',$cmd[4])) {
            die('ERROR: Invalid parameter.');
        }

        // replace } with >, { with <
        // > and < could give problems when using html
        $cmd[4] = str_replace('}','>',$cmd[4]);
        $cmd[4] = str_replace('{','<',$cmd[4]);

        // check for special, scripted commands
        switch ($cmd[2]) {
        case 'colorizehex':
            // imagemagick's colorize, but with hex-rgb colors
            // convert to decimal rgb
            $r = round((255 - hexdec(substr($cmd[4], 0, 2))) / 2.55);
            $g = round((255 - hexdec(substr($cmd[4], 2, 2))) / 2.55);
            $b = round((255 - hexdec(substr($cmd[4], 4, 2))) / 2.55);

            // add command to list
            $commands .= ' -colorize "'."$r/$g/$b".'"';
            break;

        case 'opticrop':
            if (function_exists('opticrop')) {
                // crops the image to the requested size
                // chooses the crop with the most edges, or "interestingness"
                if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                    die('ERROR: Invalid parameter.');
                }
                list($width, $height) = explode('x', $cmd[4]);
                $result = opticrop($image, $width, $height, $cache);
            }
            else {
                die('Function does not exist. Check includes.');
            }
            break;

         case 'liquid':
            if (function_exists('opticrop')) {
                if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                    die('ERROR: Invalid parameter.');
                }
                list($w, $h) = explode('x', $cmd[4]);
                $im = new Imagick($image);
                $im->liquidRescaleImage( $w, $h, 3, 25 );
                $im->writeImage($cache);
            }
            else {
                die('Function does not exist. Check includes.');
            }
            break;

       case 'opticrop2':
            if (function_exists('opticrop2')) {
                // refinement of opticrop 1
                if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                    die('ERROR: Invalid parameter.');
                }
                list($width, $height) = explode('x', $cmd[4]);
                $result = opticrop2($image, $width, $height, $cache);
            }
            break;

        case 'part':
            if (function_exists('part')) {
                // crops the image to the requested size
                if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                    die('ERROR: Invalid parameter.');
                }
                list($width, $height) = explode('x', $cmd[4]);
                $commands .= part($image, $width, $height);
            }
            break;

        case 'type':
            // convert the image to this file type
            if (!preg_match('/^[a-z]+$/',$cmd[4])) {
                die('ERROR: Invalid parameter.');
            }
            $new_type = $cmd[4];
            break;

        default:
            // nothing special, just add the command
            if ($cmd[4]=='') {
                // no parameter given, eg: flip
                $commands .= ' -'.$cmd[2].'';
            } else {
                $commands .= ' -'.$cmd[2].' "'.$cmd[4].'"';
            }
        }
    }

    // run Imagemagick from command line if needed
    if ($commands) {
        // create the convert-command
        $convert = CONVERT_PATH.' '.$commands.' "'.$image.'" ';
        if (isset($new_type)) {
            // send file type-command to imagemagick
            $convert .= $new_type.':';
        }
        $convert .= '"'.$cache.'"';

        // execute imagemagick's convert, save output as $cache
        $result = exec($convert);
        dprint($cache);
    }

    // there should be a file named $cache now
    if (!file_exists($cache)) {
        die('ERROR: Image conversion failed.');
    }

    // make cache easily writeable so anyone can clear it
    chmod($cache, 0777);

    return $result;
}


/* 
 * serves an image 
 *
 * $cache - path of file to display
 * $as_html - serve the image as an img tag in an html page (use for debugging)
 */
function render($cache, $as_html=false) {
    if ($as_html) {
        $outsub = str_replace("^%","%5E%25",$cache);
        echo "<img src=\"$outsub\"/>";
        return;
    }
    // get image data for use in http-headers
    $imginfo = getimagesize($cache);
    $content_length = filesize($cache);
    $last_modified = gmdate('D, d M Y H:i:s',filemtime($cache)).' GMT';

    // array of getimagesize() mime types
    $getimagesize_mime = array(1=>'image/gif',2=>'image/jpeg',
          3=>'image/png',4=>'application/x-shockwave-flash',
          5=>'image/psd',6=>'image/bmp',7=>'image/tiff',
          8=>'image/tiff',9=>'image/jpeg',
          13=>'application/x-shockwave-flash',14=>'image/iff');

    // did the browser send an if-modified-since request?
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
       // parse header
       $if_modified_since = 
    preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE']);

        if ($if_modified_since == $last_modified) {
         // the browser's cache is still up to date
         header("HTTP/1.0 304 Not Modified");
         header("Cache-Control: max-age=86400, must-revalidate");
         exit;
        }
    }

    // send other headers
    header('Cache-Control: max-age=86400, must-revalidate');
    header('Content-Length: '.$content_length);
    header('Last-Modified: '.$last_modified);
    if (isset($getimagesize_mime[$imginfo[2]])) {
       header('Content-Type: '.$getimagesize_mime[$imginfo[2]]);
    } else {
            // send generic header
            header('Content-Type: application/octet-stream');
    }

    // and finally, send the image
    readfile($cache);
}
               
function dprint($str, $print_r=false) {
    if (DEBUG > 0) {
        if ($print_r) {
            print_r($str);
            echo "<br/>\n";
        }
        else {
            echo $str."<br/>\n";
        }
    }
}

?>
