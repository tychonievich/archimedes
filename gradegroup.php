<?php
include "tools.php";
logInAs();
if (!$isstaff) die("page restricted to staff");

if (array_key_exists('student', $_REQUEST)) {
    $st = rosterEntry($_REQUEST['student'], true);
    if (!$st || hasStaffRole($st)) die("{\"error\":\"$_REQUEST[student] is not a student\"}");
    if (array_key_exists('grader', $_REQUEST)) { // adding a grader to a student
        $gr = rosterEntry($_REQUEST['grader'], true);
        if (!$gr || !hasStaffRole($gr)) die("{\"error\":\"$_REQUEST[grader] is not a grader\"}");
        updateUser($_REQUEST['student'], array('grader'=>$_REQUEST['grader'], 'grader_name'=>$gr['name']), false);
        die('true');
    } else { // query the grader of a student
        die(json_encode($st['grader']));
    }
} else if (array_key_exists('lab', $_REQUEST)) { // query the graders assigned to a lab
    $lab = $_REQUEST['lab'];
    $graders = array();
    foreach(fullRoster() as $id=>$details) {
        if (strpos($details['groups'], $lab) !== FALSE && !hasStaffRole($details)) {
            $graders[$id] = $details['grader'];
        }
    }
    die(json_encode($graders));
}
?>﻿<!DOCTYPE html>
<html lang="en"><head>
    <title>Grading group creation</title>
    <script>//<!--
function ajax(payload, qstring, empty=null, response=null) {
	var xhr = new XMLHttpRequest();
	if (!("withCredentials" in xhr)) {
		alert('Your browser does not support TLS in XMLHttpRequests; please use a browser based on Gecko or Webkit'); return null;
	}
	xhr.open("POST", "<?=$_SERVER['SCRIPT_NAME']?>?"+qstring, true);
	xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	
	xhr.withCredentials = true;
	xhr.onerror = function() {
		alert("Grading failed (network trouble or browser incompatibility)");
	}
	xhr.onload = function() {
		if (xhr.responseText.length == 0) {
			if (empty) empty();
			else console.warn("<?=$_SERVER['SCRIPT_NAME']?>?"+qstring + ' returned nothing');
		} else {
			if (response) response(xhr.responseText);
			else console.info("<?=$_SERVER['SCRIPT_NAME']?>?"+qstring + ' returned ' + JSON.stringify(xhr.responseText));
		}
	}
    
	xhr.send(Object.entries(payload).map(e => e.map(ee => encodeURIComponent(ee)).join('=')).join('&'));
}

function picklab(lab) {
    ajax({'lab':lab.value}, '', null, function(txt){
        var now = JSON.parse(txt);
        window.groups = {};
        for(var k in now) {
            if (!window.groups[now[k]]) window.groups[now[k]] = []
            window.groups[now[k]].push(k);
        }
        console.log(window.groups);
        var l = document.getElementById('lab');
        while (l.childNodes.length > 0) l.removeChild(l.lastChild);
        
        for(var k in window.groups) if (k) {
            var bucket = document.createElement('div');
            bucket.classList.add('group');
            bucket.id = k;
            bucket.ondrop = drop_handler;
            bucket.ondragover = dragover_handler;
            bucket.appendChild(document.createTextNode('Grader '+k+': '+window.groups[k].length));
            
        }
        
    });
}
function newgroup() {
    console.log(document.querySelector('[name="addgroup"]').value);
}

function drag_handler(ev) {}
function dragover_handler(ev) { ev.preventDefault(); }
function dragstart_handler(ev) { ev.dataTransfer.setData("text", ev.target.id); }

function drop_handler(ev) {
    ev.preventDefault();
    var data = ev.dataTransfer.getData("text");
    ev.target.appendChild(document.getElementById(data));
    ev.target.firstChild.innerText = 'Grader ' + ev.target.id + ': ' + ev.target.children.length
    ev.target.replaceChild(document.createTextNode(ev.target.id + ': ' + ev.target.children.length), ev.target.childNodes[0])
}

    //--></script>
</head><body>
    <p>Any changes you make here are logged, and appear live in all other Archimedes tools.
    Use only as directed.
    You have been warned.</p>
    <p>Pick a section to view: <select onchange="picklab(this)">
        <option>(select one)</option>
        <option>1110-100</option>
        <option>1110-101</option>
        <option>1110-102</option>
        <option>1110-103</option>
        <option>1110-104</option>
        <option>1110-105</option>
        <option>1110-106</option>
        <option>1110-107</option>
        <option>1110-108</option>
        <option>1110-109</option>
        <option>1110-110</option>
        <option>1110-111</option>
        <option>1111</option>
    </select></p>
    <p>Add a grading group for <?=staffDropdown('addgroup')?> <input type="button" value="create group" onclick="newgroup()"></p>
    <div id="lab"></div>
    <?php ?>
</body></html>
