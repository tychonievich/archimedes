<?php
ini_set("auto_detect_line_endings", true);
ini_set("auto_detect_line_endings", "America/New_York");
// date_default_timezone_set

if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50400) {
	ini_set("magic_quotes_runtime", false);
}


/// The following array grants certain users access to the class even if you upload an empty roster or otherwise mess up the course setup. Feel free to add yourself to the set, but remove those it has now at your own risk.
$superusers = array(
	'lat7h'=>array('name'=>'Luther Tychonievich', 'role'=>'Admin'),
	'no grader'=>array('name'=>'no grader assigned', 'role'=>'Teaching Assistant'),
);

$inPre = False;
$noPre = False;
function preFeedback($msg) {
	global $inPre, $noPre;
	if (!$inPre && !$noPre) echo '<div class="prewrap"><pre>';
	$inPre = True;
	echo $msg;
	echo "\n";
}
function leavePre() {
	global $inPre, $noPre;
	if ($inPre && !$noPre) echo '</pre></div>';
	$inPre = False;
}


$_roster = False;
/// Helper function to read the entire roster (for staff view)
function fullRoster() {
	global $_roster, $superusers;
	if ($_roster === False) {
		$_roster = array_merge($superusers, json_decode(file_get_contents("meta/roster.json"), true));
	}
	return $_roster;
}

/// Helper function to read one roster entry (for student access)
function rosterEntry($id, $check=false) {
	global $superusers, $_roster;
	$me = false;
	// if ($_roster) return $_roster[$id]; // faster?
	if ($check && !preg_match('/^[a-z0-9 ]+$/', $id)) return $me;
	if (file_exists("users/$id.json")) {
		$me = json_decode(file_get_contents("users/$id.json"), true);
		$me['id'] = $id;
	}
	if (!$me && array_key_exists($id, $superusers)) {
		$me = $superusers[$id];
		$me['id'] = $id;
	}
	return $me;
}

$_assignments = False;
/// Helper function to read the set of assignments (needed in almost every view)
function assignments() {
	global $_assignments;
	if ($_assignments === False) {
		$_assignments = json_decode(file_get_contents("meta/assignments.json"), true);
		foreach($_assignments as $k=>$v) {
			if (!array_key_exists('fbdelay', $v)) {
				$_assignments[$k]['fbdelay'] = 2;
			}
		}
	}
	return $_assignments;
}

$_coursegrade = False;
/// Helper function to read the letter buckets and group weights
function coursegrade() {
	global $_coursegrade;
	if ($_coursegrade === False) {
		$_coursegrade = json_decode(file_get_contents("meta/coursegrade.json"), true);
		$total = 0;
		foreach($_coursegrade['weights'] as $k=>$v) {
			$total += $v;
		}
		foreach($_coursegrade['weights'] as $k=>&$v) {
			$v /= $total;
		}
	}
	return $_coursegrade;
}

function letterOf($ratio, $html=False) {
	foreach(coursegrade()['letters'] as $pair) {
		foreach($pair as $letter=>$bottom) {
			if ($ratio >= $bottom) return $letter;
		}
	}
	if ($html && substr($letter, 1) == "-") return $letter[0] + "&minus;";
	return $letter;
}

// ensures $roster[grader
function makeConsistent($newdata) {
	// ensure everyone has a name
	foreach($newdata as $k=>&$v) {
		if (!array_key_exists('name', $v)) $v['name'] = 'name unknown';
		if (array_key_exists('graded', $v)) unset($v['graded']); // <-- prep for next loop
		if (array_key_exists('grader', $v) && $v['grader'] == 'no grader') {
			unset($v['grader']);
			if (array_key_exists('grader_name', $v)) unset($v['grader_name']);
		}
	}
	// and all graders are consistent
	foreach($newdata as $k=>&$v) {
		if (array_key_exists('grader', $v)) {
			if (!array_key_exists($v['grader'], $newdata)) {
				preFeedback("grader $v[grader] is not in the roster; removing from $v[name] ($k)");
				unset($v['grader']);
			} else if (!hasStaffRole($newdata[$v['grader']])) {
				preFeedback("grader $v[grader] does not have a staff role; removing from $[name] ($k)");
				unset($v['grader']);
			} else {
				$v['grader_name'] = $newdata[$v['grader']]['name'];
				if (!array_key_exists('graded', $newdata[$v['grader']])) $newdata[$v['grader']]['graded'] = array();
				$newdata[$v['grader']]['graded'][] = $k;
			}
		}
	}
	return $newdata;
}

function updateUser($id, $newdata, $remove=false) {
	global $_roster;
	if (!$remove) {
		$old = rosterEntry($id);
		if ($old) $newdata = array_merge($old, $newdata);
	}
	if (array_key_exists('grader', $newdata) && $newdata['grader'] == 'no grader') {
		unset($v['grader']);
	}
	$newroster = fullRoster();
	$newroster[$id] = $newdata;
	$newroster = makeConsistent($newroster);
	$newdata = $newroster[$id];
	
	// save to a timestamped file, then update the hard link
	$realname = 'meta/.' . date_format(date_create(), "Ymd-His") . "-roster.json";
	if (file_put_contents($realname, json_encode($newroster, JSON_PRETTY_PRINT)) !== FALSE) {
		rename('meta/roster.json', 'meta/backup-roster.json');
		if (link($realname, 'meta/roster.json')) {
			unlink('meta/backup-roster.json');
		} else {
			preFeedback("ERROR: failed to update roster.json link");
			rename('meta/backup-'.$realname, 'meta/roster.json');
		}
	} else {
		preFeedback("ERROR: failed to save master roster file (not sure why?)");
	}
	if (file_put_contents("users/$id.json", json_encode($newdata)) == FALSE) {
		preFeedback("ERROR: failed to save $uid.json");
	} else {
		chmod("users/$uid.json", 0666);
	}
	if (array_key_exists('grader', $newdata)) {
		$id = $newdata['grader'];
		if (file_put_contents("users/$id.json", json_encode($newroster[$id])) == FALSE) {
			preFeedback("ERROR: failed to save $uid.json");
		} else {
			chmod("users/$uid.json", 0666);
		}
	}
	

	$_roster = $newroster;
}

/**
 * A modestly complicated function for turning spreadsheets of information containing a computing ID
 * into information for user records.
 * Does allow uploading several different sheets (e.g., Collab's for name/role/section; a custom one for grading groups; ...)
 */
function updateRosterSpreadsheet($uploadrecord, $remove=False, $keepWaiters=True) {
	// read the current roster, and prepare some bookkeeping variables
	$olddata = fullRoster();
	if ($remove) $newdata = array();
	$changed = 0;
	$added = 0;
	$removed = 0;
	$killed = '';

	// read the uploaded spreadsheet
	require_once 'spreadsheet-reader/SpreadsheetReader.php';
	$reader = new SpreadsheetReader($uploadrecord['tmp_name'], $uploadrecord['name'], $uploadrecord['type']);
	foreach($reader->Sheets() as $idx => $name) {
		$reader->ChangeSheet($idx);
		
		// for some strange reason, Collab exports a roster with several sub-sheets inside each sheet.
		// each has a single title row with just one element, a blank row, and then the header and contents
		// since spreadsheet-reader skips empty lines in xlsx, we look for <= 1
		$blank = true;
		$header = array();

		foreach ($reader as $row) {
			if (count($row) <= 1) { $blank = true; }
			else if ($blank) {
				$header = array();
				foreach($row as $i=>$head) { $header[$i] = strtolower($head); }
				$blank = false;
			} else {
				$entry = array();
				$user = False;
				foreach($header as $i=>$head) {
					$head = strtolower($head);
					// normalize the various forms of computing ID
					if ($user === FALSE && ($head == "id" || substr_compare($head, " id", -3) == 0)) {
						$user = strtolower($row[$i]);
						$entry['id'] = $user;
					} else if ($head == 'name') {
						$entry[$head] = implode(' ', array_reverse(explode(',', str_replace(', ', ',', $row[$i]), 2)));
					} else {
						$entry[$head] = $row[$i];
					}
					if ($head == 'grader'
					&& array_key_exists($entry[$head], $olddata)
					&& array_key_exists('name', $olddata[$entry[$head]])) {
						$entry['grader_name'] = $olddata[$entry[$head]]['name'];
					}
				}
				if ($user !== False) {
					if ($remove) { // if removing missing, have to copy seen people into new array
						if (array_key_exists($user, $newdata)) {
							$newentry = array_replace($newdata[$user], $entry);
							if ($newentry != $newdata[$user]) {
								$newdata[$user] = $newentry;
								$changed += 1; // bookkeeping
							}
						} else if (array_key_exists($user, $olddata)) {
							$newentry = array_replace($olddata[$user], $entry);
							if ($newentry != $olddata[$user]) {
								$changed += 1; // bookkeeping
							}
							$newdata[$user] = $newentry;
						} else {
							$newdata[$user] = $entry;
							$added += 1; // bookkeeping
						}
					} else { // if leaving missing, simpler to update old array in place
						if (array_key_exists($user, $olddata)) {
							$newentry = array_replace($olddata[$user], $entry);
							if ($newentry != $olddata[$user]) {
								$olddata[$user] = $newentry;
								$changed += 1;
							}
						} else {
							$olddata[$user] = $entry;
							$added += 1; // bookkeeping
						}
					}
				} else {
					preFeedback("WARNING: no User ID in " . json_encode($entry));
					flush();
				}
			}
		}
	}
	
	// possibly remove waitlisted students
	if (!$keepWaiters) {
		foreach($newdata as $k=>$v) {
			if (array_key_exists('role', $v) && $v['role'] == 'Waitlisted Student') {
				preFeedback("$k was waitlisted");
				unset($newdata[$k]);
			} else {
				if (array_key_exists('groups', $v)) {
					$sections = explode(', ', $v['groups']);
					foreach($sections as $i=>$s) {
						if (strpos($s, 'Waitlist') > 0) unset($sections[$i]);
					}
					$newdata[$k]['groups'] = implode(', ', $sections);
				}
			}
		}
	}
	
	
	// finish bookkeeping, and unify remove and non-remove cases
	if ($remove) {
		$killed = array_diff_key($olddata, $newdata);
		$removed = count($killed);
		if ($removed > 0) {
			foreach($killed as $i=>$val) {
				unlink("users/$val.json");
			}
			$killed = ": " . implode(', ', array_keys($killed));
		}
		else $killed = '';
		$removed = count($olddata) - count($newdata) + $added;
		if ($removed < 0) $removed = 0;
	} else {
		$newdata = $olddata;
	}
	$newdata = makeConsistent($newdata);
	if ($added == 0 && $changed == 0 && $removed == 0) {
		preFeedback("-=> $uploadrecord[name] <=- New roster same as old");
		return;
	}

	// save to a timestamped file, then update the hard link
	$realname = 'meta/.' . date_format(date_create(), "Ymd-His") . "-roster.json";
	if (file_put_contents($realname, json_encode($newdata, JSON_PRETTY_PRINT)) !== FALSE) {
		rename('meta/roster.json', 'meta/backup-roster.json');
		if (link($realname, 'meta/roster.json')) {
			preFeedback("-=> $uploadrecord[name] <=- Added $added, Changed $changed, Removed $removed$killed");
			unlink('meta/backup-roster.json');
		} else {
			preFeedback("ERROR: failed to update roster.json link");
			rename('meta/backup-'.$realname, 'meta/roster.json');
		}
	} else {
		preFeedback("ERROR: failed to save master roster file (not sure why?)");
	}
	foreach($newdata as $uid=>$data) {
		if (file_put_contents("users/$uid.json", json_encode($data)) == FALSE) {
			preFeedback("ERROR: failed to save $uid.json");
		} else {
			chmod("users/$uid.json", 0666);
		}
	}
	
}

function hasFacultyRole($me) {
	return array_key_exists('role', $me) && (
		$me['role'] == 'Instructor'
		|| $me['role'] == 'Professor'
		|| $me['role'] == 'Teacher'
		|| $me['role'] == 'Admin'
	);
}
function hasStaffRole($me) {
	return array_key_exists('role', $me) && (
		$me['role'] == 'Instructor'
		|| $me['role'] == 'Professor'
		|| $me['role'] == 'Teacher'
		|| $me['role'] == 'Admin'
		|| stripos($me['role'], 'instruct') !== False
		|| stripos($me['role'], 'teach') !== False
		|| $me['role'] == 'TA'
	);
}

/**
 * Handles Netbadge (PHP_AUTH_USER), identifying staff, and ?asuser=mst3k.
 * Sets global variables: $user (a computing ID); $me (an array of information);
 * $isself, $isstaff, and $isfaculty (booleans).
 */
function logInAs($compid=false, $initial=true) {
	global $user, $me, $isstaff, $isself, $isfaculty;
	if ($compid !== false) {
		$user = $compid;
	} else if (array_key_exists('PHP_AUTH_USER', $_SERVER)) {
		$user = $_SERVER['PHP_AUTH_USER'];
	} else {
		preFeedback("ERROR: you don't appear to be authenticated with NetBadge.");
		leavePre();
		die("</body></html>\n");
	}

	if (($me = rosterEntry($user)) !== False) {
		if ($initial) {
			$isfaculty = hasFacultyRole($me);
			$isstaff = hasStaffRole($me);
		}
	} else {
		preFeedback("ERROR: user $user is not in our roster.");
		leavePre();
		if (array_key_exists('PHP_AUTH_USER', $_SERVER) && $_SERVER['PHP_AUTH_USER'] != $user) {
			echo "<p><a href=\"$_SERVER[SCRIPT_NAME]\">Return to site as yourself.</a></p>\n";
		}
		die("</body></html>\n");
	}
	
	$isself = true;
	if ($initial && $isstaff && array_key_exists('asuser', $_GET)) {
		global $_me;
		$_me = false;
		logInAs($_GET['asuser'], false);
		$isstaff = true;
		$isself = false;
	}
}

/**
 * Small wrapper to parse times from an assignment details entry
 */
function assignmentTime($key, $details) {
	if (!array_key_exists($key, $details)) return False;
	if (is_int($details[$key]) && $details[$key] > 1000000000) return $details[$key];
	return strtotime($details[$key] . " America/New_York");
}
/**
 * Converts a unix timestamp into a time string, including relative time.
 * Also wraps in a span with the unix timestamp for javascript update
 */
function prettyTime($timestamp) {
	if ($timestamp === False) return "on a yet-to-be-determined date";
	$time = date_create("@$timestamp");
	$time->setTimeZone(timezone_open('America/New_York'));
	$now = date_create("now", timezone_open('America/New_York'));
	$base = $time->format('D Y-M-d H:i');
	$delta = $now->diff($time);
	if ($delta->m > 0 || $delta->y > 0) {
		$extra = "more than a month" . ($delta->invert ? " ago" : " from now");
	} else if ($delta->d == 0) {
		$extra = $delta->format('%h:%I') . ($delta->invert ? " ago" : " from now");
	} else if (False && $delta->d == 1) {
		$h = floatval($time->format('G'));
		$extra = ($delta->invert ? "yesterday " : "tomorrow ")
			. ($h < 12 ? "morning" 
			  :($h < 18 ? "afternoon"
			   :($h < 21 ? "evening"
			    :"night"
			    )))
			;
	} else if ($delta->d < 7) {
		$h = floatval($time->format('G'));
		$extra = ($delta->invert ? "last " : "next ")
			. $time->format('l')
			. ($h < 12 ? " morning" 
			  :($h < 18 ? " afternoon"
			   :($h < 21 ? " evening"
			    :" night"
			    )))
			;
	} else {
		$weeks = round(floatval($delta->format('%a'))/7);
		$extra = ($weeks != 1 ? "$weeks weeks" : "a week") . ($delta->invert ? " ago" : " from now");
	}
	return "<span class='datetime' ts='$timestamp'>$base ($extra)</span>";
}

function display_grade_file($path) {
	global $isstaff, $isself;
	$data = json_decode(file_get_contents($path), true);
	if (array_key_exists('slug', $data)) $asgn = assignments()[$data['slug']];
	else $asgn = array();

	if (array_key_exists('timestamp', $data)) echo '<div>feedback generated '. prettyTime($data['timestamp']).'</div>';
	
	if (($isstaff && $isself) && array_key_exists('grader', $data)) {
		echo "<div class='grader'>Graded by ".fullRoster()[$data['grader']]['name']." ($data[grader])</div>\n";
	}
	if (array_key_exists('grade', $data)) {
		if (array_key_exists('total', $asgn)) $show = round($data['grade'] * $asgn['total'], 3) . " / $asgn[total]";
		else $show = round($data['grade']*100, 2)."%";
		echo "<div class='grade'>Grade: $show (";
		echo letterOf($data['grade']);
		echo ")</div>\n";
	}

	if (array_key_exists('stdout', $data)) { // old-style feedback
		echo '<pre class="feedback stdout">'.htmlspecialchars($data['stdout']).'</pre>';
		if (($isstaff && $isself) && array_key_exists('stderr', $data)) {
			echo '<pre class="feedback stderr">'.htmlspecialchars($data['stderr']).'</pre>';
		}
	} 
	
	if (array_key_exists('details', $data)) { // new-style feedback
		$rubric = rubricOf($data['slug']);
		echo feedbackTag($data, $rubric, array_key_exists('total', $asgn) ? $asgn['total'] : False);
	}
	
	if (array_key_exists('comments', $data) && array_key_exists('extension', $data['comments'])) {
		echo "<div><strong>Extension decision:</strong><div>";
		echo implode("</div><div>", $data['comments']['extension']);
		echo "</div></div>";
	}
	
	echo regradeTag($data, false);
	return $data;
}

/** Recursively create an HTML tag for displaying grade feedback. */
function feedbackTag($details, $rubric, $worth) {
	try {
		if (array_key_exists('details', $details)) {
			$ans = feedbackTag($details['details'], $rubric, $worth);
		} else if ($rubric['kind'] == 'breakdown') {
			$total = 0; foreach($rubric['parts'] as $r) $total += $r['ratio'];
			$ans = '<dl class="breakdown">';
			$_ignore = False;
			foreach($rubric['parts'] as $r) {
				$ans .= "<dt class='breakdown-header'>".htmlspecialchars($r['name'])." (";
				if ($worth) {
					$ans .= round(gradePool($details[$r['name']], $r['rubric'], $_ignore)*$worth*$r['ratio']/$total, 3);
					$ans .= " / ";
					$ans .= round($worth*$r['ratio']/$total, 3);
				} else {
					$ans .= round(gradePool($details[$r['name']], $r['rubric'], $_ignore)*100*$r['ratio']/$total, 3);
					$ans .= " / ";
					$ans .= round(100*$r['ratio']/$total, 3) . "%";
				}
				$ans .= ")</dt><dd class='breakdown-body'>";
				$ans .= feedbackTag($details[$r['name']], $r['rubric'], $worth*$r['ratio']/$total);
				$ans .= "</dd>";
			}
			$ans .= '</dl>';
		} else if ($rubric['kind'] == 'percentage') {
			$ans = "<div class='percentage'>".($worth 
			? round($details['ratio']*$worth,3)." / ". round($worth,3)
			: round($details['ratio']*100,2)."%"
			).": ".htmlspecialchars($details['comment'])."</div>";
		} else if ($rubric['kind'] == 'check') {
			if ($details >= 1) $ans = '<div class="check correct">✓</div>';
			else if ($details <= 0) $ans = '<div class="check wrong">✗</div>';
			else $ans = '<div class="check partial">½</div>';
		} else if ($rubric['kind'] == 'buckets') {
			$rows = False;
			$score = gradePool($details, $rubric, $rows);
			$ans = "<dl class='buckets'>";
			foreach($details as $i=>$set) {
				if (count($set) > 0) {
					$ans .= "<dt class='bucket'>".htmlspecialchars($rubric['buckets'][$i]['name'])."</dt><dd><ul class='bucket'>";
					foreach($set as $txt) $ans .= "<li>".htmlspecialchars($txt)."</li>";
					$ans .= "</ul></dd>";
				}
			}
			$ans .= "</dl>";
		} else {
			return '<div class="unexpected">Unexpected error "'.$rubric['kind'].'" encountered in generating feedback; please contact your professor</div>';
		}
	} catch (Exception $e) {
		return '<div class="unexpected">Unexpected error "'.$e->getMessage().'" encountered in generating feedback; please contact your professor</div>';
	}
	if (array_key_exists('.mult', $details)) {
		foreach($details['.mult'] as $mult) {
			$c = htmlspecialchars($mult['comment']);
			if ($mult['ratio'] > 1) {
				$ans .= "<div class='extra credit'>$c: +".round($mult['ratio']*100-100, 2)."% extra credit</div>";
			} else if ($mult['ratio'] == 1) {
				$ans .= "<div class='extra comment'>$c</div>";
			} else {
				$ans .= "<div class='extra penalty'>$c: &minus;".round(100-$mult['ratio']*100, 2)."% penalty</div>";
			}
		}
	}
	return $ans;
}
function regradeTag($grade, $graderView=False) {
	if (!array_key_exists('slug', $grade)) return '';
	$slug = $grade['slug'];
	$student = $grade['student'];
	$ans = '';

	if (file_exists("meta/requests/regrade/$slug-$student") || array_key_exists('regrade', $grade)) {
		$ans .= '<div class="regrade-log" id="'."$slug\n$student\nregrade".'"><strong>regrade log:</strong>';
		if (array_key_exists('regrade', $grade)) {
			foreach($grade['regrade'] as $exchange) {
				$ans .= '<pre class="regrade-request">'.htmlspecialchars($exchange['request']).'</pre>';
				if (array_key_exists('response', $exchange)) {
					$ans .= '<pre class="regrade-response">'; 
					if ($exchange['response'])
						$ans .= htmlspecialchars($exchange['response']);
					else
						$ans .= '<em>(resolved without comment)</em>';
					$ans .= '</pre>';
				}
			}
		}
		if (file_exists("meta/requests/regrade/$slug-$student")) {
			$ans .= '<pre class="regrade-request">'.htmlspecialchars(file_get_contents("meta/requests/regrade/$slug-$student")).'</pre>';
			if ($graderView) $ans .= '<strong>Response:</strong><textarea class="regrade-response"></textarea>';
		}
		$ans .= '</div>';
	}
	return $ans;
}

function file_put($path, $contents) {
	umask(0);
	if (!is_dir(dirname($path)))
		mkdir(dirname($path), 0777, true);
	$ans = file_put_contents($path, $contents);
	chmod($path, 0666);
	return $ans;
}
function file_append($path, $contents) {
	umask(0);
	if (!is_dir(dirname($path)))
		mkdir(dirname($path), 0777, true);
	$ans = file_put_contents($path, $contents, FILE_APPEND);
	chmod($path, 0666);
	return $ans;
}
function file_move($newpath, $oldpath) {
	umask(0);
	if (!is_dir(dirname($newpath)))
		mkdir(dirname($newpath), 0777, true);
	$ans = rename($oldpath, $newpath);
	chmod($newpath, 0666);
	return $ans;
}
function ensure_file($path) {
	umask(0);
	if (!is_dir(dirname($path)))
		mkdir(dirname($path), 0777, true);
	$ans = touch($path);
	chmod($path, 0666);
	return $ans;
}

function user_error_msg($msg) {
	echo '<div class="big error"><h1>Error!</h1>';
	echo $msg;
	echo '</div>';
}
function user_notice_msg($msg) {
	echo '<div class="big notice"><h1>Notice:</h1>';
	echo $msg;
	echo '</div>';
}
function user_success_msg($msg) {
	echo '<div class="big success"><h1>Success!</h1>';
	echo $msg;
	echo '</div>';
}

function closeTime($assignmentDetails) {
	$close = assignmentTime('close', $assignmentDetails);
	//if ($_SERVER['PHP_AUTH_USER'] == 'lat7h') { echo "<pre>"; var_dump($assignmentDetails); var_dump($close); echo "</pre>"; }
	if ($close === False) {
		$close = assignmentTime('due', $assignmentDetails);
		if (array_key_exists('late-policy', $assignmentDetails)) {
			$close += 24*60*60*count($assignmentDetails['late-policy']); // 24 hours != 1 day if DST in between... use anyway
		}
	}
	return $close;
}

/**
 * Returns a <select name="$name">...</select> string with all assignment slugs in it
 */
function assignmentDropdown($name) {
	// $ans = "<select name='$name'><option value=''>(select)</option>";
	$ans = "<input type='text' title='example: Lab03' list='assignments-list' name='$name'/><datalist id='assignments-list'>";
	foreach(assignments() as $slug=>$details) {
		$ans .= "<option value='$slug'>$slug";
		if (array_key_exists('files', $details)) {
			if (is_string($details['files'])) $ans .= ": $details[files]";
			else $ans .= ": " . implode('|', $details['files']);
		}
		$ans .= '</option>';
	}
	// $ans .= '</select>';
	$ans .= '</datalist>';
	return $ans;
}
/**
 * Returns a <select name="$name">...</select> string with all user ids in it
 */
function userDropdown($name) {
	// $ans = "<select name='$name'><option value=''>(select)</option>";
	$ans = "<input type='text' title='example: mst3k' list='users-list' name='$name'/><datalist id='users-list'>";
	foreach(fullRoster() as $slug=>$details) {
		if (array_key_exists('role', $details) && $details['role'] == 'Admin') continue;
		$ans .= "<option value='$slug'>$details[name] ($slug)</option>";
	}
	// $ans .= '</select>';
	$ans .= "</datalist>";
	return $ans;
}
function studentDropdown($name) {
	// $ans = "<select name='$name'><option value=''>(select)</option>";
	$ans = "<input type='text' title='example: mst3k' list='students-list' name='$name'/><datalist id='students-list'>";
	foreach(fullRoster() as $slug=>$details) {
		if (array_key_exists('role', $details) && $details['role'] == 'Admin') continue;
		if (!array_key_exists('role', $details) || $details['role'] != 'Student') continue;
		$ans .= "<option value='$slug'>$details[name] ($slug)</option>";
	}
	// $ans .= '</select>';
	$ans .= "</datalist>";
	return $ans;
}
function staffDropdown($name) {
	// $ans = "<select name='$name'><option value=''>(select)</option>";
	$ans = "<input type='text' title='example: mst3k' list='staff-list' name='$name'/><datalist id='staff-list'>";
	foreach(fullRoster() as $slug=>$details) {
		if (!hasStaffRole($details)) continue;
		$ans .= "<option value='$slug'>$details[name] ($slug)</option>";
	}
	// $ans .= '</select>';
	$ans .= "</datalist>";
	return $ans;
}


function _rubric_bucket_helper(&$rubric, &$buckets) {
	if ($rubric['kind'] == 'buckets' && !array_key_exists('buckets', $rubric))
		$rubric['buckets'] = $buckets;
	if ($rubric['kind'] == 'breakdown') {
		foreach($rubric['parts'] as $k=>&$v) {
			_rubric_bucket_helper($v['rubric'], $buckets);
		}
	}
}
function rubricOf($assignment) {
	if (file_exists("meta/buckets.json")) {
		$buckets = json_decode(file_get_contents("meta/buckets.json"), true);
	} else { $buckets = false; }
	
	if (array_key_exists($assignment, assignments()) && array_key_exists('rubric', assignments()[$assignment])) {
		$rubric = assignments()[$assignment]['rubric'];
	} else if (file_exists("uploads/$assignment/.rubric")) {
		$rubric = json_decode(file_get_contents("uploads/$assignment/.rubric"), true);
	} else if (file_exists("uploads/.rubric")) {
		$rubric = json_decode(file_get_contents("uploads/.rubric"), true);
	} else if ($buckets) {
		$rubric = array('kind'=>'buckets');
	} else {
		$rubric = array('kind'=>'percentage');
	}
	
	if ($buckets) {
		_rubric_bucket_helper($rubric, $buckets);
	}
	return $rubric;
}

function commentSet($assignment, $startat=0) {
	$ans = array('..'=>$startat);
	if (!file_exists("meta/commentpool/$assignment.json")) return $ans;
	$fh = fopen("meta/commentpool/$assignment.json", "r");
	if (!$fh) return $ans;
	fseek($fh, $startat);
	while (($line = fgets($fh)) !== False) {
		$ans['..'] = ftell($fh);
		foreach(json_decode($line, true) as $k=>$v) {
			if (array_key_exists("\n$k", $ans)) $ans["\n$k"][] = $v;
			else $ans["\n$k"] = array($v);
		}
	}
	return $ans;
}

/**
 * Given a string with a ' (' in it, replaces it with the pre-' (' part and returns the in-paran part
 */
function commentSplit($text, &$main, &$extra) {
	$upto = strpos($text, ' ('); // chop off details
	// usually want $upto !== False, but in this case we don't want to chop to nothing...
	if ($upto) {
		$extra = substr($text, $upto+2, -1);
		$main = substr($text, 0, $upto);
	} else {
		$extra = '';
		$main = $text;
	}
}

/**
 * Both compute a grade and create a pool of comments used (unless $rows is False, then just grade)
 */
function gradePool(&$gradeobj, $rubric, &$rows, $path='') {
	if (!$gradeobj && $gradeobj !== 0)
		throw new InvalidArgumentException("incomplete grade object ".json_encode($gradeobj));
	if (!$rubric) 
		throw new InvalidArgumentException("incomplete rubric object");
	if (!array_key_exists('kind', $rubric)) 
		throw new InvalidArgumentException("rubric object does not identify its kind");
	if ($rubric['kind'] == 'breakdown') {
		if ($rows === false && array_key_exists('.earned', $gradeobj)) return $gradeobj['.earned'];
		$grade = 0;
		$bits = 0;
		foreach($rubric['parts'] as $r) {
			$bits += $r['ratio'];
			if (array_key_exists($r['name'], $gradeobj)) { // should always be true...
				$grade += $r['ratio'] * gradePool($gradeobj[$r['name']], $r['rubric'], $rows, $path ? "$path\n$r[name]" : $r['name']);
			}
		}
		if (array_key_exists('.mult', $gradeobj)) {
			foreach($gradeobj['.mult'] as $mult) {
				$grade *= gradePool($mult, array('kind'=>'percentage'), $rows,  $path ? "$path\n.mult" : '.mult');
			}
		}
		$gradeobj['.earned'] = $bits > 0 ? $grade / $bits : $grade; // enforce 100%
		return $gradeobj['.earned'];
	} else if ($rubric['kind'] == 'percentage') {
		if ($rows !== False) {
			$upto = strpos($gradeobj['comment'], ' ('); // chop off details
			// usually want $upto !== False, but in this case we don't want to chop to nothing...
			if ($upto) {
				$comment = substr($gradeobj['comment'], 0, $upto);
				$rows[] = json_encode(array($path=>array('ratio'=>$gradeobj['ratio'],'comment'=>$comment)));
			} else {
				$rows[] = json_encode(array($path=>$gradeobj));
			}
		}
		return $gradeobj['ratio'];
	} else if ($rubric['kind'] == 'check') {
		return $gradeobj;
	} else if ($rubric['kind'] == 'buckets') {
		if (count($rubric['buckets']) != count($gradeobj))
			throw new InvalidArgumentException("bucket count mismatch");
		// this is the tricky one... cascading spill-over and so on
		$idx = -2; $spill = -1;
		foreach($gradeobj as $i=>$set) {
			$key = $path ? "$path\n$i" : "$i";
			foreach($set as $j=>$comment) {
				if ($i != $idx) { // new bucket!
					$spill = 0;
					if ($idx == $i-1 && $rubric['buckets'][$idx]['spillover'] > 0  && $rubric['buckets'][$idx]['spillover'] <= $spill) { $spill = 1; } // spillover
					$idx = $i;
				}
				$spill += 1;
				if ($rows !== False) {
					$upto = strpos($comment, ' ('); // chop off details
					// usually want $upto !== False, but in this case we don't want to chop to nothing...
					if ($upto) $comment = substr($comment, 0, $upto);
					$rows[] = json_encode(array($key=>$comment));
				}
			}
		}
		if ($idx < 0) return 1; // full credit
		$bucket = $rubric['buckets'][$idx];
		if (abs($bucket['spillover']) <= 1 || $spill == 1) // simple bucket value
			return $bucket['score'];
		$distance = ($spill - 1) / (abs($bucket['spillover']) - 1);
		$next = array_key_exists($idx+1, $rubric['buckets']) ? $rubric['buckets'][$idx+1]['score'] : 0;
		if ($distance >= 1) // simple spillover
			return $next;
		return $bucket['score']*(1-$distance) + $next*($distance); // linear path to next bucket
	} else {
		throw new InvalidArgumentException("unexpected rubric kind '".$rubric['kind']."'");
	}
}

function totalGradeAndAddPool(&$grade) {
	$rows = array();
	if (!array_key_exists('details', $grade)) return $grade['grade'];
	try {
		$rubric = rubricOf($grade['slug']);
		$score = gradePool($grade['details'], $rubric, $rows);
		$grade['grade'] = $score;
		// FIX ME: add rows to commentpool
		if (file_exists("meta/commentpool/$grade[slug].json")) {
			$eraser = array();
			$fh = fopen("meta/commentpool/$grade[slug].json", "r");
			if ($fh) {
				while (($line = fgets($fh)) !== False)
					$eraser[] = trim($line);
				fclose($fh);
			}
			$rows = array_diff($rows, $eraser);
		}
		$fh = fopen("meta/commentpool/$grade[slug].json", "a");
		if ($fh) {
			foreach($rows as $line) fwrite($fh, "$line\n");
			fclose($fh);
		}
	} catch (Exception $e) {
		die(json_encode(array("success"=>False, "comment"=>$e->getMessage(), "payload"=>$grade)));
	}
}

/** returns HTML tags to place a file nicely on the the screen */
function studentFileTag($path, $classes='left') {
	if ($path === False) {
		return "<div class='$classes width'>No files found</div>";
	}
	$title = htmlspecialchars(basename($path));
	if ($classes) $classes = "$classes panel";
	else $classes = "panel";
	if (!file_exists($path)) {
		return "<div class='$classes width'>Missing file: <tt>$title</tt></div>";
	} else {
		$link = 'download.php?file='.rawurlencode(explode('/',$path,2)[1]);
		$finfo = new finfo(FILEINFO_MIME);
		$mime = $finfo->file($path);
		if (stripos($mime, 'image') !== FALSE) {
			return "<a href='$link' target='_blank'><img class='$classes width height' src='$link'/></a><br/><input type='button' value='toggle image visibility' onclick='e=this.previousSibling.previousSibling; e.setAttribute(\"style\", e.getAttribute(\"style\") ? \"\" : \"display:none\")'/>";
			//	return "<a href='$link' target='_blank'><img class='$classes width height' src='$link'/></a>";
		} else if (stripos($mime, 'text') !== FALSE) {
			return "<div class='$classes width'>File <a href='$link' target='_blank'><tt>$title</tt></a>:<pre><code>" . htmlspecialchars(file_get_contents($path)) . "</code></pre></div>";
		} else if (is_dir($path)) {
			$vlink = 'view.php?file='.rawurlencode(explode('/',$path,2)[1]);
			return "<div class='$classes width'>Directory <a href='$vlink'><tt>$title</tt></a></div>";
		} else {
			return "<div class='$classes width'>File <a href='$link' target='_blank'><tt>$title</tt></a> (no preview available)</div>";
		}
	}
}

function applies_to($me, $task) {
	if (is_string($me)) $me = rosterEntry($me);
	if (!array_key_exists('groups', $me)) return True;
	if (is_string($task) && array_key_exists($task, assignments())) $task = assignments()[$task];
	if (is_array($task)) {
		if (!array_key_exists('group', $task)) return True;
		$task = $task['group'];
	}
	
	$cg = coursegrade();
	$mg = explode(', ', $me['groups']);
	if (array_key_exists('includes', $cg) && array_key_exists($task, $cg['includes'])) {
		foreach($mg as $g1)
			foreach($cg['includes'][$task] as $g2)
				if ($g1 == $g2)
					return True;
		return False;
	} else if (array_key_exists('excludes', $cg) && array_key_exists($task, $cg['excludes'])) {
		foreach($mg as $g1)
			foreach($cg['excludes'][$task] as $g2)
				if ($g1 == $g2)
					return False;
		return True;
	}
	return True;
}

// FIX ME: refactor to use grade_map
function grade_in_course($user) {
	$earned = array();
	$detailed = array();
	$cg = coursegrade();
	$weight_denom = 0;
	$me = rosterEntry($user);
	foreach($cg['weights'] as $key=>$weight) {
		if (!applies_to($me, $key)) continue;
		$earned[$key] = array('earned'=>0, 'missed'=>0, 'future'=>0, 'weight'=>$weight);
		$weight_denom += $weight;
		if (array_key_exists($key, $cg['drops'])) {
			$earned[$key]['drop'] = array();
			$earned[$key]['undropped'] = $cg['drops'][$key];
		}
	}
	$ungrouped = array();
	$excused = array();
	$zero = array();
	$special_notes = array(); // HACK: consider refactoring
	foreach(assignments() as $slug=>$details) {
		if (!array_key_exists('group', $details)) {
			$ungrouped[] = $slug;
			continue;
		}
		$grp = $details['group'];
		if (!applies_to($me, $grp)) continue;
		if (!array_key_exists($grp, $earned)) {
			$ungrouped[] = $slug;
			continue;
		}
		if (file_exists("uploads/$slug/$user/.excused")) {
			$excused[] = $slug;
			continue;
		}
		$weight = 1;
		if (array_key_exists('weight', $details)) $weight = $details['weight'];
		$closed = closeTime($details) < time(); // this makes past-due appear 0 even if still being graded
		if ($weight == 0 && $closed) {
			$zero[] = $slug;
			continue;
		}
		if (!file_exists("uploads/$slug/$user/.grade")) {
			if ($closed /*&& !file_exists("uploads/$slug/$user/") && $grp != 'Exam'*/) $earned[$grp]['missed'] += $weight;
			else $earned[$grp]['future'] += $weight;
			continue;
		}
		$tmp = json_decode(file_get_contents("uploads/$slug/$user/.grade"), true);
		if (!array_key_exists('grade', $tmp)) {
			totalGradeAndAddPool($tmp);
			file_put_contents("uploads/$slug/$user/.grade", json_encode($tmp));
		}
		if (file_exists("uploads/$slug/$user/.adjustment")) { // HACK: should probably refactor...
			$adj = json_decode(file_get_contents("uploads/$slug/$user/.adjustment"), true);
			$cap = 1;
			if (array_key_exists('extra', $adj)) $cap = $adj['extra'];
			$ng = min($adj['mult']*$tmp['grade'], $cap);
			if (($ng != $tmp['grade'])) {
				$extra_notes[$slug] = array(($ng-$tmp['grade'])*$weight, $adj['comments']);
				$tmp['grade'] = $ng;
			}
		}
		$score = $weight * $tmp['grade'];
		if ($weight == 1 && $score < 1 && array_key_exists('drop', $earned[$grp])) {
			if ($earned[$grp]['undropped'] > 0) {
//if ($_SERVER['PHP_AUTH_USER'] == 'lat7h') { preFeedback("drop test $grp"); var_dump($earned[$grp]); leavePre(); }
				$earned[$grp]['drop'][] = $score;
				$earned[$grp]['undropped'] -= 1;
				continue;
			}
			foreach($earned[$grp]['drop'] as $i=>$v) {
				if ($v > $score) {
					$earned[$grp]['drop'][$i] = $score;
					$score = $v;
				}
			}
		}
		$earned[$grp]['earned'] += $score;
		if ($score < $weight) $earned[$grp]['missed'] += $weight - $score;
		if (!array_key_exists($grp, $detailed)) $detailed[$grp] = array();
		$detailed[$grp][$slug] = array('earned'=>$score, 'outof'=>$weight);
	}

	$m = 0; $e = 0;
	foreach($earned as $grp=>$scores) {
		$possible = $scores['missed'] + $scores['earned'] + $scores['future'];
		if ($possible > 0) {
			$m += ($scores['weight'] / $weight_denom) * ($scores['missed'] / $possible);
			$e += ($scores['weight'] / $weight_denom) * ($scores['earned'] / $possible);
		}
	}
	$f = 1-$m-$e;
	
	$ans = '';
	if ($f < 0.001) {
		$ans .= "<div><strong>Grade</strong>: ".(round(1000000*$e / ($m+$e))/10000)."% = ".letterOf($e / ($m+$e), True)."</div>";
	} else if ($m + $e != 0) {
		$ans .= "<div><strong>Grade so far</strong>: ".(round(1000000*$e / ($m+$e))/10000)."% = ".letterOf($e / ($m+$e), True)." (with ".round($f*100, 1)."% of the course still to go)</div>";
	} else $ans .= "<div>Nothing has been graded yet...</div>";
	foreach($earned as $grp=>$details) {
		if (array_key_exists('drop', $details) && count($details['drop']) > 0) {
			$n = count($details['drop']);
			if ($n == 1) $n = '';
			$ans .= "<div><strong>Dropped:</strong> $n lowest $grp score".($n == '' ? '' : 's')."</div>";
		}
	}
	if (count($excused) > 0) $ans .= "<div><strong>Excused:</strong> ".implode(", ", $excused)."</div>";
	if (count($ungrouped) > 0) $ans .= "<div><strong>Not configured:</strong> ".implode(", ", $ungrouped)." (this usually means your professor has not properly set up this site)</div>";
	if (count($zero) > 0) $ans .= "<div title='Elided items are those given 0 weight per course grading policy'><strong>Non-credit exercises:</strong> ".implode(", ", $zero)."</div>";
	if (count($extra_notes) > 0) {
		$ans .= "<div><strong>Grade adjustments:</strong>";
		foreach($extra_notes as $slug=>$notes) {
			$diff=$notes[0];
			if ($diff < 0) $ans .= '<br/>&nbsp; &nbsp; &minus;'.(-$diff)." on $slug; $notes[1]";
			else $ans .= '<br/>+'.($diff)." on $slug; $notes[1]";
		}
		$ans .= "</div>";
	}
	if ($f < 1) {
		$ans .= "<table class='nopad' style='width:100%'><tbody><tr><td><strong>Progress:</strong>&nbsp;</td><td width='100%'>";
		$ans .= "<div class='xp-bar'>";
		$ans .= "<span class='xp-earned' style='width:".($e*100)."%;' title='points awarded: ".(floor($e*1000)/10)."%'></span>";
		$ans .= "<span class='xp-future' style='width:".($f*100)."%;' title='points pending: ".(floor($f*1000)/10)."%'></span>";
		$ans .= "<span class='xp-missed' style='width:".($m*100)."%;' title='points missed: ".(ceil($m*1000)/10)."%'></span>";
		$ans .= "</div>";
		$ans .= "</td></tr></tbody></table>";
	}
	
	$ans .= "<dl>"; 
	foreach($detailed as $grp=>$finished) {
		$possible = $earned[$grp]['missed'] + $earned[$grp]['earned'] + $earned[$grp]['future'];
		if ($possible > 0) {
			$e = $earned[$grp]['earned']*100*$earned[$grp]['weight'] / $possible;
		} else { $e = 0; }
		

		$ans .= "<dt>$grp ($e of ".(100*$earned[$grp]['weight'])."%)</dt><dd><table><tr><th>Task</th><th>Got</th><th>Of</th><th>Percent</th></tr>";
		foreach($finished as $task=>$did) {
			$ans .= "<tr><td>$task</td><td>$did[earned]</td><td>$did[outof]</td><td>".$did['earned']*100/$did['outof']."%</td></tr>";
		}
		$ans .= "</table></dd>";
	}
	$ans .= "</dl>";

	return $ans;
}


$_grade_map = null;
/**
 * Creates the non-user-specific parts of the grade_map()
 */
function base_grade_map() {
	global $_grade_map;
	if ($_grade_map === null) {
		$_grade_map = array();
		$cg = coursegrade();
		foreach($cg['weights'] as $grp=>$weight) {
			$_grade_map[$grp] = array("weight"=>$weight,"earned"=>0,"missed"=>0,"future"=>0,"items"=>array());
		}
		foreach($cg['drops'] as $grp=>$count) {
			$_grade_map[$grp]['drop'] = $count;
		}
		foreach(assignments() as $slug=>$details) {
			$grp = $details['group'];
			if (!array_key_exists($grp, $_grade_map)) continue; // $_grade_map[$grp] = array("weight"=>0,"items"=>array());
			if (!array_key_exists('weight', $details)) $details['weight'] = 1;
			if (time() < closeTime($details)) {
				$_grade_map[$grp]['future'] += $details['weight'];
				$_grade_map[$grp]['items'][$slug] = array("weight"=>0,"notes"=>"still open");
			} else {
				$_grade_map[$grp]['items'][$slug] = array("weight"=>$details['weight']);
			}
		}
	}
	$ans = $_grade_map;
	return $ans;
}
/**
 * For a given user, computes the full grade so far:
{
    "earned": 3.866,
    "future": 1.26,
    "missed": 8.224,
    "grade": 0.31976840363937,
    "letter": "F",
    "details": {
        "Exam": {
            "weight": 0.5,
            "earned": 2.115,
            "missed": 1.215,
            "future": 0,
            "items": {
                "Exam 1": {
                    "weight": 1,
                    "score": 0.98
                },
                "Exam 2": {
                    "weight": 1,
                    "notes": "no submission",
                    "score": 0
                },
                "Exam 3": {
                    "weight": 1.33,
                    "score": 0.85338345864662
                }
            }
        },
        "PA": { ... }
}
 * Other elements:
 * "drop":int
 * "drooped":[int, int, ...] // array of missed points excused
 * 
 * Notes:
 * no submission (score 0, normal weight)
 * excused (weight 0)
 * ungraded (submitted but no grade recorded yet; weight 0)
 * extended (due, but this student has an extension; weight 0)
 * still open (not yet due; weight 0)
 * whatever is written in an .adjustments file (normal weight)
 */
function grade_map($user) {
	$ans = base_grade_map();
	foreach($ans as $k=>&$v) {
		$earned = 0;
		$possible = 0;
		$droppers = array(); if (array_key_exists('drop',$v)) while(count($droppers) < $v['drop']) $droppers[] = 0;
		foreach($v['items'] as $slug=>&$results) {
			if ($results['weight'] == 0) continue;
			if (file_exists("uploads/$slug/$user/.extension")) {
				$details = assignments()[$slug] + json_decode(file_get_contents("uploads/$slug/$user/.extension"), TRUE);
				if (time() < closeTime($details)) {
					$results['notes'] = 'extended';
					$v['future'] += $results['weight'];
					$results['weight'] = 0;
					continue;
				}
			}
			if (!file_exists("uploads/$slug/$user")) {
				$results['notes'] = 'no submission'; $results['score'] = 0;

				$earn = $results['weight']*$results['score'];
				$miss = $results['weight']*(1-$results['score']);
				if (count($droppers) && $miss > $droppers[0]) {
					$diff = $miss - $dropers[0];
					$droppers[0] = $miss;
					$earn += $diff;
					$miss -= $diff;
					rsort($droppers);
				}
				$v['earned'] += $earn;
				$v['missed'] += $miss;
				
			} else if (file_exists("uploads/$slug/$user/.excused")) {
				$results['notes'] = 'excused'; $results['weight'] = 0; 
			} else if (file_exists("uploads/$slug/$user/.grade")) { 
				$got = json_decode(file_get_contents("uploads/$slug/$user/.grade"), true);
				if (!array_key_exists('grade', $got)) {
					totalGradeAndAddPool($got);
					file_put_contents("uploads/$slug/$user/.grade", json_encode($got));
				}
				if (file_exists("uploads/$slug/$user/.adjustment")) {
					$adj = json_decode(file_get_contents("uploads/$slug/$user/.adjustment"), true);
					$cap = 1;
					if (array_key_exists('extra', $adj)) $cap = $adj['extra'];
					$ng = min($adj['mult']*$got['grade'], $cap);
					if (($ng != $got['grade'])) {
						$results['notes'] = $adj['comments'];
						$got['grade'] = $ng;
					}
				}
				$results['score'] = $got['grade'];
				$earn = $results['weight']*$results['score'];
				$miss = $results['weight']*(1-$results['score']);
				if (count($droppers) && $miss > $droppers[0]) {
					$diff = $miss - $droppers[0];
					$droppers[0] = $miss;
					$earn += $diff;
					$miss -= $diff;
					rsort($droppers);
				}
				$v['earned'] += $earn;
				$v['missed'] += $miss;
			} else {
				$v['future'] += $results['weight'];
				$results['notes'] = 'ungraded'; $results['weight'] = 0;
			}
		}
		if (array_key_exists('drop', $v)) $v['drop_worth'] = $droppers;
	}
	$e = 0; $m = 0; $f = 0;
	foreach($ans as $g=>$d) {
		$e += $d['weight'] * $d['earned'];
		$m += $d['weight'] * $d['missed'];
		$f += $d['weight'] * $d['future'];
	}
	return array(
		'earned'=>$e,
		'future'=>$f,
		'missed'=>$m,
		'grade'=>($e + $m > 0 ? $e / ($e + $m) : 0),
		'letter'=>($e + $m > 0 ? letterOf($e / ($e + $m), True) : 'F'),
		'details'=>$ans
	);
}


?>
