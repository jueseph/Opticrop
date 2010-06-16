<?php

/* 
 * edge-maximizing crop
 * determines center-of-edginess, then tries different-sized crops around it. 
 * picks the crop with the highest normalized edginess.
 * see documentation on how to tune the algorithm
 *
 * $w, $h - target dimensions of thumbnail
 * $image - system path to source image
 * $out - path/name of output image
 */

function opticrop($image, $w, $h, $out) {
    // source dimensions
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

    dprint("$image: $w0 x $h0 => $w x $h");
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
        if (DEBUG > 0) {
            $currimg = clone $img;
            $c= new ImagickDraw(); 
            $c->setFillColor("red"); 
            $c->circle($xcenter, $ycenter, $xcenter, $ycenter+4); 
            $currimg->drawImage($c); 
            $currimg->cropImage($wcrop, $hcrop, $xcrop, $ycrop);
            $currimg->writeImage($currfile);
            $currimg->destroy();
        }

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

    if (FORMAT == 'json') {
        // return coordinates instead of image
        $data = json_encode($maxparam);
        file_put_contents($out, $data);
    }
    else {
        // return image
        $imgcp->cropImage($maxparam['w'],$maxparam['h'],
            $maxparam['x'],$maxparam['y']);
        $imgcp->scaleImage($w,$h);
        $imgcp->writeImage($out);
    }
    chmod($out, 0777);
    $img->destroy();
    $imgcp->destroy();
    return 0;
}

?>
