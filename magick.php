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
        preg_match_all('/\+*(([a-z]+)(\(([^\)]*)\))?)\+*/', $_GET['cmd'],
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
    render(end(explode('/', $image)), true);
    echo "<br/>";
    // serve out results
    if ($result == 0) {
        render($cache, true);
    }

    // end timer
    $timeparts = explode(' ',microtime());
    $endtime = $timeparts[1].substr($timeparts[0],1);
    $elapsed = bcsub($endtime,$starttime,6);
    echo "<br/>Script execution time (s): ".$elapsed;
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
        if (!preg_match('/^[a-z]+$/',$cmd[2])) {
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
            // crops the image to the requested size
            // chooses the crop with the most edges, or "interestingness"
            if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                die('ERROR: Invalid parameter.');
            }
            list($width, $height) = explode('x', $cmd[4]);
            $result = opticrop($image, $width, $height, $cache);
            break;

        case 'part':
            // crops the image to the requested size
            if (!preg_match('/^[0-9]+x[0-9]+$/',$cmd[4])) {
                die('ERROR: Invalid parameter.');
            }

            list($width, $height) = explode('x', $cmd[4]);

            // get size of the original
            $imginfo = getimagesize($image);
            $orig_w = $imginfo[0];
            $orig_h = $imginfo[1];

            // resize image to cmd either the new width
            // or the new height

            // if original width / original height is greater
            // than new width / new height
            if ($orig_w/$orig_h > $width/$height) {
                // then resize to the new height...
                $commands .= ' -resize "x'.$height.'"';

                // ... and get the middle part of the new image
                // what is the resized width?
                $resized_w = ($height/$orig_h) * $orig_w;

                // crop
                $commands .= ' -crop "'.$width.'x'.$height.
                    '+'.round(($resized_w - $width)/2).'+0"';
            } else {
                // or else resize to the new width
                $commands .= ' -resize "'.$width.'"';

                // ... and get the middle part of the new image
                // what is the resized height?
                $resized_h = ($width/$orig_w) * $orig_h;

                // crop
                $commands .= ' -crop "'.$width.'x'.$height.
                    '+0+'.round(($resized_h - $height)/2).'"';
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

/* 
 * edge-maximizing crop
 *
 * generates a thumbnail of desired dimensions from a source image by cropping 
 * the most "interesting" or edge-filled part of the image.
 *
 * $w, $h - target dimensions of thumbnail
 * $image - system path to source image
 * $out - path/name of output image
 */

function opticrop($image, $w, $h, $out) {
    // get size of the original
    $imginfo = getimagesize($image);
    $w0 = $imginfo[0];
    $h0 = $imginfo[1];
    if ($w > $w0 || $h > $h0)
        die("Target dimensions must be smaller or equal to source dimensions.");

    // parameters for the edge-maximizing crop algorithm
    $r = 2;         // radius of edge filter
    $nk = 3;        // scale count: number of crop sizes to try
    $nx = 5;        // number of x-translations to try
    $ny = 5;        // number of y-translations to try
    $gamma = 0.8;   // edge-sum normalization parameter -- see documentation
    $ar = $w/$h;    // target aspect ratio (AR)

    dprint('$img: '.$image);
    dprint('$w x $h: '.$w.'x'.$h);
    dprint('$w0 x $h0: '.$w0.'x'.$h0);
    $img = new Imagick($image);

    // crop source img to target AR
    if ($w0/$h0 > $ar) {
        // source AR wider than target
        // crop width to target AR
        $wcrop0 = round($ar*$h0);
        $hcrop0 = $h0;
    } 
    else {
        // crop height to target AR
        $wcrop0 = $w0;
        $hcrop0 = round($w0/$ar);
    }

    // crop parameters for all scales and translations
    $params = array();

    // crop at different scales
    $hgap = $hcrop0 - $h;
    $hinc = $hgap / ($nk - 1);
    for ($k = 0; $k < $nk; $k++) {
        $hcrop = round($hcrop0 - $k*$hinc);
        $wcrop = $hcrop*$ar;
        // crop at different locations 
        // space translations out evenly across source image
        $xgap = $w0 - $wcrop;
        $xinc = $xgap / $nx;
        $ygap = $h0 - $hcrop;
        $yinc = $ygap / $ny;

        // crop is only slightly smaller than source
        // proceed by 1px increments
        $nxtemp = $nx;
        $nytemp = $ny;
        if ($xgap < $nx - 1) {
            $nxtemp = $xgap + 1;
            $xinc = 1;
        }
        if ($ygap < $ny - 1) {
            $nytemp = $ygap + 1;
            $yinc = 1;
        }
        // generate parameters for trial crops
        for ($i=0; $i<$nxtemp; $i++) {
            $xcrop = round($i * $xinc);
            for ($j=0; $j<$nytemp; $j++) {
                $ycrop = round($j * $yinc);
                $params[] = array('wcrop'=>$wcrop, 'hcrop'=>$hcrop, 'xcrop'=>$xcrop, 'ycrop'=>$ycrop);
            }
        }
    }
    dprint("original:<br/><img src=\"".$_GET['src']."\"/>");
    // crop each trial image, save the one with most edges
    $i = 0;
    $maximg = clone $img;
    $maxbetanorm = 0;
    $maxparam = "";
    foreach ($params as $param) {
        $i++;
        $currfile = CACHE_PATH."image$i.jpg";
        $beta = 0;
        $currimg = clone $img;
        $currimg->cropImage($param['wcrop'],$param['hcrop'],$param['xcrop'],$param['ycrop']);
        $currimgcp = clone $currimg;        // save for output
        $currimg->edgeImage($r);
        $currimg->modulateImage(100,0,100); // grayscale
        $currimg->writeImage($currfile);    // save for debug
        $pi = $currimg->getPixelIterator();
        foreach ($pi as $row=>$pixels) {
            foreach($pixels as $column=>$pixel) {
                $beta += $pixel->getColorValue(imagick::COLOR_RED);
            }
        }
        $area = $param['wcrop'] * $param['hcrop'];
        $betanorm = $beta / pow($area, $gamma);
        dprint("$currfile (beta=$beta, normalized=$betanorm):<br/>\n<img src=\"$currfile\"/>");
        dprint($param, true);
        // best image found, save it
        if ($betanorm > $maxbetanorm) {
            $maxbetanorm = $betanorm;
            $maxfile = $currfile;
            $maximg->destroy();
            $maximg = $currimgcp;
        }
        else {
            $currimg->destroy();
            $currimgcp->destroy();
        }
    }
    $img->destroy();
    $img = $maximg;
    $img->scaleImage($w,$h);
    $img->writeImage($out);
    dprint("maxfile: $maxfile");

    return 0;
}

?>
