<?php header('Content-Type: text/html; charset=utf-8'); ?>﻿<!DOCTYPE html>
<html><head>
	<title>Archimedes Grading Server</title>
	<style>
		body { margin:-1ex; padding:0em; border:1ex solid rgba(255,255,255,0); }
		
		.panel { float:left; margin: 1ex; padding:1ex; border: thin solid; border-radius:2ex; }
		.panel.height { float:left; margin: 0ex; padding:0ex; border: none; border-radius:0ex; }
		
		pre.highlighted { border: thin solid #f0f0f0; white-space:pre-wrap; padding-right: 1ex; max-width:calc(100%-2ex); margin:0em; }
		.highlighted .lineno { background:#f0f0f0; padding:0ex 1ex; color:#999999; font-weight:normal; font-style:normal; }
		.highlighted .comment { font-style: italic; color:#808080; }
		.highlighted .string { font-weight:bold; color: #008000; }
		.highlighted .number { color: #0000ff; }
		.highlighted .keyword { font-weight: bold; color: #000080; }
	</style>
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


var re_comment = /(#[^\n]*)/;
var re_string = /((?:\br?b|\bbr)?"""(?:[^"\\]|\\[\s\S]|""?(?=[^"]))*"""|(?:r?b|br)?'''(?:[^'\\]|\\[\s\S]|''?(?=[^']))*'''|(?:r?b|br)?"(?:[^"\\\n]|\\[\s\S])*"|(?:r?b|br)?'(?:[^'\\\n]|\\[\s\S])*')/;
var re_number = /\b((?:[0-9]*\.[0-9]+(?:[eE][-+][0-9]+)?|[0-9]+\.(?:[eE][-+][0-9]+)?|[0-9]+[eE][-+][0-9]+|0[Bb][01]+|0[Oo][0-7]+|0[Xx][0-9a-fA-F]+|0|[1-9][0-9]*)[jJ]?)\b/;
var re_keyword = /\b(elif|global|as|if|from|raise|for|except|finally|import|pass|return|else|break|with|class|assert|yield|try|while|continue|del|def|lambda|nonlocal|and|is|in|not|or|None|True|False)\b/;

var tokenizer = new RegExp([re_comment.source, re_string.source, re_number.source, re_keyword.source].join('|'), 'g');
var token_types = [null, 'comment', 'string', 'number', 'keyword'];

function htmlspecialchars(s) {
	return s.replace(/\&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;')
}
function highlight() {
	var highlight = document.querySelectorAll('pre code');
	for(var i=0; i<highlight.length; i+=1) {
		var code = highlight[i];
		if (code.parentElement.classList.contains('highlighted')) continue;
		var src = code.innerText.replace(/\t/g, '    ');
		var bits = src.split(tokenizer);
		var newcode = '';
		for(var j=0; j<bits.length; j+=token_types.length) {
			newcode += htmlspecialchars(bits[j]);
			if (j+1 == bits.length) break;
			for(var k=1; k<token_types.length; k+=1)
				if (bits[j+k]) newcode += '<span class="'+token_types[k]+'">'+htmlspecialchars(bits[j+k])+'</span>';
		}
		var lines = newcode.split('\n');
		var wid = String(lines.length).length;
		src = '';
		for(var j=0; j<lines.length; j+=1) {
			src += '<span class="lineno">'+String(j+1).padStart(wid)+'</span>' + lines[j] + '\n';
		}
		code.innerHTML = src + '<input type="button" value="line numbers" onclick="togglelineno()"/><input type="button" value="wrap" onclick="togglewrap()"/>';
		code.parentElement.classList.add('highlighted');
	}
}
function togglewrap() {
	var s = document.querySelectorAll('.highlighted');
	for(var i=0; i<s.length; i+=1) {
		if (s[i].style.whiteSpace == 'pre') {
			s[i].style.whiteSpace = 'pre-wrap';
			s[i].style.overflowX = '';
		}
		else {
			s[i].style.whiteSpace = 'pre';
			s[i].style.overflowX = 'auto';
		}
	}
}
function togglelineno() {
	var s = document.querySelectorAll('.lineno');
	for(var i=0; i<s.length; i+=1) {
		if (s[i].style.display == 'none') s[i].style.display = '';
		else s[i].style.display = 'none';
	}
}
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

