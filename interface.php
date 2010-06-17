<?php
define('DEBUG', 0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

function dprint($str) {
    echo $str."<br/>\n";
}

$imgsrc = isset($_GET['img']) ? $_GET['img'] : 'test.jpg';
$opticrop_url = "opticrop.php";
$magick_url = "magick.php";
?>

<html>
<head>
    <title>Automagic Crop Tool</title>
    <!--script src="http://code.jquery.com/jquery-1.4.2.min.js"></script-->
    <script src="jcrop/js/jquery.min.js"></script>
    <script src="jcrop/js/jquery.Jcrop.min.js"></script>
    <link rel="stylesheet" href="jcrop/css/jquery.Jcrop.css" type="text/css" />

    <style type="text/css">
        .spacer {float:left; width:10px;}
        .clear {clear:both; overflow:hidden;}
        .code {font-family: "Courier New"; font-size:80%; background: #ccc; padding:4px;}
    </style>

    <script type="text/javascript"> 

        // Remember to invoke within jQuery(window).load(...)
        // If you don't, Jcrop may not initialize properly
        $(window).load(function(){
            var fixTargetSize;
            var api;
            
            init();
            
            function init() {
                fixTargetSize = false;
                
                // initialize crop window
                api = $.Jcrop('#cropbox', {
                        onChange: showCoords,
                        onSelect: showCoords,
                });
                $('#w').val('100');
                $('#h').val('100');
                autoSetBounds();

                // display ajax errors
                $('.log').ajaxError(function(e, xhr, settings, exception) {
                    $(this).text('Triggered ajaxError handler. URL:'+settings.url+' Response: '+xhr.statusText);
                });
                
                // event handlers
                $('#suggest').click(autoSetBounds);
                $('#crop').click(validateAndSubmit);
                $('#w, #h').change(changeSize);
                $('#fixts').change(updateFixTargetSize);
            }
            
            function autoSetBounds() {            
                $('.log').html('Computing crop...');
                var url = "<?php echo $opticrop_url;?>";
                $.get(url,
                    { src: "<?php echo $imgsrc;?>", format: 'json', w: $('#w').val(), h: $('#h').val() },
                    function(data, status, xhr) {
                        var jd = JSON.parse(data);
                        $('.log').html('Request: '+url+'<br/>'+'Status: '+data);
                        api.setSelect([jd.x, jd.y, jd.x+jd.w, jd.y+jd.h]);
                    }
                );
                api.focus();
            }
            
            function updateFixTargetSize() {
                var c = api.tellSelect();
                if (this.checked) {
                    fixTargetSize = true;                    
                    api.setOptions({aspectRatio: $('#w').val() / $('#h').val() });
                }
                else {
                    fixTargetSize = false;
                    api.setOptions({aspectRatio: 0 });
                }                
                api.focus();
            };
            
            function changeSize() {
                var c = api.tellSelect();
                var size = [c.x, c.y, c.x+parseFloat($('#w').val()), c.y+parseFloat($('#h').val())];
                var str = "("+c.x+","+c.y+","+c.x2+","+c.y2+") =>"; 
                str += "("+c.x+","+c.y+","+(c.x+parseFloat($('#w').val()))+","+(c.y+parseFloat($('#y').val()))+")";
                $('.log').html(str);
                api.setOptions({aspectRatio: $('#w').val() / $('#h').val() });
                api.setSelect(size);                     
                api.focus();
            }
            
            // Our simple event handler, called from onChange and onSelect
            // event handlers, as per the Jcrop invocation above
            function showCoords(c)
            {
                jQuery('#x').val(c.x);
                jQuery('#y').val(c.y);
                jQuery('#x2').val(c.x2);
                jQuery('#y2').val(c.y2);
                if (!fixTargetSize) {
                    $('#w').val(c.x2-c.x);
                    $('#h').val(c.y2-c.y);
                }
            };
            
            function validateAndSubmit()
            {
                if (!parseInt($('#w').val())) {
                    alert('Please select a crop region then press submit.');
                    return false;
                }
                
                var c = api.tellSelect();
                
                var cmd = "crop("+c.w+"x"+c.h+"+"+$('#x').val()+"+"+$('#y').val()+")";
                cmd += "+resize("+$('#w').val()+"x"+$('#h').val()+")";
                var qstr = "<?php echo $magick_url;?>";
                qstr += "?src=<?php echo $imgsrc;?>";
                qstr += "&cmd="+encodeURIComponent(cmd);
                
                $('.log').html('Building crop img tag...<br/>query: '+qstr);
                $('#output').html("<img src=\"http://localhost/opticrop/"+qstr+"\"/>");
                /*var url = "<?php echo $magick_url;?>";
                $.get(url,
                    { src: "<?php echo $imgsrc;?>", cmd: cmd},
                    function(data) {
                        $('.log').html('request: '+url+'<br/>'+'Response: '+data);
                    }
                );*/
                api.focus();
            };
        });
        </script>
</head>
<body>
    <h1>Automagic Crop Tool</h1>
    <p>
        <a href="interface.php">Test 1</a> | 
        <a href="interface.php?img=test2.jpg">Test 2</a> | 
        <a href="interface.php?img=test3.jpg">Test 3</a> | 
        <a href="interface.php?img=test4.jpg">Test 4</a>
    </p>
    <div class="log"></div>

    <img src="<?php echo $imgsrc;?>" id="cropbox"/>
    <form onsubmit="return false;"> 
        <label>X1 <input type="text" size="4" id="x" /></label> 
        <label>Y1 <input type="text" size="4" id="y" /></label> 
        <label>X2 <input type="text" size="4" id="x2" /></label> 
        <label>Y2 <input type="text" size="4" id="y2" /></label> 
        <label>Target width <input type="text" size="4" id="w" /></label> 
        <label>Target height <input type="text" size="4" id="h" /></label>
        <label>Fix target size? <input type="checkbox" id="fixts" value="1"/></label>     
        <input type="button" id="suggest" value="Suggest Crop" /> 
        <input type="button" id="crop" value="Crop Image" /> 
    </form>  
    <div id="output"></div>
</body>
</html>
