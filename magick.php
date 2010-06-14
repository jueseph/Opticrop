<?php
define('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT']);
define('DEBUG', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// update to master

function main() {}

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
    global $cache_path;
    // get size of the original
    $imginfo = getimagesize($image);
    $w0 = $imginfo[0];
    $h0 = $imginfo[1];
    if ($w > $w0 || $h > $h0)
        die("Target dimensions must be smaller or equal to source dimensions.");

    dprint('$img: '.$image);
    dprint('$w x $h: '.$w.'x'.$h);
    dprint('$w0 x $h0: '.$w0.'x'.$h0);
    $img = new Imagick($image);

    // parameters for the edge-maximizing crop algorithm
    $r = 2;         // radius of edge filter
    $nk = 3;        // scale count: number of crop sizes to try
    $nx = 5;        // number of x-translations to try
    $ny = 5;        // number of y-translations to try
    $gamma = 0.8;   // edge-sum normalization parameter -- see documentation
    $ar = $w/$h;    // target aspect ratio (AR)

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

    // crop at a few different scales
    $hgap = $hcrop0 - $h;
    $hinc = $hgap / ($nk - 1);
    for ($k = 0; $k < $nk; $k++) {
        $hcrop = round($hcrop0 - $k*$hinc);
        $wcrop = $hcrop*$ar;
        // crop at a few different locations 
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
        $currfile = $cache_path."image$i.jpg";
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
    $outsub = str_replace("^%","%5E%25",$out);
    dprint("maxfile: $maxfile");
    dprint("output:<br/><img src=\"/process/$outsub\"/>");
    return;
}

/* 
 * serves the image stored at the system path $cache
 */
function render($cache) {
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

//  location of cached images (with trailing /)
$cache_path = 'imagecache/';

//  location of imagemagick's convert utility
//$convert_path = '/usr/local/bin/convert';
$convert_path = 'convert';

// Images must be local files, so for convenience we strip the domain if it's there
$image			= preg_replace('/^(s?f|ht)tps?:\/\/[^\/]+/i', '', (string) $_GET['src']);

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

// extract the commands from the query string
// eg.: ?resize(....)+flip+blur(...)
if (isset($_GET['cmd'])) {
    preg_match_all('/\+*(([a-z]+)(\(([^\)]*)\))?)\+*/',
                $_GET['cmd'],
                $matches, PREG_SET_ORDER);
}
// no commands specified
else {
    $matches = Array();
}

// concatenate commands for use in cache file name
$cache = ltrim($image, '/');
foreach ($matches as $match) {
    $cache .= '%'.$match[2].'-'.$match[4];
}
//$cache = str_replace('/','_',$cache);
$path = explode('/',$cache);
$cache_dirs = $cache_path.implode('/', array_slice($path, 2, -1)); // remove filename
$cache_file = end($path);
$cache = $cache_dirs.'/'.$cache_file;
$cache = escapeshellcmd($cache);

//echo $cache_file.'<br/>';
//echo $cache_dirs.'<br/>';
//echo $cache.'<br/>';

// create cache directory path if doesn't exist
if (!is_dir($cache_dirs)) {
    mkdir($cache_dirs, 0777, true);
}

// prepare cache
if (isset($_GET["cache"])) {
    dprint('cache: '.$_GET["cache"]);
    switch($_GET["cache"]) {
        case 'no': 
            $cache = $cache_path."temp.jpg";
            // no break;
        case 'refresh':
            if (file_exists($cache)) {
                unlink($cache);
            }
            break;
    }
}

if (!file_exists($cache)) {
    // there is no cached image yet, so we'll need to create it first

    // convert query string to an imagemagick command string
    $commands = '';
    
    foreach ($matches as $match) {
        // $match[2] is the command name
        // $match[4] the parameter
        
        // check input
        if (!preg_match('/^[a-z]+$/',$match[2])) {
            die('ERROR: Invalid command.');
        }
        if (!preg_match('/^[a-z0-9\/{}+-<>!@%]+$/',$match[4])) {
            die('ERROR: Invalid parameter.');
        }
    
        // replace } with >, { with <
        // > and < could give problems when using html
        $match[4] = str_replace('}','>',$match[4]);
        $match[4] = str_replace('{','<',$match[4]);

        // check for special, scripted commands
        $noconvert = false;
        switch ($match[2]) {
            case 'colorizehex':
                // imagemagick's colorize, but with hex-rgb colors
                // convert to decimal rgb
                $r = round((255 - hexdec(substr($match[4], 0, 2))) / 2.55);
                $g = round((255 - hexdec(substr($match[4], 2, 2))) / 2.55);
                $b = round((255 - hexdec(substr($match[4], 4, 2))) / 2.55);

                // add command to list
                $commands .= ' -colorize "'."$r/$g/$b".'"';
                break;

            case 'opticrop':
                // crops the image to the requested size
                // chooses the crop with the most edges, or "interestingness"
                if (!preg_match('/^[0-9]+x[0-9]+$/',$match[4])) {
                    die('ERROR: Invalid parameter.');
                }

                list($width, $height) = explode('x', $match[4]);

                opticrop($image, $width, $height, $cache);
                $noconvert = true;
                break;

            case 'part':
                // crops the image to the requested size
                if (!preg_match('/^[0-9]+x[0-9]+$/',$match[4])) {
                    die('ERROR: Invalid parameter.');
                }

                list($width, $height) = explode('x', $match[4]);

                // get size of the original
                $imginfo = getimagesize($image);
                $orig_w = $imginfo[0];
                $orig_h = $imginfo[1];

                // resize image to match either the new width
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
                if (!preg_match('/^[a-z]+$/',$match[4])) {
                    die('ERROR: Invalid parameter.');
                }
                $new_type = $match[4];
                break;
            default:
                // nothing special, just add the command
                if ($match[4]=='') {
                    // no parameter given, eg: flip
                    $commands .= ' -'.$match[2].'';
                } else {
                    $commands .= ' -'.$match[2].' "'.$match[4].'"';
                }
        }
    }

    // create the convert-command
    $convert = $convert_path.' '.$commands.' "'.$image.'" ';
    if (isset($new_type)) {
        // send file type-command to imagemagick
        $convert .= $new_type.':';
    }
    $convert .= '"'.$cache.'"';

    //echo $convert.'<br/>';
    //echo getcwd();
    //$output = Array();
    // execute imagemagick's convert, save output as $cache
    if (!$noconvert) {
        exec($convert);
    }
    dprint($cache);
    chmod($cache, 0777);
}

// there should be a file named $cache now
if (!file_exists($cache)) {
        die('ERROR: Image conversion failed.');
}

if (!$noconvert)
    render($cache);

?>
