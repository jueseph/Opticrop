<?php
/* 
 * edge-maximizing crop - tries different crops in a grid
 * very slow - use opticrop instead
 *
 * generates a thumbnail of desired dimensions from a source image by cropping 
 * the most "interesting" or edge-filled part of the image.
 *
 * $w, $h - target dimensions of thumbnail
 * $image - system path to source image
 * $out - path/name of output image
 */

function opticrop2($image, $w, $h, $out) {
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

?>
