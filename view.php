<?php header('Content-Type: text/html; charset=utf-8'); ?>﻿<!DOCTYPE html>
<html><head>
	<title>Archimedes Grading Server</title>
	<style>
		body { margin:-1ex; padding:0em; border:1ex solid rgba(255,255,255,0); }
		
		.panel { float:left; margin: 1ex; padding:1ex; border: thin solid; border-radius:2ex; }
		.panel.height { float:left; margin: 0ex; padding:0ex; border: none; border-radius:0ex; }
		
		pre.highlighted { border: thin solid #f0f0f0; padding-right: 1ex; max-width:calc(100%-2ex); margin:0em; }
		.highlighted .lineno { background:#f0f0f0; padding:0ex 1ex; color:#999999; font-weight:normal; font-style:normal; }
		.highlighted .comment { font-style: italic; color:#808080; }
		.highlighted .string { font-weight:bold; color: #008000; }
		.highlighted .number { color: #0000ff; }
		.highlighted .keyword { font-weight: bold; color: #000080; }
	</style>
	<script src="dates_collapse.js"></script>
    <script type="text/javascript" src="codebox_py.js"></script>
	<script>
function imageresize() {
	// use of devicePixelRatio does help allow page zoom, but not clear if it works for UHDA devices
	var panels = document.querySelectorAll('.panel.width');
	for(var i=0; i<panels.length; i+=1) {
		panels[i].style.maxWidth = (window.innerWidth * window.devicePixelRatio) + 'px';
	}
	var panels = document.querySelectorAll('.panel.height');
	for(var i=0; i<panels.length; i+=1) {
		panels[i].style.maxHeight = (window.innerHeight * window.devicePixelRatio) + 'px';
	}
}
window.onresize = imageresize;
	</script>
</head><body onload="imageresize(); highlight();">
<?php
include "tools.php";
logInAs();

if (!array_key_exists('file', $_REQUEST)) die('Failed to provide a file name</body></html>');

$path = trim($_REQUEST['file'], "./\\\t\n\r\0\x0B");
if (!$path) die('Failed to provide a file name</body></html>');
if (($isstaff && $isself) && strpos($path, 'support') === 0) $path = "meta/$path";
else $path = "uploads/$path";

if (realpath($path) != getcwd()."/$path") die('Invalid file name</body></html>');
if (basename($path)[0] == '.') die('Invalid file name</body></html>');

if (is_dir($path)) $path .= '/';

if (!($isstaff && $isself) && strpos($path, "/$user/") === FALSE) die('Invalid file name</body></html>');

if (is_dir($path)) {
	$submitted = array();
	foreach(glob("$path*") as $fname) {
		$submitted[basename($fname)] = $fname;
	}
	$parts = explode('/', trim($path, '/'));
	if ($parts[0] == 'uploads' && count($parts) == 3) {
		$a = assignments()[$parts[1]];
		if (array_key_exists('extends', $a)) {
			foreach($a['extends'] as $slug2) {
				foreach(glob("uploads/$slug2/$parts[2]/*") as $path) {
					$n = basename($path);
					if (!array_key_exists(basename($path), $submitted)) {
						$submitted[$n] = $path;
					}
				}
			}
		}
		ksort($submitted);
	}
	foreach($submitted as $name=>$fname) {
		echo studentFileTag($fname);
	}

} else {
	echo studentFileTag($path);
}
?>
</body></html>

