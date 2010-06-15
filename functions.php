<?php

/* 
 * 2nd generation edge-maxing crop
 *
 * $w, $h - target dimensions of thumbnail
 * $image - system path to source image
 * $out - path/name of output image
 */

function opticrop2($image, $w, $h, $out) {
    //mt_srand(6);
    // get size of the original
    $imginfo = getimagesize($image);
    $w0 = $imginfo[0];
    $h0 = $imginfo[1];
    if ($w > $w0 || $h > $h0)
        die("Target dimensions must be smaller or equal to source dimensions.");

    // parameters for the edge-maximizing crop algorithm
    $r = 1;         // radius of edge filter
    $nk = 9;        // scale count: number of crop sizes to try
    $gamma = 0.2;   // edge normalization parameter -- see documentation
    $ar = $w/$h;    // target aspect ratio (AR)
    $ar0 = $w0/$h0;    // target aspect ratio (AR)

    dprint('$img: '.$image);
    dprint('$w x $h: '.$w.'x'.$h);
    dprint('$w0 x $h0: '.$w0.'x'.$h0);
    $img = new Imagick($image);
    $imgcp = clone $img;

    // compute center of edginess
    $img->edgeImage($r);
    $img->modulateImage(100,0,100); // grayscale
    $img->blackThresholdImage("#0f0f0f");
    $img->writeImage($out);
    // use gd for random pixel access
    $im = ImageCreateFromJpeg($out);
    $xcenter = 0;
    $ycenter = 0;
    $sum = 0;
    $n = 100000;
    for ($k=0; $k<$n; $k++) {
        $i = mt_rand(0,$w0-1);
        $j = mt_rand(0,$h0-1);
        $val = imagecolorat($im, $i, $j) & 0xFF;
        $sum += $val;
        $xcenter += ($i+1)*$val;
        $ycenter += ($j+1)*$val;
    }
    $xcenter /= $sum;
    $ycenter /= $sum;
    imagejpeg($im, $out);


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
    $hinc = ($nk == 1) ? 0 : $hgap / ($nk - 1);
    $wgap = $wcrop0 - $w;
    $winc = ($nk == 1) ? 0 : $wgap / ($nk - 1);
    // find window with highest normalized edginess
    $n = 10000;
    $maxbetanorm = 0;
    $maxfile = '';
    $maxparam = array('w'=>0, 'h'=>0, 'x'=>0, 'y'=>0);
    for ($k = 0; $k < $nk; $k++) {
        $hcrop = round($hcrop0 - $k*$hinc);
        $wcrop = round($wcrop0 - $k*$winc);
        $xcrop = $xcenter - $wcrop / 2;
        $ycrop = $ycenter - $hcrop / 2;
        dprint("crop: $wcrop, $hcrop, $xcrop, $ycrop");

        if ($xcrop < 0) $xcrop = 0;
        if ($xcrop+$wcrop > $w0) $xcrop = $w0-$wcrop;
        if ($ycrop < 0) $ycrop = 0;
        if ($ycrop+$hcrop > $h0) $ycrop = $h0-$hcrop;

        // debug
        $currfile = CACHE_PATH."image$k.jpg";
        $currimg = clone $img;
        $c= new ImagickDraw(); 
        $c->setFillColor("red"); 
        $c->circle($xcenter, $ycenter, $xcenter, $ycenter+4); 
        $currimg->drawImage($c); 
        $currimg->cropImage($wcrop, $hcrop, $xcrop, $ycrop);
        $currimg->writeImage($currfile);
        $currimg->destroy();

        $beta = 0;
        for ($c=0; $c<$n; $c++) {
            $i = mt_rand(0,$wcrop-1);
            $j = mt_rand(0,$hcrop-1);
            $beta += imagecolorat($im, $xcrop+$i, $ycrop+$j) & 0xFF;
        }
        $area = $wcrop * $hcrop;
        $betanorm = $beta / ($n*pow($area, $gamma-1));
        dprint("beta: $beta; betan: $betanorm");
        dprint("image$k.jpg:<br/>\n<img src=\"$currfile\"/>");
        // best image found, save it
        if ($betanorm > $maxbetanorm) {
            $maxbetanorm = $betanorm;
            $maxparam['w'] = $wcrop;
            $maxparam['h'] = $hcrop;
            $maxparam['x'] = $xcrop;
            $maxparam['y'] = $ycrop;
            $maxfile = $currfile;
        }
    }
    dprint("best image: $maxfile");

    $img->destroy();
    $img = $imgcp;
    $img->cropImage($maxparam['w'],$maxparam['h'],
                    $maxparam['x'],$maxparam['y']);
    $img->scaleImage($w,$h);
    $img->writeImage($out);

    return 0;
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
    $nk = 1;        // scale count: number of crop sizes to try
    $nx = 3;        // number of x-translations to try
    $ny = 3;        // number of y-translations to try
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
    $hinc = ($nk == 1) ? 0 : $hgap / ($nk - 1);
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
    $imgcopy = clone $img;
    $maxbetanorm = 0;
    $maxparam = "";
    $timeparts = explode(' ',microtime());
    $starttime = $timeparts[1].substr($timeparts[0],1);
    foreach ($params as $param) {
        $i++;
        //$currfile = CACHE_PATH."image$i.jpg";
        $beta = 0;
        $img->edgeImage($r);
        $img->modulateImage(100,0,100); // grayscale
        $pi = $img->getPixelRegionIterator(
            (int)$param['xcrop'],
            (int)$param['ycrop'], 
            (int)$param['wcrop'], 
            (int)$param['hcrop']);
        foreach ($pi as $row=>$pixels) {
            foreach($pixels as $column=>$pixel) {
                $beta += $pixel->getColorValue(imagick::COLOR_RED);
            }
        }
        $area = $param['wcrop'] * $param['hcrop'];
        $betanorm = $beta / pow($area, $gamma);
        dprint($param, true);
        // best image found, save it
        if ($betanorm > $maxbetanorm) {
            $maxbetanorm = $betanorm;
            $maxparam = $param;
        }
    }
    $timeparts = explode(' ',microtime());
    $endtime = $timeparts[1].substr($timeparts[0],1);
    $elapsed = bcsub($endtime,$starttime,6);
    echo "<br/>Metric computation time (s): ".$elapsed;

    $img->destroy();
    $img = $imgcopy;
    $img->cropImage($maxparam['wcrop'],$maxparam['hcrop'],$maxparam['xcrop'],$maxparam['ycrop']);

    $img->scaleImage($w,$h);
    $img->writeImage($out);

    return 0;
}


/*
 * Given a target $width and $height, returns an imagemagick command
 * string that will resize a source $image to those
 * dimensions while cropping it to the target aspect ratio
 */
function part($image, $width, $height) {
    $commands = '';
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
    return $commands;
}
?>
