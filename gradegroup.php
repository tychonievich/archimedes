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
            $graders[$id] = array('grader'=>$details['grader'],'name'=>$details['name']);
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

function idwrap(s) {
    var t = /\(([^\)]*)\)/.exec(s);
    if (t) s = t[1];
    return s;
}

function updatelab() {
    ajax({'lab':document.querySelector('select').value}, '', null, function(txt){
        var now = JSON.parse(txt);
        window.groups = {};
        for(var k in now) {
            var grader = now[k]['grader'];
            if (!window.groups[grader]) window.groups[grader] = []
            window.groups[grader].push(now[k]['name']+' ('+k+')');
        }
        var l = document.getElementById('lab');
        
        for(var k in window.groups) {
            var bucket = document.getElementById(k == 'null' ? 'no grader' : k);
            if (!bucket) {
                bucket = document.createElement('div');
                bucket.classList.add('group');
                bucket.id = (k == 'null' ? 'no grader' : k);
                bucket.ondrop = drop_handler;
                bucket.ondragover = dragover_handler;
                bucket.appendChild(document.createTextNode('Grader '+(k == 'null' ? 'no grader' : k)+': '+window.groups[k].length));
                l.appendChild(bucket);
            }
            for(var i=0; i<window.groups[k].length; i+=1) {
                var p = document.getElementById(idwrap(window.groups[k][i]));
                if (!p) {
                    p = document.createElement('div');
                    p.id = idwrap(window.groups[k][i]);
                    p.ondrag = drag_handler;
                    p.ondragstart = dragstart_handler;
                    p.draggable = true;
                    p.classList.add('student');
                    p.appendChild(document.createTextNode(window.groups[k][i]));
                    var i = document.createElement('img');
                    img.src =  'picture.php?user='+p.id;
                    img.classList.add('hide');
                    p.appendChild(img);
                }
                bucket.appendChild(p);
            }
        }
    
        var all = document.querySelectorAll('.group');
        for(var i=0; i<all.length; i+=1) {
            all[i].replaceChild(document.createTextNode('Grader ' + all[i].id + ': ' + all[i].children.length), all[i].childNodes[0]);
            var nodes = []; nodes.push.apply(nodes, all[i].querySelectorAll('.student'));
            nodes.sort(function(a,b) { return a.id < b.id ? -1 : +(a.id > b.id); });
            nodes.forEach(function(n){n.parentNode.appendChild(n);});
            
        }
    });
}

function picklab(lab) {
    ajax({'lab':lab.value}, '', null, function(txt){
        var now = JSON.parse(txt);
        window.groups = {};
        for(var k in now) {
            var grader = now[k]['grader'];
            if (!window.groups[grader]) window.groups[grader] = []
            window.groups[grader].push(now[k]['name']+' ('+k+')');
        }
        var l = document.getElementById('lab');
        while (l.childNodes.length > 0) l.removeChild(l.lastChild);
        
        for(var k in window.groups) {
            var bucket = document.createElement('div');
            bucket.classList.add('group');
            bucket.id = (k == 'null' ? 'no grader' : k);
            bucket.ondrop = drop_handler;
            bucket.ondragover = dragover_handler;
            bucket.appendChild(document.createTextNode('Grader '+(k == 'null' ? 'no grader' : k)+': '+window.groups[k].length));
            for(var i=0; i<window.groups[k].length; i+=1) {
                var p = document.createElement('div');
                p.id = idwrap(window.groups[k][i]);
                p.ondrag = drag_handler;
                p.ondragstart = dragstart_handler;
                p.draggable = true;
                p.classList.add('student');
                p.appendChild(document.createTextNode(window.groups[k][i]));
                var img = document.createElement('img');
                img.src = 'picture.php?user='+p.id;
                img.classList.add('hide');
                p.appendChild(img);
                bucket.appendChild(p);
            }
            l.appendChild(bucket);
        }
        var nodes = []; nodes.push.apply(nodes, document.querySelectorAll('.student'));
        nodes.sort(function(a,b) { return a.id < b.id ? -1 : +(a.id > b.id); });
        nodes.forEach(function(n){n.parentNode.appendChild(n);});
       
    });
}
function newgroup() {
    var grader = document.querySelector('[name="addgroup"]').value;
    document.querySelector('[name="addgroup"]').value = '';
    if (!grader || document.getElementById(grader)) return;
    var bucket = document.createElement('div');
    bucket.classList.add('group');
    bucket.id = grader;
    bucket.ondrop = drop_handler;
    bucket.ondragover = dragover_handler;
    bucket.appendChild(document.createTextNode('Grader '+grader+': 0'));
    document.getElementById('lab').appendChild(bucket);
}

function drag_handler(ev) {}
function dragover_handler(ev) { ev.preventDefault(); }
function dragstart_handler(ev) {
    updatelab(document.querySelector('select'));
    ev.dataTransfer.setData("text", ev.target.id); 
}

function drop_handler(ev) {
    ev.preventDefault();
    var data = ev.dataTransfer.getData("text");
    var dest = ev.target;
    while(dest && !dest.classList.contains('group')) dest = dest.parentElement;
    if (!dest) return;
    dest.appendChild(document.getElementById(data));
    ajax({'student':data,'grader':dest.id}, '', null, function(txt){
        console.log('returned', txt);
    });
    var all = document.querySelectorAll('.group');
    for(var i=0; i<all.length; i+=1) {
        all[i].replaceChild(document.createTextNode('Grader ' + all[i].id + ': ' + all[i].children.length), all[i].childNodes[0]);
        var nodes = []; nodes.push.apply(nodes, all[i].querySelectorAll('.student'));
        nodes.sort(function(a,b) { return a.id < b.id ? -1 : +(a.id > b.id); });
        nodes.forEach(function(n){n.parentNode.appendChild(n);});
        
    }
}

    //--></script><style>
        .group { float:left; margin:1em; padding:1em; background-color:#eee;border:thin solid #ddd; }
        .student { margin:0.5ex; padding:0.5ex; background-color:#ffd;border:thin solid #ffb; }
        img { max-width: 10em; display:block; }
        img.hide { display: none; }
    </style>
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
    <div><input type="button" value="Show pictures" onclick="if (this.value == 'Show pictures') { this.value = 'Hide pictures'; document.querySelectorAll('img').forEach(function(x){x.classList.remove('hide');}); } else {this.value = 'Show pictures'; document.querySelectorAll('img').forEach(function(x){x.classList.add('hide');}); }"/></div>
    <div id="lab"></div>
    <?php ?>
</body></html>
