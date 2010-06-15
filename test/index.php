<?php
define('DEBUG', 0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

function dprint($str) {
    echo $str."<br/>\n";
}

?>

<html>
<head><title>Test crops</title></head>

<style type="text/css">
img {float:left; padding:4px; border: 1px solid #ccc;}
.spacer {float:left; width:10px;}
.clear {clear:both; overflow:hidden;}
.code {font-family: "Courier New"; font-size:80%; background: #ccc; padding:4px;}
</style>

<body>
<h1>Automagic Cropper Script Demo</h1>
<p>Here is a script that will automatically crop an image to a desired size, while keeping the most interesting features in the image in frame.</p>
<p>This can be used to generate any kind of thumbnail you want.</p>

<p>Syntax (example, not live yet)</p>
<p class="code">http://news.sciencemag.org/process/crop/?src=http://news.sciencemag.org/assets/2010/06/15/sn-topic.jpg&cmd=opticrop2(300x300)</p>

<p>To use, just include the above url as the src attribute of an img tag.</p>
<p class="code">&lt;img src="http://news.sciencemag.org/process/crop/?src=http://news.sciencemag.org/assets/2010/06/15/sn-topic.jpg&cmd=opticrop2(300x300)"/&gt;</p>

<p>Here are some sample results.</p>
<p>Images shown: original, 300x300, 200x50 (widget), 170x40 (sidebar), 80x80 (Premium content), 60x60 (article feed).</p>

<?php
$files = glob("*.jpg");
foreach ($files as $file) {
    $imgstr = "<h2>$file</h2>";
    $imgstr .= "<img src=\"$file\"/>\n";
    $imgstr .= "<div class=\"spacer\">&nbsp;</div>\n";
    $imgstr .= "<div class=\"clear\">&nbsp;</div>\n";

    $imgstr .= "<img src=\"/process/magick.php?src=http://localhost/process/test/$file&cmd=opticrop2(300x300)\"/>\n";
    $imgstr .= "<div class=\"spacer\">&nbsp;</div>\n";

    $imgstr .= "<img src=\"/process/magick.php?src=http://localhost/process/test/$file&cmd=opticrop2(200x50)\"/>\n";
    $imgstr .= "<div class=\"spacer\">&nbsp;</div>\n";
    $imgstr .= "<img src=\"/process/magick.php?src=http://localhost/process/test/$file&cmd=opticrop2(170x40)\"/>\n";
    $imgstr .= "<div class=\"spacer\">&nbsp;</div>\n";
    $imgstr .= "<img src=\"/process/magick.php?src=http://localhost/process/test/$file&cmd=opticrop2(80x80)\"/>\n";
    $imgstr .= "<div class=\"spacer\">&nbsp;</div>\n";
    $imgstr .= "<img src=\"/process/magick.php?src=http://localhost/process/test/$file&cmd=opticrop2(60x60)\"/>\n";
    $imgstr .= "<div class=\"clear\">&nbsp;</div>\n";

    echo $imgstr;
}
?>
    
</body>
</html>
