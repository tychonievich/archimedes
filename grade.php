<?php
// accept asynchronous grade postings, comment set updates, and comment set queries without HTML content

include "tools.php";
$noPre = true; // turn off <pre></pre> stuff
logInAs();
if (!$isstaff) die("page restricted to staff");

$issuperuser = ($user == 'lat7h' || $user == 'ez4cc' || $user == 'cap4yf' || $user == 'mg3ta'); // HACK!

if (array_key_exists('addgrade', $_REQUEST)) {
	// validate payload
	$grade = json_decode(file_get_contents("php://input"), true);
	if (!$grade) die ("invalid JSON payload received");
	if (!array_key_exists('slug', $grade) || !array_key_exists('student', $grade) || !array_key_exists('details', $grade)) die ("grade payload missing required keys");
	$grade['timestamp'] = time();
	
	// copy down any new comments into the commentpool, and compute the final grade total
	totalGradeAndAddPool($grade);

	// add a late penalty, if needed
	$sentin = 0;
	foreach(glob("uploads/$grade[slug]/$grade[student]/*") as $path) {
		$finfo = new finfo(FILEINFO_MIME);
		$mime = $finfo->file($path);
		if (stripos($mime, 'image') !== FALSE) { continue; }
		$t = filemtime($path);
		if ($t > $sentin) $sentin = $t;
	}
	$asgn = assignments()[$grade['slug']];
	if (file_exists("uploads/$grade[slug]/$grade[student]/.extension")) {
		$extension = json_decode(file_get_contents("uploads/$grade[slug]/$grade[student]/.extension"), true);
		$asgn = $extension + $asgn;
	}
	$due = assignmentTime('due', $asgn) + 5*60; // give them 5 minutes to avoid server-time sync complaints
	if (array_key_exists('late-policy', $asgn) && count($asgn['late-policy']) > 0 && $due < $sentin) {
		$late = $asgn['late-policy'];
		$days = intval(1 + ($sentin - $due) / (24*60*60));
		$mult = array('comment'=>($days == 1 ? "$days day late" : "$days days late"));
		if ($days-1 >= count($late)) {
			$mult['ratio'] = $late[count($late)-1];
		} else {
			$mult['ratio'] = $late[$days-1];
		}
		$grade['grade'] *= $mult['ratio'];
		if (!array_key_exists('.mult', $grade)) $grade['.mult'] = array($mult);
		else $grade['.mult'][] = $mult;
	}
	
	// post to uploads/assignment/.gradelog and uploads/assignment/student/.grade
	$payload = json_encode($grade);
	file_put("uploads/$grade[slug]/$grade[student]/.grade", $payload);
	if (file_exists("uploads/$grade[slug]/$grade[student]/.partners")) {
		foreach(explode("\n",file_get_contents("uploads/$grade[slug]/$grade[student]/.partners")) as $pair) {
			file_put("uploads/$grade[slug]/$pair/.grade", $payload);
		}
	}
	file_append("uploads/$grade[slug]/.gradelog", "$payload\n");
	
	if (array_key_exists('regrade', $grade) && file_exists("meta/requests/regrade/$grade[slug]-$grade[student]")) {
		rename("meta/requests/regrade/$grade[slug]-$grade[student]",
			"meta/requests/regrade/.".date("Ymd-His", filemtime(
				"meta/requests/regrade/$grade[slug]-$grade[student]"
			))."-$grade[slug]-$grade[student]");
	}
	
	// log that I graded it for future redo=review
	file_append("users/.graded/$user/$grade[slug]", "$grade[student]\n");
	
	// inform invoking method of success
	die(json_encode(array("success"=>True,"slug"=>$grade['slug'],"student"=>$grade['student'])));
}
if (array_key_exists('getcomments', $_REQUEST)) {
	$extra = array();
	if (array_key_exists('viewing', $_REQUEST)) {
		if (file_exists("uploads/$_REQUEST[slug]/$_REQUEST[viewing]/.grade")) {
			$extra['.grade'] = true;
		}

		$studentpath = "uploads/$_REQUEST[slug]/$_REQUEST[viewing]/.view";
		$graderpath = "users/.$user";
		
		$oldgrader = file_exists($studentpath) ? (filemtime($studentpath) < time()-45 ? False : file_get_contents($studentpath)) : False;
		
		if ($oldgrader != $user && file_exists($graderpath)) {
			$oldstudentpath = file_get_contents($graderpath);
			unlink($graderpath);
			// race condition exists below, but if it triggers someone just gets graded twice...
			if (file_exists($oldstudentpath) && file_get_contents($oldstudentpath) == $user)
				unlink($oldstudentpath);
		}
		
		if ($oldgrader == $user || !$oldgrader) {
			file_put_contents($studentpath, $user);
			file_put_contents($graderpath, $studentpath);
		} else {
			$extra['.view'] = fullRoster()[$oldgrader]['name'] . " ($oldgrader)";
		}
	}
	die(json_encode($extra + commentSet($_REQUEST['slug'], $_REQUEST['getcomments'])));
}


header('Content-Type: text/html; charset=utf-8');
?>﻿<!DOCTYPE html>
<html><head>
	<title>Archimedes Grading Server</title>
	
	<style>
body { font-family: sans-serif; }
.assignment, .action { border-radius:1ex; padding:1ex; margin:1ex 0ex; }
.assignment.pending { background:rgba(0,0,0,0.0625); opacity:0.5; }
.assignment.open { background:rgba(0,255,127,0.0625); }
.assignment.late { background:rgba(255,127,0,0.0625); }
.assignment.closed { background:rgba(0,0,0,0.0625); }
.action { background:rgba(191,0,255,0.0625); border: thin solid rgba(191,0,255,0.25)}
div.prewrap pre { background: rgba(63,31,0,1); color: white; padding:1ex; margin:0em; float:left; }
div.prewrap { max-width:50em; overflow:auto; padding:0em; margin:0em; background: rgba(63,31,0,1); }

h1 { margin:0.25ex 0ex; text-align:center; }
p { margin:0.5ex 0ex; }

.assignment tt, .big tt { border: thin solid white; padding: 1px; background: rgba(255,255,255,0.25); }

.hide-outer { margin:0ex 0.5ex; border:thin solid rgba(0,0,0,0.5); padding:0.5ex; border-radius:0.5ex; }
.hidden strong { font-weight: normal; display:block; width:100%; }
.hidden > strong:before { content: "+ "; }
.shown > strong:before { content: "− "; }
.hidden .hide-inner { display:none; }
.hide-outer textarea { width:100%; }
.hide-outer.important { border-width:thick; background:rgba(255,255,0,0.5); }

.big { font-size: 150%; margin:1ex; padding:1ex; border: thick solid; border-radius:1ex; }
.big h1 { font-size: 125%; text-align:left; margin:0em;}
.big.notice { border-color:rgba(0,0,0,0.25); background-color:rgba(0,0,0,0.0625); font-size:125%; }
.big.success { border-color:rgba(0,255,127,0.5); background-color:rgba(0,255,127,0.125); }
.big.error { border-color:rgba(255,63,0,0.5); background-color:rgba(255,63,0,0.125); }

pre.rawtext, pre.feedback, pre.regrade-request, pre.regrade-response { white-space:pre-wrap; padding:0.5ex; }
.stdout { background: white; } .stderr, pre.regrade-request { background: #ddd; }
pre.regrade-request { font-family: sans-serif; margin:0.5ex; }

dl, dt, ul, li { margin-top:0em; margin-bottom:0em; }
dl.comments dt { font-weight:bold; margin-left:1em; }
dl.comments dd { margin:0em; }
dl.comments ul { margin:0em; }

.linklist li { margin:1ex 0ex; }

.advice { font-style: italic; opacity:0.5;}
dl.buckets > dt { font-weight: bold; }
dl.buckets > dd > p { text-indent:-2em; margin:0em; margin-left: 2em; }
	</style>
	<style>
		body { margin:0em; padding:0em; border:1ex solid rgba(255,255,255,0); }
		
		.panel.left { float:left; margin: 0ex; clear:left; }
		.panel.left + .panel.left, a + .panel.left, .panel.left + a .panel.left { margin-top: 1ex; }
		
		.student.name:before { content:"submitted by: "; font-size:70.71%; opacity:0.7071; } 
		.grader.name:before { content:"last graded by: "; font-size:70.71%; opacity:0.7071; } 
		.viewer.name:before { content:"checked out for grading by: "; font-size:70.71%; opacity:0.7071; } 
		.viewer.name { background: yellow; }
		.name { opacity:0.7071; } 

dd { margin-left:1em; }

		li, dt, dd { margin: 0em; }
		.buckets > dd { margin-left:1em; }
		ul { padding-left: 1em; }
		dl dl, dl ul, dl div.percentage, dl div.extra { margin:0em 0em 0em 1em; }
		dl div.percentage, dl dl.buckets, dl dl.breakdown, dl div.extra { 
			border-left: thin solid rgba(0,0,0,0.25); padding-left:1ex; margin-bottom:1ex; 
		}

.ungraded { border: 0.25ex solid rgba(255,127,0,0.25); padding:0.25ex; background-color: rgba(255,127,0,0.0625); border-radius:0.5ex; }

dd.collapsed { display:none; }
dt.collapsed:before { content: "+ "; }
dt.collapsed:after { content: " …"; }
dt.collapsed { font-style: italic; }

input, textarea { padding:0ex; margin:0ex; font-size:100%; font-family:inherit; }
pre { margin:0em; }
	
input[type="text"] { border:0.125ex solid gray; background-color:inherit; padding:0.125ex; border-radius:0.5ex; }
input[type="button"] { border:0.125ex solid gray; background:#eeedeb; color:black; padding:0.125ex 1ex; border-radius:0.5ex; }
input[type="button"]:hover { background: white; color: black; }
input + input { margin-left:0.5ex; }

.regrade-response { margin:0.5ex; padding:0.5ex; width: calc(100% - 2ex); font-family: inherit; }

		input.error, div.percentage.error > input[type="text"], div.check.error { background-color: rgba(255,0,0,0.25); }
		
		.table-columns { margin:0em; border:0px solid black; padding:0em; }
		table.table-columns { border-collapse:collapse; margin:-1ex; }
		.table-columns td { padding:0ex; vertical-align:top; padding-right:1ex; }
		.table-columns td + td { padding:1ex; border-left:dotted thin; }

		table.table-columns.done, table.table-columns:not(.done) + table.table-columns, table.table-columns:not(.done) + #grading-footer { display:none; }


		pre.highlighted { border: thin solid #f0f0f0; white-space:pre-wrap; padding-right: 1ex; max-width:calc(100%-2ex); margin:0em; }
		.highlighted .lineno { background:#f0f0f0; padding:0ex 1ex; color:#999999; font-weight:normal; font-style:normal; }
		.highlighted .comment { font-style: italic; color:#808080; }
		.highlighted .string { font-weight:bold; color: #008000; }
		.highlighted .number { color: #0000ff; }
		.highlighted .keyword { font-weight: bold; color: #000080; }
	</style>
	<script type="text/javascript" src="codebox.js"></script>
	<script>
/**
 * This function is supposed to change all self-sizing panels based on a page resize
 * It has to be javascript because CSS does not (yet) support it such that page zoom still works
 */
function imageresize() {
	// use of devicePixelRatio does help allow page zoom, but not clear if matters for UHDA devices
	var panels = document.querySelectorAll('.panel.width');
	for(var i=0; i<panels.length; i+=1) {
		panels[i].style.maxWidth = (window.innerWidth * window.devicePixelRatio / 2) + 'px';
	}
	var panels = document.querySelectorAll('.panel.height');
	for(var i=0; i<panels.length; i+=1) {
		panels[i].style.maxHeight = (window.innerHeight * window.devicePixelRatio) + 'px';
	}
}
window.onresize = imageresize;


<?php
$js_asgn = assignments()[$_GET['assignment']];
if (array_key_exists('total', $js_asgn)) { $js_total = $js_asgn['total']; }
else { $js_total = 1; }
?>


/** Adds hooks to all definition lists so that clicking their terms toggles the visibility of their definitions */
function setUpCollapses() {
	var breakdowns = document.querySelectorAll('dt');
	for(var i=0; i<breakdowns.length; i+=1) {
		breakdowns[i].onclick = function() {
			if (this.classList.contains('collapsed')) {
				this.nextElementSibling.classList.remove('collapsed');
				this.classList.remove('collapsed');
			} else {
				this.nextElementSibling.classList.add('collapsed');
				this.classList.add('collapsed');
			}
		}
	}
}

/** Actually creates and adds a comment HTML structure */
function _addcomment(key, value) {
	var path = key.substring(1).replace(/\n/g,'/');
	var cssSelector = '[name="'+path+'"]';
	var places = document.querySelectorAll(cssSelector);
	if (!places.length) { 
		path = "/"+path; 
		cssSelector = '[name="'+path+'"]';
		places = document.querySelectorAll(cssSelector); 
	}
	// console.log(key, path, value, places);
	for(var i=0; i<places.length; i+=1) {
		var p = places[i];
		var kind = (p.classList.contains('percentage') && !p.classList.contains('set')) ? 'radio' : 'checkbox';
		var html_front = '<label><input type="'+kind+'" name="'+p.id+'" value=';
		var html_back = '</label>';
		html_back += ' (<textarea rows="1"></textarea>)';
		for(var j=0; j<value.length; j+=1) {
			var isstr = 'string' ==  typeof value[j];
			var json = JSON.stringify(value[j]);
			if (!isstr) json = "'"+json.replace(/'/g, '&apos;')+"'";
			else json = json.replace(/\\\\/g, '\\'); // don't escape backslashes in strings
			if (p.querySelector('input[value='+json+']') != null) continue; // skip duplicates
			var html = html_front + json + '/> ' ;
			if (p.classList.contains('percentage')) html += '&times;'+value[j].ratio*<?=$js_total?>+': ';
			html += htmlspecialchars(isstr ? value[j] : value[j].comment) + html_back;
			var wrapper = document.createElement('p');
			wrapper.innerHTML = html;
			var after = p.querySelector(cssSelector + ' > input');
			after.parentElement.insertBefore(wrapper, after);
		}
	}
}

/** The function called by the "add comment" button, by parsing inputs and invoking _addcomment */
function addcomment(id) {
	var el = document.getElementById(id);
	if (!el.value) { el.classList.add('error'); return; } else el.classList.remove('error');
	var id2 = id.replace(/^[^\n]*\n/, 'ratio\n');
	var el2 = document.getElementById(id2);
	if (el2) { if(!el2.value || Number.isNaN(Number(el2.value))) { el2.classList.add('error'); return; } else el2.classList.remove('error'); }
	
	var par = el.parentNode;
	
	var key = '\n'+id.split('\n').slice(3).join('\n');
	var value = el.value;
	el.value = '';
	if (el2) { 
		value = {ratio:Number(el2.value), comment:value};
		el2.value = '';
		if (Math.abs(value.ratio) > 2) value.ratio /= <?=$js_total != 1 ? $js_total : 100?>; // assume percentage
		if (value.ratio < 0) value.ratio = 1 + value.ratio; // assume penalty
	}
	
	_addcomment(key, [value]);
	var labels = par.getElementsByTagName('label');
	labels[labels.length - 1].firstElementChild.checked = true;
	return;
}

/** parses the HTML dom to figure out what the actual grade JSON ought to be */
function _grade(id) {
	var root = document.getElementById(id);
	if (root.classList.contains('buckets')) {
		var kids = root.children;
		var dds = [];
		for(var i=0; i<kids.length; i+=1) { if (kids[i].tagName.toLowerCase() == 'dd') dds.push(kids[i]); }
		var details = [];
		for(var i=0; i<dds.length; i+=1) {
			details.push([]);
		}
		for(var i=0; i<dds.length; i+=1) {
			var dd = dds[i];
			var path = dd.id.split('\n');
			var idx = Number(path[path.length-1]);
			var entries = dd.querySelectorAll('input:checked');
			for(var j=0; j<entries.length; j+=1) {
				var entry = entries[j].value;
				var more = entries[j].parentNode.nextElementSibling.value;
				if (more) details[idx].push(entry + ' (' + more + ')')
				else details[idx].push(entry)
			}
		}
		return details;
	} else if (root.classList.contains('breakdown')) {
		var ans = {};
		var kids = root.children;
		for(var i=0; i<root.children.length; i+=1) if (root.children[i].tagName.toLowerCase() == 'dd') {
			var node = root.children[i].firstElementChild;
			var key = node.id.split('\n'); key = key[key.length-1];
			ans[key] = _grade(node.id);
		}
		return ans;
	} else if (root.classList.contains('check')) {
		console.log(root);
		var json = root.querySelector('input:checked');
		if (!json) { root.classList.add('error'); throw new Error('Missing check-off grade'); }
		else root.classList.remove('error');

		var entry = JSON.parse(json.value);
		
		return entry;
	} else if (root.classList.contains('percentage')) {
		if (root.classList.contains('set')) {
			var set = root.querySelectorAll('input:checked');
			var ans = [];
			for(var i=0; i<set.length; i+=1) { 
				var entry = JSON.parse(set[i].value);
				var more = set[i].parentNode.nextElementSibling.value;
				if (more) entry.comment += ' (' + more + ')'
				ans.push(entry); 
			}
			return ans;
		} else {
			var json = root.querySelector('input:checked');
			if (!json) { root.classList.add('error'); throw new Error('Missing percentage grade'); }
			else root.classList.remove('error');

			var entry = JSON.parse(json.value);
			var more = json.parentNode.nextElementSibling.value;
			if (more) entry.comment += ' (' + more + ')'

			return entry;
		}
	} else {
		alert('Grader script error: unexpected rubric kind '+JSON.stringify(root.classList));
	}
}

/** Callback for the "submit grade" button: tell the server, hide the student, and ask for new comments */
function grade(id) {
	var ans = {
		grader:"<?=$user?>", 
		slug:id.split('\n',2)[0], 
		student:id.split('\n',3)[1],
		details:_grade(id),
	}
	var rg = document.getElementById(id+"\nregrade");
	if (rg) {
		var rglog = [];
		for(var i=0; i<rg.children.length; i+=1) {
			if (rg.children[i].classList.contains('regrade-request')) rglog.push({request:rg.children[i].innerHTML});
			if (rg.children[i].classList.contains('regrade-response')) rglog[rglog.length-1].response = rg.children[i].value;
		}
		ans.regrade = rglog;
	}
	if (ans) ajax(ans, 'addgrade=1&asuser=<?=$user?>'); // FIX ME: add checking of response code
	document.getElementById("table\n"+id).classList.add('done');
	commentPoll(id.split('\n')[0]);
}

/** hides the current student's work and moves on to the next one */
function skip(id, skipped=true) {
	document.getElementById("table\n"+id).classList.add('done');
	commentPoll(id.split('\n')[0]);
	if (skipped) {
		var foot = document.getElementById('grading-footer');
		foot.firstElementChild.classList.remove('success');
		foot.firstElementChild.classList.add('notice');
		foot.firstElementChild.firstElementChild.innerText = "All assignments either graded or skipped";
		foot.firstElementChild.lastElementChild.innerText = "You skipped some assignments; reload this page to see them.";
	}
}


/** I wrote this, intentionally, and have no recollection of its purpose. Sorry. */
function postpone(id, viewer) {
	var el = document.getElementById("table\n"+id);
	if (el.classList.contains('viewed')) return;
	el.classList.add('viewed');
	var view = el.querySelector('.name.viewer');
	if (view) {
		view.innerHTML = viewer;
	} else {
		//          tbody            tr               td
		var td = el.lastElementChild.lastElementChild.lastElementChild;
		
		var entry = document.createElement('div');
		entry.classList.add('name');
		entry.classList.add('viewer');
		entry.appendChild(document.createTextNode(viewer));
		td.insertBefore(entry, td.firstChild);
	}
	var foot = document.getElementById('grading-footer');
	foot.parentNode.insertBefore(el, foot);
	commentPoll(id.split('\n')[0]);
}

/** Sends a request to the comment server, and processes its reply */
var lastCommentIndex = 0;
function commentPoll(slug) {
	var viewing = document.querySelector('table.table-columns:not(.done)');
	if (!viewing) return;
	viewing = viewing.id.replace(/[\s\S]*\n/g, '');
	ajax('', 'getcomments='+lastCommentIndex+'&slug=' + encodeURIComponent(slug) + '&viewing=' + encodeURIComponent(viewing) + '&asuser=<?=$user?>'
		,function(){ /* console.log(slug +' ' + viewing + ' no new comments'); */ }
		,function(txt){ 
			j = JSON.parse(txt);
			/*/ console.log("###", txt); /**/
			if ('.view' in j) {
				console.log('being viewed: '+slug+"/"+viewing);
				postpone(slug+'\n'+viewing, j['.view']);
			}
<?php if (!array_key_exists('redo', $_REQUEST)) { ?>
			if ('.grade' in j) {
				console.log('already graded: '+slug+"/"+viewing);
				skip(slug+'\n'+viewing, false);
			}
<?php } ?>
			if (j['..'] == lastCommentIndex) {
				/* console.log(slug +' ' + viewing + ' no new comments'); */
			} else {
				lastCommentIndex = j['..'];
				for(var key in j) if (key != '..') {
					_addcomment(key, j[key]);
				}
			}
		}
	);
}
/** go back to the previous student by removing one .done class marker */
function unDoneOne() {
	var v = document.querySelectorAll('table.done');
	if (v.length > 0) v[v.length-1].classList.remove('done');
}

/** Send an HTTP request asynchronously, optionally with callbacks for empty and non-empty responses */
function ajax(payload, qstring, empty=null, response=null) {

	/*/ console.log("### ajax ###", payload, qstring); /**/

	var xhr = new XMLHttpRequest();
	if (!("withCredentials" in xhr)) {
		alert('Your browser does not support TLS in XMLHttpRequests; please use a browser based on Gecko or Webkit'); return null;
	}
	xhr.open("POST", "<?=$_SERVER['SCRIPT_NAME']?>?"+qstring, true);
	xhr.setRequestHeader('Content-Type', 'application/json');
	
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
	xhr.send(JSON.stringify(payload));
}


	</script>
</head><body onload="imageresize(); highlight(); setUpCollapses(); (window.onfocus ? window.onfocus() : null);">
<?php

function gradeableTree($limit=False) {
	$ans = array();
	$everyone = fullRoster();
	foreach(assignments() as $slug => $details) {
		if ($limit && $slug != $limit) continue;
		$ct = closeTime($details);
		if ($ct == True && $ct < time() || $_SERVER['PHP_AUTH_USER'] == 'lat7h') {
			foreach(glob("uploads/$slug/*") as $dir) {
				if (file_exists("$dir/.extension") 
				&& closeTime(json_decode(file_get_contents("$dir/.extension"),true)) > time()) {
					continue;
				}
				if (count(glob("$dir/*", GLOB_NOSORT)) == 0) { continue; } // no submission
				$sid = explode('/',$dir)[2];
				if (file_exists("$dir/.partners")) {
					$lastid = True;
					foreach(explode("\n",file_get_contents("$dir/.partners")) as $pair) {
						if (strcmp($sid, $pair) > 0) $lastid = False;
					}
					if (!$lastid) continue; // duplicate with partner
				}
				$student = $everyone[$sid];
				$gid = array_key_exists('grader', $student) ? $student['grader'] : 'no grader';
				$status = file_exists("$dir/.grade") ? (file_exists("meta/requests/regrade/$slug-$sid") ? 'regrade' : 'graded') : 'ungraded';
				
				if (!array_key_exists($slug, $ans)) $ans[$slug] = array(
					'regrade'=>0,
					'graded'=>0,
					'ungraded'=>0,
					'graders'=>array(),
				);
				if ($gid != 'no grader')
					$ans[$slug][$status] += 1;

				if (!array_key_exists($gid, $ans[$slug]['graders'])) $ans[$slug]['graders'][$gid] = array(
					'regrade'=>0,
					'graded'=>0,
					'ungraded'=>0,
				);
				$ans[$slug]['graders'][$gid][$status] += 1;
			}
		}
	}
	
	return $ans;
}
function toGrade($slug, $grader, $redo) {
	$ans = array();
	if ($redo == 'regrade') { // show only open regrade requests
		foreach(glob("meta/requests/regrade/$slug-*") as $req) {
			$student = substr($req, strrpos($req, '-')+1);
			if ($grader && $grader != 'all' && fullRoster()[$student]['grader'] != $grader) continue;
			$ans[] = $student;
		}
		shuffle($ans);
	} else if ($redo == 'review') {
		if ($grader == 'all') { // show anything anyone has graded
			foreach(glob("users/.graded/*/$slug") as $req) {
				$ans = array_merge($ans, explode("\n", trim(file_get_contents($req))));
			}
			shuffle($ans);
		} else { // show only things $grader has graded
			if (file_exists("users/.graded/$grader/$slug"))
				$ans = explode("\n", trim(file_get_contents("users/.graded/$grader/$slug")));
		}
	} else if ($grader == 'all' || $grader == 'no grader') {
		foreach(glob("uploads/$slug/*") as $path) {
			if ((!$redo) == file_exists("$path/.grade")) continue;
			if (file_exists("$path/.extension") && closeTime(json_decode(file_get_contents("$path/.extension"),true)) > time()) continue;
			$student = explode('/', $path)[2];
			$them = fullRoster()[$student];
			if ($grader == 'all' || !array_key_exists('grader', $them)) $ans[] = $student;
		}
		shuffle($ans);
	} else {
		foreach(fullRoster()[$grader]['graded'] as $student) {
			$path = "uploads/$slug/$student";
			if (!file_exists($path)) continue;
			if ((!$redo) == file_exists("$path/.grade")) continue;
			if (file_exists("$path/.extension") && closeTime(json_decode(file_get_contents("$path/.extension"),true)) > time()) continue;
			$ans[] = $student;
		}
		shuffle($ans);
	}
	if (array_key_exists('limit', $_REQUEST)) $ans = array_slice($ans, 0, intval($_REQUEST['limit']));
	return $ans;
}
function showGradingView($slug, $student, $rubric, $comments, $nof='') {
	$name = fullRoster()[$student]['name'];
	echo "<table class='table-columns' id='table\n$slug\n$student'><tbody><tr><td>";
	$files = false;
	$norepeat = array();
	foreach(glob("uploads/$slug/$student/*") as $path) {
		echo studentFileTag($path);
		$norepeat[] = basename($path);
		$files = true;
	}
	$a = assignments()[$slug];
	if (array_key_exists('extends', $a)) {
		foreach($a['extends'] as $slug2) {
			foreach(glob("uploads/$slug2/$student/*") as $path) {
				if (in_array(basename($path), $norepeat)) continue;
				echo "<div style='clear:both; padding-top:2em;'>Submitted under $slug2:</div>";
				echo studentFileTag($path);
				$norepeat[] = basename($path);
				$files = true;
			}
		}
	}

	
	if (!$files) { echo studentFileTag(false); }
	echo '</td><td>';
	echo "You may <a href='download.php?file=$slug/$student'>download a .zip of submitted and tester files</a> for this student.";
	echo "<div class='student name'>";
	echo "$name ($student)";
	if (file_exists("uploads/$slug/$student/.partners")) {
		foreach(explode("\n", file_get_contents("uploads/$slug/$student/.partners")) as $other) {
			if ($other != $student) {
				echo " and " . (fullRoster()[$other]['name']) . " ($other)";
			}
		}
	}
	echo $nof;
	echo "</div>";
	
	if ($slug == "Checkpoint 1") { // HACK
		echo "<p><strong>Grading guidelines:</strong> Give 1 if they <em>either</em> describe their game idea <em>or</em> provide partial game code. Give at least 0.5 if they submitted something more than a blank file. Also give comments and feedback.</p>";
	}
	if ($slug == "Checkpoint 2") { // HACK
		echo "<p><strong>Grading guidelines:</strong> Give 1 if they submitted <q>a the basics of a working game, in <code>game.py</code>, possibly with a few <q>it crashes if you do <em>X</em>X</q>-type bugs or missing features.</q> Take off at most 0.1 for wrong file name. Give at least 0.5 if they some gamebox s.</p>";
	}
	
	$grade = array();
	if (file_exists("uploads/$slug/$student/.autofeedback")) {
		$feedback = display_grade_file("uploads/$slug/$student/.autofeedback");
		if (array_key_exists('pregrade', $feedback)) $grade['details'] = $feedback['pregrade'];
	}
	if (file_exists("uploads/$slug/$student/.grade"))
		$grade = json_decode(file_get_contents("uploads/$slug/$student/.grade"), true);

	if (array_key_exists('grader', $grade)) echo "<div class='grader name'>".fullRoster()[$grade['grader']]['name']." ($grade[grader]) <small>".prettyTime(filemtime("uploads/$slug/$student/.grade"))."</small></div>";
	
	// process regrades
	echo regradeTag($grade, true);
	
	echo rubricTree($rubric, $comments, 
		array_key_exists('details', $grade) ? $grade['details'] : array(),
		"$slug\n$student");
	
	// add an additional set of fields if this is a regrade
	
	echo "<input type='button' value='submit grade' onclick='grade(".json_encode("$slug\n$student").")'/>";
	echo "<input type='button' value='skip' onclick='skip(".json_encode("$slug\n$student").")'/>";
	
	echo '</td></tr></tbody></table>';
}


/**
 * Returns an array of students with assignments still needing to be graded.
 */
function gradeQueue($assignment, $grader, $redo=false) {
	$ans = array();
	$roster = fullRoster();
	foreach(glob("uploads/$assignment/*") as $path) {
		if (file_exists("$path/.extension") 
		&& closeTime(json_decode(file_get_contents("$path/.extension"),true)) > time()) {
			continue;
		}
		$student = explode('/', $path)[2];
		$them = $roster[$student];
		$yours = $grader == 'all' || (array_key_exists('grader', $them) ? $them['grader'] == $grader : $grader == 'no grader');
		$regrade = file_exists("meta/requests/regrade/$assignment-$student");
		$done = file_exists("$path/.grade");
		if ($redo == 'regrade') {
			if ($regrade) $ans[] = $student;
		} else if ($redo == 'review') {
			if ($yours && $done) $ans[] = $student;
		} else if ($redo == 'all') {
			if ($yours) $ans[] = $student;
		} else {
			if ($yours && (!$done || $regrade)) $ans[] = $student;
		}
	}
	return $ans;
}

function rubricTree($rubric, $comments, $grade, $prefix, $path="", $name="") {
	global $js_total;
	if ($rubric['kind'] == 'breakdown') {
		echo "<dl class='breakdown' id='$prefix$path'>";
		foreach($rubric['parts'] as $part) {
			echo "<dt><strong>$part[name]</strong> ($part[ratio])</dt><dd>";
			rubricTree(
				$part['rubric'], 
				$comments,
				array_key_exists($part['name'], $grade) ? $grade[$part['name']] : array(),
				$prefix,
				"$path\n$part[name]",
				$name ? "$name/$part[name]" : "$part[name]"
			);
			echo "</dd>";
		}
		echo "<dt style='display:none;'><em>grade multipliers</em></dt><dd style='display:none;'>";
		rubricTree(
			array('kind'=>'percentage','set'=>True),  // FIX ME: not right; a set of, not just one
			$comments,
			array_key_exists('.mult', $grade) ? $grade['.mult'] : array(),
			$prefix,
			"$path\n.mult",
			$name ? "$name/.mult" : ".mult"
		);
		echo "</dd>";
		echo '</dl>';
	} else if ($rubric['kind'] == 'percentage') {
		// if in grade but not in comments, might not show up (untested, but I don't think it will)
		if (array_key_exists('set',$rubric) && $rubric['set']) {
			echo "<div class='percentage set' id='$prefix$path' name='$name'>";
			$radio = 'checkbox';
			$check = array();
			$pref = "&times;";
			foreach($grade as $comobj) {
				$key = ''; $ex = '';
				commentSplit($comobj['comment'], $key, $ex);
				$check[round($comobj['ratio'],8)." ".$key] = $ex;
			}
		} else {
			echo "<div class='percentage' id='$prefix$path' name='$name'>";
			$radio = 'radio';
			$check = array();
			$pref = "score ";
			if (array_key_exists('comment', $grade)) {
				$key = ''; $ex = '';
				commentSplit($grade['comment'], $key, $ex);
				$check[round($grade['ratio'],8)." ".$key] = $ex;
				$asgn = assignments()[split("\n",$prefix,2)[0]];
				$show = $grade['ratio'];
				if (array_key_exists('total', $asgn)) $show *= $asgn['total'];
				echo "<div>Current score: $show</div>";
			}
		}
//		echo "$pref <input type='text' id='ratio\n$prefix$path' size='3' value='1'/>: <input type='text' id='new\n$prefix$path'/> <input type='button' value='add comment' onclick='addcomment(".json_encode("new\n$prefix$path").")'/>";
		echo "$pref <input type='text' id='ratio\n$prefix$path' size='3' value='1'/>: <textarea id='new\n$prefix$path'></textarea> <input type='button' value='add comment' onclick='addcomment(".json_encode("new\n$prefix$path").")'/>";
		$path_fix = $path ? $path : "\n"; // <-- not elegant, but works with current comment pool format
		$cset = array();
		if (array_key_exists($path_fix, $comments)) { $cset = array_merge($cset, $comments[$path_fix]); }
		if (array_key_exists('set',$rubric) && $rubric['set']) {
			$cset = array_merge($cset, $grade);
		} else if (array_key_exists('comment', $grade)) {
			$cset[] = $grade;
		}
		foreach($cset as $obj) {
			echo "<br/>";
			echo "<label><input type='$radio' name='$prefix$path' value='";
			echo htmlspecialchars(json_encode($obj), ENT_QUOTES|ENT_HTML5);
			$key = round($obj['ratio'],8)." ".$obj['comment'];
			$more = False;
			if (array_key_exists($key, $check)) { echo "' checked='checked"; $more = $check[$key]; }
			echo "'/> $pref".($obj['ratio']*$js_total).": ";
			echo htmlspecialchars($obj['comment'], ENT_QUOTES|ENT_HTML5);
			echo "</label> (<textarea rows='1'>";
			if ($more !== False) echo htmlspecialchars($more, ENT_QUOTES|ENT_HTML5);
			echo "</textarea>)";
		}
		echo '</div>';
	} else if ($rubric['kind'] == 'check') {
		echo "<div class='check' id='$prefix$path' name='$name'>";
		echo "<label><input type='radio' name='$prefix$path' value='1'";
		if ($grade >= 1) echo " checked='checked'"; 
		echo "> full</label> or ";
		echo "<label><input type='radio' name='$prefix$path' value='0.5'";
		if ($grade > 0 && $grade < 1) echo " checked='checked'"; 
		echo "> partial</label> or ";
		echo "<label><input type='radio' name='$prefix$path' value='0'";
		if ($grade <= 0) echo " checked='checked'"; 
		echo "> no</label> credit.";
		echo '</div>';
	} else if ($rubric['kind'] == 'buckets') {
		echo "<dl class='buckets' id='$prefix$path'>";
		foreach($rubric['buckets'] as $i=>$bucket) {
			$coms = array();
			if (array_key_exists("$path\n$i", $comments)) $coms = array_merge($comments["$path\n$i"], $coms);
			// extract the two parts of each comment, general and specific
			if (array_key_exists($i, $grade)) {
				$general = array();
				$specific = array();
				foreach($grade[$i] as $j=>$txt) {
					commentSplit($txt, $general[$j], $specific[$j]);
				}
				$coms = array_unique(array_merge($general, $coms), SORT_REGULAR);
			}
			natcasesort($coms);
			// the following trusts that all general comments in $grade are in $comments
			echo "<dt>$bucket[name] ($bucket[score])</dt><dd class='bucket' name='$name/$i' id='$prefix$path\n$i'>";
			foreach($coms as $j=>$txt) {
				$idx = array_key_exists($i, $grade) ? array_search($txt, $general) : False;
				echo "<p><label><input type='checkbox' name='$prefix$path\n$i' value='";
				echo htmlspecialchars($txt, ENT_QUOTES|ENT_HTML5);
				if ($idx !== False) echo "' checked='checked";
				echo "'/> ";
				echo htmlspecialchars($txt, ENT_QUOTES|ENT_HTML5);
				echo "</label> (<textarea rows='1'>";
				if ($idx !== False) echo htmlspecialchars($specific[$idx], ENT_QUOTES|ENT_HTML5);
				echo "</textarea>)";
				echo "</p>";
			}
			echo "<input type='text' id='new\n$prefix$path\n$i'/><input type='button' value='add comment' onclick='addcomment(".json_encode("new\n$prefix$path\n$i").")'/>";
			echo "</dd>";
		}
		echo '</dl>';
	} else {
		echo '<p class="error">Error! Unexpected rubric kind ';
		var_dump($rubric['kind']);
		echo '</p>';
	}
}

/*
Home: list of assignments that have open grades, and how many
Assignment: 
	If graders and you have ungraded, show grade view
	If graders and you're done, show list of other graders
	If no graders, show grade view (grader=all)
Grade:
	pre-load: all submissions, all bit first in hidden div
	submit: ajax record grade, hide graded div, unhide next div (if none left, unhide success message)
	periodically: query for new comments, or for others having graded this
Redo:
	like grade but 
	* show previous grader, time, and grade
	* may skip
	various flavors:
	* own
	* for-student
	* spot-check grader/all
Regrade:
	like grade but also 
	* show previous grader, time, and grade
	* show regrade request message 
	* add regrade comment category, with any previous regrade messages
*/


if (array_key_exists('assignment', $_REQUEST)) {
	$slug = $_REQUEST['assignment'];
	$options = gradeableTree($slug);
	if (!array_key_exists($slug, $options)) {
		?> <div class="big notice"><h1>No such assignment</h1>
		<p>Assignment <strong><?=$slug?></strong> either does not exist, is still open, or has no submissions. Try either <a href="grade.php">the index of gradable assignments</a> or <a href="index.php">the submission page</a>.</p>
		</div><?php
	} else if (array_key_exists('student', $_REQUEST)) {
		$rubric = rubricOf($slug);
		$comments = commentSet($slug);
		showGradingView($slug, $_REQUEST['student'], $rubric, $comments);
		?>
		<div id="grading-footer">
		<div class="big notice"><h1>Review done</h1><p></p></div>
		<p>Return to <a href="grade.php?assignment=<?=$slug?>">this assignment's index</a>.</p>
		</div>
		<div style="position:fixed; right:0.5ex; bottom:0.5ex; opacity:0.5;">
			Font size:
			<input type="text" size="4" onchange="document.body.style.fontSize = (/^[\s0-9]+$/.test(this.value) ? this.value+'pt' : this.value)"/>
		</div>
		<?php
		
	} else {
		$stats = $options[$slug];
		$redo = array_key_exists('redo', $_REQUEST) ? $_REQUEST['redo'] : False;
		$grader = array_key_exists('grader', $_REQUEST) ? $_REQUEST['grader'] : False;
		if (!$grader && $redo == 'regrade') $grader = 'all';

// echo '<pre>'; print_r($options); echo '</pre>';
		if (!$grader && count($stats['graders']) == 1) $grader = 'all';
		
		if ($redo == 'review' && $grader) {
			echo "<h2>For $slug, you graded (most recent first):</h2><ul class='linklist'>";
			foreach(array_reverse(toGrade($slug, $grader, $redo)) as $student) {
				$stud = fullRoster()[$student];
				echo "<li><a href='?assignment=$slug&student=$student'>$stud[name] ($student)</a>";
				if ($grader != 'all') {
					if (!array_key_exists('grader', $stud)) echo " &mdash; no grader assigned";
					else if ($stud['grader'] != $grader) echo " &mdash; in $stud[grader_name] ($stud[grader])'s grading group";
				}
				echo "</li>";
			}
			echo "</ul>";
		} else if ($grader) {
			// show this user's grading interface
			$rubric = rubricOf($slug);
			$comments = commentSet($slug);
			$alltodo = toGrade($slug, $grader, $redo);
			foreach($alltodo as $i=>$student) {
				showGradingView($slug, $student, $rubric, $comments, " ".($i+1)." of ".count($alltodo));
			}
			?>
			<div id="grading-footer">
			<div class="big success"><h1>Your Grading is Done!</h1><p>But there may be others who could use your help...</p></div>
			<p>Return to 
				<a href="index.php">the submission site</a>, 
				<a href="grade.php">the main grading site</a>, or 
				<a href="grade.php?assignment=<?=$slug?>">this assignment's index</a>;
				or refresh this page to see any submissions you skipped or that someone else was working on in this grading group but didn't finish grading.</p>
			</div>
			<div style="position:fixed; right:0.5ex; bottom:0.5ex; opacity:0.5; text-align:right;">
				<input type="button" value="view previous student" onclick="unDoneOne()"/>
				<br/>
				Font size:
				<input type="text" size="4" onchange="document.body.style.fontSize = (/^[\s0-9]+$/.test(this.value) ? this.value+'pt' : this.value)"/>
			</div>
			<?php
		} else {
			echo "<h2>Pick a grading group:</h2><ul class='linklist'>";
			// show "everyone" mop-up option
			$undone = 0;
			foreach($stats['graders'] as $grader=>$counts) if ($grader != 'no grader') $undone += $counts['ungraded'];
			if ($undone > 0) {
				echo "<li>Finish all ungraded: <a class='ungraded' href='?assignment=$slug&grader=all'>$undone</a>"; 
				if ($undone > 12) {
					echo " (or just a random <a class='ungraded' href='?assignment=$slug&grader=all&limit=12'>dozen</a>";
					if ($undone > 24) echo " or <a class='ungraded' href='?assignment=$slug&grader=all&limit=24'>two</a>";
					echo ")";
				}
				echo "</li>";
			}
			
			// show list of users
			ksort($stats['graders']);
			foreach($stats['graders'] as $grader=>$counts) {
				echo "<li>";
				echo fullRoster()[$grader]['name']; echo " ($grader):";
				if ($counts['ungraded']) echo " <a class='ungraded' href='?assignment=$slug&grader=$grader'>$counts[ungraded] ungraded</a>";
				else echo " 0 ungraded";
				if ($counts['graded']) echo " (<a href='?assignment=$slug&grader=$grader&redo=own'>$counts[graded] graded</a>)";
				if ($user == $grader && file_exists("users/.graded/$grader/$slug"))
					 echo " (<a href='?assignment=$slug&grader=$grader&redo=review'>your grading history</a>)";
				if ($counts['regrade']) echo " <a class='ungraded' href='?assignment=$slug&grader=$grader&redo=regrade'>$counts[regrade] to regrade</a>";
				echo "</li>";
			}
			echo '</ul>';
		}
	}
} else {
	// show list of assignments (might be slow?)
	$options = gradeableTree();
	echo '<h2>Pick an assignment:</h2>';
	if ($issuperuser) echo "<p>We have a prototype <a href='merge.php'>comment merge site</a> you are in the initial pilot group to use (with caution... its changes are currently irreversible)</p>";
	echo '<ul class="linklist">';
	foreach($options as $slug=>$stats) {
		if (strlen($slug) == 0 || $slug[0] == '.') continue; // just in case
		echo "<li><a href='$_SERVER[SCRIPT_NAME]?assignment=$slug'";
		if ($stats['ungraded'] > 0) echo " class='ungraded'";
		echo "><strong>$slug</strong>: $stats[graded] / ".($stats['graded'] + $stats['ungraded'])." graded</a>";
		if (array_key_exists($user, $stats['graders'])) {
			echo "; your students <a href='$_SERVER[SCRIPT_NAME]?assignment=$slug&grader=$user'";
			if ($stats['graders'][$user]['ungraded'] > 0) echo "class='ungraded'";
			echo ">".$stats['graders'][$user]['graded']." / ".($stats['graders'][$user]['graded'] + $stats['graders'][$user]['ungraded'])." graded</a>";
		}
		if ($stats['regrade'] > 0) echo "; <span class='regrades'>pending <a href='$_SERVER[SCRIPT_NAME]?assignment=$slug&redo=regrade'>regrades: $stats[regrade]</a></span>";
		if ($issuperuser) echo "; view <a href='$_SERVER[SCRIPT_NAME]?assignment=$slug&grader=all&redo=own&limit=20'>20 random submissions</a>";
		if ($stats['ungraded'] > 0 && $stats['ungraded'] < 100) {
			echo "; grade <a class='ungraded' href='?assignment=$slug&grader=all'>all $stats[ungraded] remaining</a>"; 
			if ($stats['ungraded'] > 12) {
				echo " (or just a random <a class='ungraded' href='?assignment=$slug&grader=all&limit=12'>dozen</a>";
				if ($stats['ungraded'] > 24) echo " or <a class='ungraded' href='?assignment=$slug&grader=all&limit=24'>two</a>";
				echo ")";
			}
			echo "</li>";
		}
		echo "</li>";
	}
	echo '</ul>';
}
?>
</body>
</html>



