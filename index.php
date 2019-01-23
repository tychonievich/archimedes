<?php header('Content-Type: text/html; charset=utf-8'); ?>﻿<!DOCTYPE html>
<html><head>
	<title>Archimedes Submission Server</title>
	<style>
		html { background:grey; }
		body { font-family: sans-serif; 
			max-width:50em; display:table; margin:auto; 
			border-radius:1em; border:thick solid grey; padding:1ex; 
			background: white; 
			}
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
		
		pre.rawtext, pre.feedback, pre.regrade-request, pre.regrade-response { white-space:pre-wrap; background: white; padding:0.5ex; }
		pre.regrade-request:before { content:"You: "; font-weight:bold; }
		pre.regrade-response:before { content:"Regrader: "; font-weight:bold; }
		
		li, dt, dd { margin: 0em; }
		ul { padding-left: 1em; }
		dl, ul, div.percentage, div.extra { margin:0em 0em 0em 1em; }
		div.percentage, dl.buckets, dl.breakdown, div.extra { 
			border-left: thin solid rgba(0,0,0,0.25); padding-left:1ex; 
		}
		
		.advice { font-style: italic; opacity:0.5;}

		table.nopad { border-collapse: collapse; }
		table.nopad, .nopad tr, .nopad td { border: none; padding:0em; margin:0em; }
		.xp-bar { width: 100%; padding: 0em; white-space: pre; border: thin solid black; line-height:0%;}
		.xp-bar span { height: 1em; padding:0em; margin: 0em; border: none; display:inline-block; }
		.xp-earned { background: rgba(0,191,0,1); }
		.xp-missed { background: rgba(255,127,0,0.5); }
		.xp-future { background: rgba(0,0,0,0.125); }
		
		.snapshot { max-width: 50%; display: table; margin: 1ex auto; box-shadow: 0ex 0ex 1ex 0ex rgba(0,0,0,0.25); text-align: center; color: rgba(0,0,0,0.5);}
		
		.panel { font-size:66.66666%; margin: 1ex; padding:1ex; border: thin solid; border-radius:2ex; background-color:white; max-width:100%; }
		pre.highlighted { border: thin solid #f0f0f0; white-space:pre-wrap; padding-right: 1ex; max-width:calc(100%-2ex); margin:0em; }
		.highlighted .lineno { background:#f0f0f0; padding:0ex 1ex; color:#999999; font-weight:normal; font-style:normal; }
		.highlighted .comment { font-style: italic; color:#808080; }
		.highlighted .string { font-weight:bold; color: #008000; }
		.highlighted .number { color: #0000ff; }
		.highlighted .keyword { font-weight: bold; color: #000080; }

		
		.person { border: thin solid white; background-color: rgba(255,255,255,0.5); padding:0.25ex; }

		dd table { border-collapse: collapse; width:100%; }
		dd table td, dd table th { padding: 1ex; text-align: left; }
		dd table tr:nth-child(2n) { background-color:rgba(255,127,0,0.125); }

		dd.collapsed { display:none; }
		dt.collapsed:before { content: "+ "; }
		dt.collapsed:after { content: " …"; }
		dt.collapsed { font-style: italic; }

		.check.correct { background-color:rgba(0,255,0,0.25); }
		.check.wrong { background-color:rgba(255,0,0,0.25); }
		.check.partial { background-color:rgba(255,255,0,0.25); }
		
		blockquote { border:1px solid rgba(0,0,0,0.125); padding:1ex; border-radius:1ex; background-color:rgba(0,0,0,0.04); margin:1em 4em; }
		
		div.grade + div { white-space: pre-wrap; }

		
		/* input, select, option { font-size:100%; } */
	</style>
	<script src="codebox.js"></script>
	<script>//<!--
/**
 * 
 */
function sensibleDateFormat(d) {
	// toLocaleFormat has been deprecated and removed from some browsers, so do this manually
	var s = d.toString().split(' '); // for day-of-week and timezone-specific time
	var dow = s[0];
	var tz = s[s.length-1];
	var date = String(d.getFullYear()).padStart(4,'0')+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
	// var time = d.toTimeString().substr(0,5);
	if (d.getHours() > 12) { var time = (d.getHours()-12) + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + ' pm'; }
	else { var time = d.getHours() + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + (d.getHours() == 12 ? ' pm' : ' am'); }
	if (time == '0 am') return dow + ' ' + date;
	return dow + ' ' + date + ' ' + time;// + ' ' + tz;
}

function reformat(el) {
	var dt = new Date(Number(el.getAttribute('ts')+'000'));
	var now = new Date();
	var ms = dt - now; // + if future, - if past
	var as = Math.abs(Math.round(ms/1000));
	var dayDiff = new Date(now.toDateString()) - new Date(dt.toDateString());
	dayDiff /= 24*60*60*1000;
	var relative = '';
	if (as < 8*60*60) {
		if (as < 60*60) {
			var m = Math.floor(as/60);
			relative = m + ' minute' + (m == 1 ? '' : 's') + (ms < 0 ? ' ago' : ' from now');
		} else {
			var h = Math.floor(as/3600);
			var m = Math.floor((as/60)%60);
			// var s = Math.floor(as%60);
			relative = h + ':' + (m < 10 ? '0'+m : m) + /*':' + (s < 10 ? '0'+s : s) +*/ (ms < 0 ? ' ago' : ' from now');
		}
	} else if (dayDiff == 0) { // today in local timezone
		if (ms < 0) relative = 'earlier ';
		else relative = 'later ';
		if (dt.getHours() < 12) relative += 'this morning';
		else if (dt.getHours() < 17) relative += 'this afternoon';
		else if (dt.getHours() < 20) relative += 'this evening';
		else relative += 'tonight';
	} else if (Math.abs(dayDiff) == 1) {
		if (ms < 0) relative = 'yesterday';
		else relative = 'tomorrow';
		if (dt.getHours() < 12) relative += ' morning';
		else if (dt.getHours() < 17) relative += ' afternoon';
		else if (dt.getHours() < 20) relative += ' evening';
		else relative += ' night';
		relative = relative.replace('yesterday night', 'last night');
	} else if (Math.abs(dayDiff) < 7) {
		if (ms < 0) relative = 'last ';
		relative += ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][dt.getDay()];
		if (dt.getHours() < 12) relative += ' morning';
		else if (dt.getHours() < 17) relative += ' afternoon';
		else if (dt.getHours() < 20) relative += ' evening';
		else relative += ' night';
	} else {
		var w = Math.round(as/(7*24*60*60));
		relative = w + ' week' + (w == 1 ? '' : 's') + (ms < 0 ? ' ago' : ' from now');
	}
	el.innerHTML = sensibleDateFormat(dt) + ' (' + relative + ')';
}

function dotimes() {
	function uclock() {
		var fixers = document.getElementsByClassName('datetime');
		for(var i=0; i<fixers.length; i+=1) {
			reformat(fixers[i]);
		}
	}
	uclock();
	window.setInterval(uclock, 60*1000);
}

function docollapse() {
	// something here does not work on safari, but I can't seem to figure out what
	var hiders = document.getElementsByClassName('hide-outer');
	for(var i=0; i<hiders.length; i+=1) {
		hiders[i].firstElementChild.onclick = function(e) {
			var parent = this.parentElement;
			if (parent.classList.contains('hidden')) {
				parent.classList.remove('hidden');
				parent.classList.add('shown');
			} else {
				parent.classList.remove('shown');
				parent.classList.add('hidden');
			}
		}
	}
	var breakdowns = document.querySelectorAll('table + dl dt');
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
		breakdowns[i].nextElementSibling.classList.add('collapsed');
		breakdowns[i].classList.add('collapsed');
	}
}
	//--></script>
</head><body onload="dotimes(); docollapse(); highlight();">
<?php

include "tools.php";
logInAs();






// If staff have uploaded a roster, accept it
if ($isstaff && array_key_exists('roster', $_FILES) && file_exists($_FILES['roster']['tmp_name'])) {
	preFeedback("Processing ".$_FILES['roster']['name']."...");
	$t1 = microtime(true);
	$remove = array_key_exists('remove', $_POST) && $_POST['remove'] != 'off';
	$dropWaiters = array_key_exists('nowaiters', $_POST) && $_POST['nowaiters'] != 'off';
	updateRosterSpreadsheet($_FILES['roster'], $remove, !$dropWaiters);
	$t2 = microtime(true);
	preFeedback("...done in ".($t2-$t1)." seconds");
	unset($_FILES['roster']);
}
// If staff have uploaded a photo archive, accept it
if ($isstaff && array_key_exists('photo_archive', $_FILES) && file_exists($_FILES['photo_archive']['tmp_name'])) {
	preFeedback("Processing ".$_FILES['photo_archive']['name']."...");
	$t1 = microtime(true);
	try {
		$zip = new ZipArchive;
		$zip->open($_FILES['photo_archive']['tmp_name'], ZipArchive::CHECKCONS);
		for($i=0; $i<$zip->numFiles; $i+=1) {
			$name = $zip->getNameIndex($i);
			$user = $name;
			if (strrpos($user, '.') !== FALSE) $user = substr($user, 0, strrpos($user, '.'));
			if (array_key_exists($user, fullRoster())) {
				file_put_contents("users/$name", $zip->getFromIndex($i));
				chmod("users/$name", 0666);
				preFeedback("Added or updated $user");
			} else {
				preFeedback("skipping $name (not enrolled)");
			}
		}
		$zip->close();
	} catch (Exception $e) {
		preFeedback("Not a properly formatted .zip file, or server permission error.");
	}
	// ...
	$t2 = microtime(true);
	preFeedback("...done in ".($t2-$t1)." seconds");
	unset($_FILES['photo_archive']);
}

// If faculty and sent extension decision, accept it
if ($isfaculty && array_key_exists('extension_decision', $_POST)) {
	if (!array_key_exists('extension_student', $_POST) 
	|| !array_key_exists($_POST['extension_student'], fullRoster())) {
		preFeedback("Invalid extension request: no such student");
	} else if (!array_key_exists('extension_assignment', $_POST) 
	|| !array_key_exists($_POST['extension_assignment'], assignments())) {
		preFeedback("Invalid extension request: no such assignment");
	} else if (($_POST['extension_decision'] == 'Approve') && (
		!array_key_exists('due', $_POST)
		|| strtotime($_POST['due']) === False) && (
		!array_key_exists('late', $_POST)
		|| !is_array(json_decode($_POST['late'], true)))) {
		preFeedback("Invalid extension: approved, but neither late policy nor deadline changed");
	} else if (($_POST['extension_decision'] != 'Approve') && (
		!array_key_exists('rejection', $_POST)
		|| strlen($_POST['rejection']) < 6
	)) {
		preFeedback("Invalid extension: missing rejection reason");
	} else {
		preFeedback("Processing extension request $_POST[extension_assignment]/$_POST[extension_student] (decision was $_POST[extension_decision])");
		$gradefile = "uploads/$_POST[extension_assignment]/$_POST[extension_student]/.grade";
		$extendfile = "uploads/$_POST[extension_assignment]/$_POST[extension_student]/.extension";
		if ($_POST['extension_decision'] == 'Approve') {
			if (stripos($_POST['due'], 'now') !== False) {
				preFeedback("Note: relative times may have a timezone-sized offset incorrectly applied...");
			}
			$object = array();
			if (array_key_exists('due', $_POST) && strtotime($_POST['due']) !== False)
				$object['due'] = date('Y-m-d H:i', strtotime($_POST['due']. " America/New_York"));
			if (array_key_exists('late', $_POST) && is_array(json_decode($_POST['late'], true)))
				$object['late-policy'] = json_decode($_POST['late'], true);
			$object['close'] = closeTime($object); // needed to overwrite optional close in assignment itself
			if (!file_put($extendfile, json_encode($object))) preFeedback("Failed to write .extension file");
			else {
				preFeedback("Recorded new deadline of $object[due] for $_POST[extension_assignment]/$_POST[extension_student]");
				if (file_exists("meta/requests/extension/$_POST[extension_assignment]"."-"."$_POST[extension_student]"))
					unlink("meta/requests/extension/$_POST[extension_assignment]"."-"."$_POST[extension_student]");
				if (file_exists($gradefile)) unlink($gradefile);
			}
		} else {
			$gradefile = "uploads/$_POST[extension_assignment]/$_POST[extension_student]/.grade";
			if (file_exists($gradefile)) { $grade = json_decode(file_get_contents($gradefile), true); }
			else $grade = array(
				'student'=>$_POST['extension_student'],
				'slug'=>$_POST['extension_assignment'],
				'grade'=>0,
			);
			$grade['grader'] = $user;
			// FIX ME: change comments to something else
			if (!array_key_exists('comments', $grade)) $grade['comments'] = array();
			if (!array_key_exists('extension', $grade['comments'])) $grade['comments']['extension'] = array();
			$grade['comments']['extension'][] = 'Extension request denied; faculty comment: <q>'.htmlspecialchars($_POST['rejection']).'</q>';
			if (!file_put($gradefile, json_encode($grade))) preFeedback("Failed to put decision into .grade");
			else if (!file_append("uploads/$_POST[extension_assignment]/.gradelog", json_encode($grade)."\n")) preFeedback("Failed to put decision into .gradelog");
			else {
				preFeedback("Recorded rejection of extension request for $_POST[extension_assignment]/$_POST[extension_student]");
				if (file_exists("meta/requests/extension/$_POST[extension_assignment]"."-"."$_POST[extension_student]"))
					unlink("meta/requests/extension/$_POST[extension_assignment]"."-"."$_POST[extension_student]");
				if (file_exists($extendfile)) unlink($extendfile);
			}
		}
	}
}
// If faculty and sent exemption decision, accept it
if ($isfaculty && array_key_exists('exemption_decision', $_POST)) {
	if (!array_key_exists('exemption_student', $_POST) 
	|| !array_key_exists($_POST['exemption_student'], fullRoster())) {
		preFeedback("Invalid exemption request: no such student");
	} else if (!array_key_exists('exemption_assignment', $_POST) 
	|| !array_key_exists($_POST['exemption_assignment'], assignments())) {
		preFeedback("Invalid exemption request: no such assignment");
	} else {
		preFeedback("Processing exemption request $_POST[exemption_assignment]/$_POST[exemption_student] (decision was $_POST[exemption_decision])");
		$exemptfile = "uploads/$_POST[exemption_assignment]/$_POST[exemption_student]/.excused";
		if ($_POST['exemption_decision'] == 'Approve') {
			file_append($exemptfile, "Excused over by web interface at ".date('Y-m-d H:i')." by $me[name] ($user)\n");
		} else {
			if (file_exists($exemptfile)) unlink($exemptfile); // FIX ME: leaves no log of exemption ever being attempted
		}
	}
}


if (array_key_exists('partner_imbalance', $_POST)) {
	if (array_key_exists('project_1', $_POST) && file_exists("uploads/Project/$_POST[project_1]")) {
		if (is_numeric($_POST['mult_1']) && strlen($_POST['reason_1']) > 1) {
			file_put_contents(
				"uploads/Project/$_POST[project_1]/.adjustment",
				json_encode(array("mult"=>floatval($_POST['mult_1']),"comments"=>$_POST['reason_1']))
			);
			preFeedback("Adjusted ".$_POST['project_1']." by ".$_POST['mult_1']);
		} else
			preFeedback("no feedback for primary partner $_POST[project_1]");
		if (is_numeric($_POST['mult_2']) && strlen($_POST['reason_2']) > 1) {
			if (file_exists("uploads/Project/$_POST[project_1]/.partners")) {
				$parts = explode("\n", file_get_contents("uploads/Project/$_POST[project_1]/.partners"));
				if (count($parts) != 1 || $parts[0] == "$_POST[project_1]")
					preFeedback("ERROR: ambiguous partner for $_POST[project_1]; please specify explicitly");
				else {
					file_put_contents(
						"uploads/Project/$parts[0]/.adjustment",
						json_encode(array("mult"=>floatval($_POST['mult_2']),"comments"=>$_POST['reason_2']))
					);
					preFeedback("Adjusted ".$parts[0]." by ".$_POST['mult_2']);
				}
			} else 
				preFeedback("ERROR: no partner of $_POST[project_1] found");
		} else
			preFeedback("no feedback for partner of $_POST[project_1]");
	}
}


// TO DO: process other uploads, like assignments.json



leavePre();



// $isself = true; $isstaff = false; // try real user view

// show welcome line, which also helps TAs know if they are not logged in as themselves
echo "<h1>".($isself ? "Welcome," : "Viewing as")." <span class='name'>$me[name]</span> ($user)</h1><a style='text-align:center; display:block;' href='//www.cs.virginia.edu/luther/COA1/F2018/'>Return to course page</a>\n";

if (!$isself) {
	echo "<img src='picture.php?user=$user' alt='no picture of $user found' class='snapshot'/>";
}

// handle user-level uploads and requests
if (array_key_exists('extension_request', $_POST) && (!array_key_exists('submission', $_FILES) || count($_FILES['submission']) == 0)) {
	if (!array_key_exists('slug', $_POST)) {
		user_error_msg("Received extension request without an associated assignment, which cannot be processed by this site. Please email your professor directly.");
	} else {
		if (file_exists("meta/requests/extension/$_POST[slug]-$user")) {
			user_notice_msg("New extension request replacing old for $_POST[slug].");
		}
		if (!file_put("meta/requests/extension/$_POST[slug]-$user", $_POST['extension_request'])) {
			user_error_msg("Internal server error prevented request from being posted. Please email your professor directly.");
		} else {
			user_success_msg("Extension request for <strong>$_POST[slug]</strong> posted; it will be reviewed and either your deadlines will change on this site or notice of non-extension will be posted as a grade comment under <strong>$_POST[slug]</strong> below. In most cases you will receive no notice of a decision other than a change to this site.");
		}
	}
} // end extension request posting
else if (array_key_exists("make_live", $_POST)) {
	if (!preg_match('@^uploads/[^/]+/'.$user.'/.2[^/]*/[^/]*$@', $_POST['make_live'])) {
		user_error_msg("Received roll-back request for a different user.");
	} else if (!file_exists($_POST['make_live'])) {
		user_error_msg("Received roll-back request to a non-existent file.");
	} else {
		$fname = basename($_POST['make_live']);
		$dname = dirname(dirname($_POST['make_live']));
		$slug = basename(dirname($dname));
		if (file_exists("$dname/$fname")) unlink("$dname/$fname");
		if (file_exists("$dname/.autofeedback")) unlink("$dname/.autofeedback");
		link($_POST['make_live'], "$dname/$fname");
		ensure_file("meta/queued/$slug-$user");
		if (file_exists("$dname/.grade")) {
			user_success_msg("roll-back completed: <tt>$dname/$fname</tt> now aliases <tt>$_POST[make_live]</tt>, and the autograder has been queued to review <tt>meta/queued/$slug-$user</tt>. Note, however, that this was previous graded; we advise <a href='grade.php?student=$user&assignment=$slug'>manually regrading</a>.");
		} else {
			user_success_msg("roll-back completed: <tt>$dname/$fname</tt> now aliases <tt>$_POST[make_live]</tt>, and the autograder has been queued to review <tt>meta/queued/$slug-$user</tt>");
		}
	}
} // end roll-back posting
else if (array_key_exists('regrade_request', $_POST) && (!array_key_exists('submission', $_FILES) || count($_FILES['submission']) == 0)) {
	if (!array_key_exists('slug', $_POST)) {
		user_error_msg("Received regrade request without an associated assignment, which shouldn't be possible; please email your professor, describing what you did to get this message, to report this bug.");
	} else if (strlen($_POST['regrade_request']) < 15) {
		user_error_msg("Regrade requests should include a description of why they are being requested (i.e., how the original grade was incorrect).");
	} else {
		if (file_exists("meta/requests/regrade/$_POST[slug]-$user")) {
			user_notice_msg("New regrade request replacing old for $_POST[slug].");
		}
		if (!file_put("meta/requests/regrade/$_POST[slug]-$user", $_POST['regrade_request'])) {
			user_error_msg("Internal server error prevented request from being posted. Please email your professor directly.");
		} else {
			user_success_msg("Regrade request for <strong>$_POST[slug]</strong> posted; it will be reviewed and a response posted as part of the grade view under <strong>$_POST[slug]</strong> below. In most cases you will receive no notice of a decision other than a change posted there.");
		}
	}
} // end regrade request posting
else if (array_key_exists('submission', $_FILES)) {
	if (count($_FILES['submission']['error']) == 1 && $_FILES['submission']['error'] == UPLOAD_ERR_NO_FILE) {
		user_error_msg("Upload action received, but no file was sent by your browser. Please try again.");
	} else if (!array_key_exists('slug', $_POST)) {
		user_error_msg("Received file upload without an associated assignment, which shouldn't be possible; please email your professor, describing what you did to get this message, to report this bug.");
	} else {
		$slug = $_POST['slug'];
		$details = assignments();
		if (array_key_exists($slug, $details)) {
			$details = $details[$slug];
			// handle extensions, if any
			if (file_exists("uploads/$slug/$user/.extension")) {
				$extension = json_decode(file_get_contents("uploads/$slug/$user/.extension"), true);
				$details = $extension + $details;
			}
			if (array_key_exists('files', $details)) {
				if (assignmentTime('open', $details) > time() && !($isstaff && $isself)) {
					user_error_msg("Tried to upload files for <strong>$slug</strong>, which is not yet open.");
				} else if (closeTime($details) < time() && !($isstaff && $isself)) {
					user_error_msg("Tried to upload files for <strong>$slug</strong>, which has already closed.");
					//if ($_SERVER['PHP_AUTH_USER'] == 'lat7h') { echo "<pre>"; var_dump($details); var_dump(closeTime($details)); var_dump(time()); echo "</pre>"; }
				} else {
					$now = date_format(date_create(), "Ymd-His");
					$realdir = "uploads/$slug/$user/.$now/";
					$linkdir = "uploads/$slug/$user/";
					foreach($_FILES['submission']['name'] as $i=>$fname) {
						$name = $_FILES['submission']['name'][$i];
						$error = $_FILES['submission']['error'][$i];
						$tmp = $_FILES['submission']['tmp_name'][$i];
						if ($error == UPLOAD_ERR_INI_SIZE) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt> because it was larger than the server is set up to accept.");
							continue;
						}
						if ($error == UPLOAD_ERR_FORM_SIZE) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt> because it was larger than this site is set up to accept.");
							continue;
						}
						if ($error == UPLOAD_ERR_PARTIAL) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt>; only part of the file was received. Please try again.");
							continue;
						}
						if ($error == UPLOAD_ERR_NO_FILE) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt>; the file was not sent by your browser. Please try again.");
							continue;
						}
						if ($error > UPLOAD_ERR_NO_FILE) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt> because of an unexpected problem (upload error number $error). Please report this by email to your professor, attaching the file you attempted to submit to your email.");
							continue;
						}
						$isok = is_string($details['files']);
						if (!$isok) foreach($details['files'] as $pattern) {
							if (fnmatch($pattern, $name, FNM_PERIOD)) $isok = True;
						} else {
							$isok = fnmatch($details['files'], $name, FNM_PERIOD);
						}
						if (!$isok || strpos($name, '/') !== FALSE || strpos($name, "\\") !== FALSE) {
							user_error_msg("File <tt>".htmlspecialchars($name)."</tt> rejected because it is not one of the file names accepted for <strong>$slug</strong>.");
							continue;
						}
						if (filesize($tmp) <= 0) {
							user_error_msg("File <tt>".htmlspecialchars($name)."</tt> rejected because the file you upload was empty.");
							continue;
						}
						if (!file_move($realdir . $name, $tmp)) {
							user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt> because of an unexpected problem (might be cause by uploading twice in one second?).");
							continue;
						}
						if (file_exists($linkdir . $name)) {
							rename($linkdir . $name, $linkdir . 'backup-' . $name);
						}
						if (!link($realdir . $name, $linkdir . $name)) {
							user_error_msg("Received <tt>".htmlspecialchars($name)."</tt> but failed to put it into the right location to be tested (not sure why; please report this to your professor).");
							continue;
						}
						user_success_msg("Received <tt>".htmlspecialchars($name)."</tt> for <strong>$slug</strong>. File contents as uploaded shown below:" . studentFileTag("uploads/$slug/$user/$name"));
						
						if (file_exists($linkdir . '.partners')) { // copies to all partner directories too
							foreach(explode("\n",trim(file_get_contents($linkdir . '.partners'))) as $u2) {
								if (strlen($u2) > 3) {
									$into = "uploads/$slug/$u2/";
									if (!file_exists($into)) { umask(0); mkdir($into, 0777, true); }
									if (file_exists($into.$name)) { unlink($into.$name); }
									link($realdir . $name, $into . $name);
								}
							}
						}
						
						if (file_exists($linkdir . '.grade')) unlink($linkdir . '.grade');
						if (file_exists($linkdir . '.autofeedback')) unlink($linkdir . '.autofeedback');
						if (!ensure_file("meta/queued/$slug-$user")) {
							user_notice_msg("Failed to queue <tt>".htmlspecialchars($name)."</tt> for automated feedback (not sure why; please report this to your professor).");
							continue;
						}
						if (file_exists($linkdir . 'backup-' . $name)) {
							unlink($linkdir . 'backup-' . $name);
						}
					}
				}
			} else {
				user_error_msg("Tried to uploaded files for <strong>$slug</strong>, which does not accept uploads.");
			}
		} else {
			user_error_msg("Tried to uploaded files for <strong>".htmlspecialchars($slug)."</strong>, which is not a valid assignment name.");
		}
	}
} // end submission posting
else if (array_key_exists('CONTENT_LENGTH', $_SERVER) && floatval($_SERVER['CONTENT_LENGTH']) > (1<<27)) {
	user_error_msg("You appear to have attempted to send some very large file, which our server's security settings caused us not to receive.");
}
else if ($_GET['submitted']) {
	user_error_msg("You appear to have attempted to submit something for ".$_GET['submitted']." but no file arrived. This sometimes happens if you had the submission page open for an extended time before attempting to submit; please try submitting again.");
}


// display extra information, if applicable
if (array_key_exists('groups', $me) && !($isstaff && $isself)) echo "<p>We list you as part of: $me[groups]</p>\n";
if (array_key_exists('grader_name', $me)) echo "<p>Your primary grader is $me[grader_name].</p>\n";




// if a TA, show other options (roster, asuser, grade, etc)
if (!$isself) {
	?><div class="action"><form action='<?=$_SERVER['SCRIPT_NAME']?>' method='get'>
	You are logged in as someone else; <input type='submit' value="Resume own identity"/>
	</form></div><?php
}
	
if ($isstaff) {
	$regrades = array();
	foreach(glob("meta/requests/regrade/*") as $request_path) {
		$request = basename($request_path);
		$i = strrpos($request, '-');
		$assignment_name = substr($request, 0, $i);
		$student_id = substr($request, $i+1);
		if (!array_key_exists($assignment_name, $regrades)) $regrades[$assignment_name] = array();
		$regrades[$assignment_name][] = $student_id;
	}
	foreach($regrades as $assignment_name => $student_ids) {
		$n = count($student_ids);
		$s = $n == 1 ? '' : 's';
		echo "<div class='hide-outer hidden'><strong class='hide-header'>$n regrade$s for $assignment_name</strong><div class='hide-inner'>\n";
		echo "Visit <a href='grade.php?assignment=$assignment_name&redo=regrade'>the grading site</a> to regrade all or click links below:<ul>";
		foreach($student_ids as $i=>$sid) {
			echo '<li>';
			$grader = rosterEntry($sid); if (array_key_exists('grader', $grader)) $grader = $grader['grader']; else $grader = 'no grader';
			//if ($i > 0) echo ", ";
			echo "<a href='grade.php?assignment=$assignment_name&redo=regrade&student=$sid'>$sid</a> (grader <a href='grade.php?assignment=$assignment_name&redo=regrade&grader=$grader'>$grader</a>)</li>";
		}
		echo "</ul></div></div>\n";
	}
	
	?>
	<div class="action"><form action='<?=$_SERVER['SCRIPT_NAME']?>' method='get'>
	Log in as <?=userDropdown('asuser')?>
	<input type='submit' value="Change Identity"/>
	</form></div>
	<div class="action">
	Visit the <a href="gradegroup.php">grading group site</a>,
	the <a href="partners.php">project partner site</a>,
	or the <a href="grade.php">the grading site</a>.
	</div>
	<?php if ($isself) { ?><div class="action">See a <a href="tafeedback.php">snapshot of student feedback</a> about your office hour help as of <?=prettyTime( filemtime('meta/ta_feedback.json'))?>.</div><?php } ?>
	
	<?php
}
if ($isfaculty) {
	$extensions = array();
	foreach(glob("meta/requests/extension/*") as $request_path) {
		$request = basename($request_path);
		$i = strrpos($request, '-');
		$assignment_name = substr($request, 0, $i);
		$student_id = substr($request, $i+1);
		if (!array_key_exists($student_id, $extensions)) $extensions[$student_id] = array();
		$extensions[$student_id][$assignment_name] = file_get_contents($request_path);
	}
	$n = count($extensions);
	if ($n > 0) {
		echo "<div class='hide-outer hidden important'><strong class='hide-header'>$n pending extension requests</strong><div class='hide-inner'>\n";
		echo "<dl>\n";
		foreach($extensions as $student_id => $requests) {
			$student_name = fullRoster()[$student_id]['name'];
			echo "<dt>$student_name ($student_id)</dt><dd><dl>";
			foreach($requests as $assignment_name => $text) {
				echo "<dt>$assignment_name</dt><dd><pre class='rawtext'>";
				echo htmlspecialchars($text);
				echo "</pre>";
				echo "<form action='$_SERVER[REQUEST_URI]' method='post'>";
				echo "<input type='hidden' name='extension_student' value='$student_id'/>";
				echo "<input type='hidden' name='extension_assignment' value='$assignment_name'/>";
				?>
				<p>
					Rejection reason: <input type='text' name='rejection'/>
					<input type='submit' name='extension_decision' value="Deny"/>
				</p><p>
					New due date+time <input type='text' name='due'/>
					and late policy (JSON array of ratios of points per day late, like </code>[0.9, 0.8]</code>): <input type='text' name='late'/>
					<input type='submit' name='extension_decision' value="Approve"/>
				</p><hr/>
				</form>
				<?php
				echo "</dd>";
			}
			echo "</dl></dd>";
		}
		echo "</dl>\n";
		echo "</div></div>\n";
	}
	?>
	<div class="action"><form action='<?=$_SERVER['REQUEST_URI']?>' method='post' enctype="multipart/form-data">
	Upload <label> new roster spreadsheet:
	<input type="file" name="roster"/></label>
	<br/><label>and remove users not in sheet: <input type="checkbox" name="remove"/></label>
	<br/><label>and skip Waitlisted Student roles: <input type="checkbox" name="nowaiters"/></label>
	<input type="submit"/>
	</form></div>

	<div class="action"><form action='<?=$_SERVER['REQUEST_URI']?>' method='post' enctype="multipart/form-data">
	Upload <label> student (and staff) photos (.zip):
	<input type="file" name="photo_archive"/></label>
	<input type="submit"/>
	<br/>(You can use <a href="download.php?file=support/collab_photo.py">collab_photo.py</a> to create this archive)
	</form></div>

	<div class="action"><form action='<?=$_SERVER['REQUEST_URI']?>' method='post'>
	Change the due date+time <input type='text' name='due' title='Example: 2018-02-23 10:00'/><br/>
	and late policy (array of points off per day late) <input type='text' name='late' value='' title='Example: [0.9, 0.8]'/><br/>
	of assignment <?=assignmentDropdown('extension_assignment')?><br/>
	for student <?=studentDropdown('extension_student')?>
	<input type='submit' name='extension_decision' value="Approve"/>
	</form></div>

	<div class="action"><form action='<?=$_SERVER['REQUEST_URI']?>' method='post'>
	Excuse assignment <?=assignmentDropdown('exemption_assignment')?><br/>
	for student <?=studentDropdown('exemption_student')?>
	<input type='submit' name='exemption_decision' value="Approve"/>
	</form></div>
	
	<div class="action">
		<a href="gradesheet.php">View all students grades</a> (takes about 10 seconds to generate report; be patient)
	</div>
	
	<div class="action">
		<datalist id='project_reasons'>
			<option>Parter evals, staff observation, and/or code authorship algorithms suggest you did almost all of the work</option>
			<option>Parter evals, staff observation, and/or code authorship algorithms suggest you did most of the work</option>
			<option>Parter evals, staff observation, and/or code authorship algorithms suggest you did little of the work</option>
			<option>Parter evals, staff observation, and/or code authorship algorithms suggest you did almost none of the work</option>
		</datalist>
		<form action='<?=$_SERVER['REQUEST_URI']?>' method='post'>
		Give <?=studentDropdown('project_1')?>
		a multiplier of &times;<input type='text' name='mult_1' value=''/>
		with explanation <input type='text' name='reason_1' value='' list='project_reasons'/><br/>
		Give their partner a multiplier of &times;<input type='text' name='mult_2' value=''/>
		with explanation <input type='text' name='reason_2' value='' list='project_reasons'/><br/>
		<input type='submit' name='partner_imbalance' value="Adjust grades"/>
	</form>
	</div>
	
	<?php
}

echo "<div class='hide-outer hidden'><strong class='hide-header'>Grade in course</strong><div class='hide-inner'>\n";
echo "<div>Note: assignments start showing up in the denominator of the grade computation when they come due, even if not yet graded. this may cause grades to look artificially low between due date and grading date.</div>";
echo grade_in_course($user);
echo "</div></div>\n";

// show assignments
foreach(assignments() as $slug=>$details) {
	
	// handle extensions, if any
	if (file_exists("uploads/$slug/$user/.extension")) {
		$extension = json_decode(file_get_contents("uploads/$slug/$user/.extension"), true);
		$details = $extension + $details;
	}
	
	// parse dates
	$open = assignmentTime('open', $details);
	$due = assignmentTime('due', $details);
	$close = closeTime($details);
	$notmine = !applies_to($me, $details) || file_exists("uploads/$slug/$user/.excused");
	if ($slug == 'Lab03') $notmine = false;

	
	// determine status and begin displaying assignment
	$status = ((!$due || $open > time()) ? "pending" : ($due > time() ? "open" : ($close > time() ? "late" : "closed")));
	if ($notmine) echo "<div class='assignment pending'>non-credit exercise: ";
	else echo "<div class='assignment $status'>";
	if (array_key_exists('link', $details))
		echo "<a href='$details[link]'>";
	else if (array_key_exists('writeup', $details))
		echo "<a href='//www.cs.virginia.edu/luther/COA1/F2018/$details[writeup]'>";
	else if (/*($notmine || $isstaff && $isself || $status != "pending") &&*/ array_key_exists('title', $details)) 
		echo "<a href='//www.cs.virginia.edu/luther/COA1/F2018/".strtolower("$slug-$details[title]").".html'>";
	echo "<strong>$slug</strong> ";
	if (array_key_exists('writeup', $details))
		echo "</a>";
	else if (/*($notmine || $isstaff && $isself || $status != "pending") &&*/ array_key_exists('title', $details)) 
		echo "$details[title]</a> ";
	if ($notmine) {}
	else if ($status == 'pending') echo "opens ".prettyTime($open)."\n";
	else if ($status == 'closed') echo "has closed; it was due ".prettyTime($due)."\n";
	else if ($status == 'late') echo "was due ".prettyTime($due)."; submitting now will incur late penalties.\n";
	else echo "is due ".prettyTime($due)."\n";
	
	// pending assignments have no other content, unless staff viewing as staff
	// (note: .extension files can change pending status for individual users)
	if ($notmine || $status == 'pending' && !($isstaff && $isself)) { echo '</div>'; continue; }
	
	// collect a set of possible user actions for later use in form
	$latesubmit = False;
	$regrade = False;
	$upload = (($status == 'open') || ($status == 'late')) || ($isstaff && $isself);
	$haveuploaded = False;


	// list latest submission, or assert no submission made
	echo '<div class="submissions">';
	$submitted = array();
	foreach(glob("uploads/$slug/$user/*") as $path) {
		$submitted[basename($path)] = '<a title="'.basename($path).'" href="download.php?file='.rawurlencode(explode('/',$path,2)[1]).'">'.basename($path).'</a> '.prettyTime(filemtime($path));
		$haveuploaded = True;
	}
	if (array_key_exists('extends', $details)) {
		foreach($details['extends'] as $slug2) {
			foreach(glob("uploads/$slug2/$user/*") as $path) {
				if (!array_key_exists(basename($path), $submitted)) {
					$submitted[basename($path)] = '<a title="'.basename($path).'" href="download.php?file='.rawurlencode(explode('/',$path,2)[1]).'">'.basename($path).'</a> '.prettyTime(filemtime($path));
					$haveuploaded = True;
				}
			}
		}
	}
	if ($due == $close && array_key_exists('files', $details) && $details['group'] == 'Project') { // HACK! Move to assignments.json, perhaps as "late message":"..."
		echo "<p class='big notice'>";
		if ($status == 'open' or $status == 'pending') {
			echo "Late submissions of this assignment will <strong>not</strong> be permitted. You or your partner <strong>must</strong> submit on time to receive any credit on this assignment.";
		} else {
			echo "Late submissions of this assignment are not permitted. ";
			if (strpos($slug, 'heckpoint') > 0 && !$haveuploaded) {
				echo "If you missed the deadline, see your grader in lab to get feedback on your game progress.";
			}
		}
		echo "</p>";
	}
	

	if (file_exists("uploads/$slug/$user/.partners")) {
		$partners = array();
		foreach(explode("\n",trim(file_get_contents("uploads/$slug/$user/.partners"))) as $u2) {
			if ($u2 != $user) {
				$whom = rosterEntry($u2);
				if ($whom) $partners[] = "$whom[name] ($u2)";
			}
		}
		if (count($partners) > 0) {
			echo "<div>Partnered with <span class='person'>".implode("</span> and <span class='person'>", $partners)."</span></div>";
		}
	}
	if (!$haveuploaded) {
		if (array_key_exists('files', $details)) {
			if ($status == 'closed') { 
				echo '<em>You did not submit this assignment.</em>';
				$latesubmit = !($isstaff && $isself) && !($details['group'] == 'Lab'); 
			}
			else if ($status != 'pending') echo 'You have not yet submitted this assignment.';
		} else {
			echo 'Online submissions are not enabled for this assignment.';
			$upload = False;
		}
	} else {
		natcasesort($submitted);
		echo "Your files (<a href='view.php?file=$slug/$user'>view all</a>) (<a href='download.php?file=$slug/$user&asuser=$user'>download all as .zip</a>):<ul class='filelist'><li>";
		echo implode('</li><li>', $submitted);
		echo '</li></ul>';
		if (!array_key_exists('files', $details)) {
			$upload = False;
		}
	}
	echo "</div>\n";
	
	// list grade if present, or feedback if no grade, or nothing if neither is present
	if (file_exists("uploads/$slug/$user/.grade")) {
		$regrade = True;
	} else if (file_exists("uploads/$slug/$user/.autofeedback")) {
		if (($isstaff && $isself) || $status == 'closed' || !array_key_exists('fbdelay', $details) || filemtime("uploads/$slug/$user/.autofeedback") < time()-60*60*$details['fbdelay']) { // delay should be from submission, not feedback
			echo "<div class='hide-outer hidden'><strong class='hide-header'>automated feedback</strong><div class='hide-inner'>\n";
			display_grade_file("uploads/$slug/$user/.autofeedback");
			echo "</div></div>\n";
		} else if (array_key_exists('fbdelay', $details)) {
			echo "<div class='advice'>This assignment is set up with a $details[fbdelay]-hour delay before showing you automated feedback. We encourage you to use that time to test your code yourself.</div>";
		}
	}
	
	
	if ($latesubmit || $regrade || $upload) {
		$submit_str = $_GET;
		$submit_str['submitted'] = $slug;
		$submit_str = http_build_query($submit_str);

		$plain_str = $_GET;
		if (array_key_exists('submitted', $plain_str)) { unset($plain_str['submitted']); }
		$plain_str = http_build_query($plain_str);
		//if ($_SERVER['PHP_AUTH_USER'] == 'lat7h') { preFeedback("Query string: $plain_str ".$_SERVER['QUERY_STRING']); var_dump($_GET); leavePre(); }
		
		
		if ($latesubmit) {
			if ($regrade) {
				echo "<div class='hide-outer hidden'><strong class='hide-header'>grade</strong><div class='hide-inner'>\n";
				display_grade_file("uploads/$slug/$user/.grade");
			}
			?><div class='hide-outer hidden'><strong class='hide-header'>late submission request</strong><div class='hide-inner'>
			<?php 
			if (file_exists("meta/requests/extension/$slug-$user")) {
				?><p>You submitted a late submission request <?=prettyTime(filemtime("meta/requests/extension/$slug-$user"))?>; it is currently waiting for faculty decision. Note, to preserve privacy only faculty (not TAs) can see these requests. If yours has not been resolved after a full business day has passed, email your professor.</p><?php
			} else {
				?>
				<p>
					In most cases, deadlines are fixed and past-close submissions are not permitted.
					However, there are cases where circumstances conspire to make deadlines unreachable.
					If you had such a circumstance, describe it below and it will be considered by course staff.
					Please include a proposed new due date in your request.
				</p>
				<form action="<?php echo $_SERVER['PHP_SELF']; ?>?<?php echo $plain_str; ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="slug" value="<?=$slug?>"/>
				<textarea name="extension_request"></textarea><br/><input type="submit"/>
				</form>
				<?php
			}
			echo '</div></div>';
			if ($regrade) {
				echo "</div></div>\n";
			}
		}

		if ($regrade && !$latesubmit) {
			echo "<div class='hide-outer hidden'><strong class='hide-header'>grade</strong><div class='hide-inner'>\n";
			display_grade_file("uploads/$slug/$user/.grade");
			
			if (strpos($slug, 'Lab') === 0) {
				echo "<div>Lab regrades are handled directly by graders in lab meetings.</div>";
			} else if (strpos($slug, 'Q') === 0) {
				// echo "<div>Quiz regrades are based only on the comments you entered when taking the quiz.</div>";
				// do not show regrade option
			} else {

				?><div class='hide-outer hidden'><strong class='hide-header'>regrade request</strong><div class='hide-inner'>
				<?php 
				if (file_exists("meta/requests/regrade/$slug-$user")) {
					echo '<blockquote>';
					echo htmlspecialchars(file_get_contents("meta/requests/regrade/$slug-$user"));
					echo '</blockquote>';
					?><p>You submitted the above regrade request <?=prettyTime(filemtime("meta/requests/regrade/$slug-$user"))?>; it is currently waiting for regrader decision.</p><?php
				} else {
					?>
					<p>
						Although uncommon, we do make mistakes in grading.
						If you feel one of those mistakes happened to you, please describe why below:
					</p>
					<form action="<?php echo $_SERVER['PHP_SELF']; ?>?<?php echo $plain_str; ?>" method="post" enctype="multipart/form-data">
					<input type="hidden" name="slug" value="<?=$slug?>"/>
					<textarea name="regrade_request"></textarea><br/><input type="submit"/>
					</form>
					<?php
				}
				echo '</div></div>';
			}
			echo "</div></div>\n";
			
		}

		if ($upload) {
			if (array_key_exists('files', $details)) {
?><form action="<?php echo $_SERVER['PHP_SELF']; ?>?<?php echo $submit_str; ?>" method="post" enctype="multipart/form-data">
<input type="hidden" name="slug" value="<?=$slug?>"/><?php
				$patterns = $details['files'];
				if (is_string($patterns)) $patterns = array($patterns);
				echo "<p>You may ".($haveuploaded ? 're' : '')."submit ";
				foreach($patterns as $i=>$s) {
					if ($i != 0) { echo " or "; }
					echo "<tt>".htmlspecialchars($s)."</tt>";
				}
				if (!$isself) echo " for $me[name] ($user)";
				echo ":</p>\n<center><input type='file' multiple='multiple' name='submission[]'/><input type='submit' name='upload' value='Upload file(s)'/></center>\n";
?></form><?php
			} else {
				echo "<p>Course staff did not set up submissions for this assignment</p>\n";
			}
		}
		
		if ($isfaculty) {
?><form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" enctype="multipart/form-data">
<input type="hidden" name="slug" value="<?=$slug?>"/><?php
			$subs = glob("uploads/$slug/$user/.2*");
			$sub_cnt = count($subs);
			if ($sub_cnt > 0) {
				sort($subs);
				echo "<div class='hide-outer hidden'><strong class='hide-header'>$sub_cnt submissions (faculty view only; excludes by-partner submission)</strong><div class='hide-inner'>";
				echo "<ol>";
				foreach($subs as $when) {
					$live = array();
					$dead = array();
					foreach(glob("$when/*") as $f) {
						$orig = fileinode($f);
						$curr = fileinode("uploads/$slug/$user/".basename($f));
						if ($orig !== FALSE && $orig == $curr) $live[] = basename($f);
						else $dead[] = $f;
					}
					$when = substr(basename($when),1);
					echo "<li>".prettyTime(DateTime::createFromFormat('Ymd-His',$when)->getTimestamp());
					if (count($dead)) {
						 echo " (click to restore old copy of ";
						 foreach($dead as $i=>$path) {
							 if ($i != 0) echo " and ";
							 echo "<button name='make_live' value='$path'>".basename($path)."</button>";
						 }
						 echo ")";
					}
					if (count($live)) {
						 echo " (current copy of <tt>".implode('</tt> and <tt>', $live)."</tt>)";
					}
					echo "</li>";
					
				}
				echo "</ol>";
				echo "</div></div>";
			}
?></form><?php
		}
		
	}
	if ($slug == 'Project') {
		echo 'We also encourage (but do not require) submitting a <a href="https://docs.google.com/forms/d/e/1FAIpQLSclqTmYTrGNerC158UMlN5A2jgbA7xquFpAlnQ4p_F1MGlOAw/viewform?usp=sf_link">partner evaluation</a>, which will be factored into overall grading';
	}
		
	echo "</div>\n";
}

?></body></html>
