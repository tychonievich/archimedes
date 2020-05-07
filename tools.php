<?php
ini_set("auto_detect_line_endings", true);
ini_set("auto_detect_line_endings", "America/New_York");
// date_default_timezone_set

if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50400) {
    ini_set("magic_quotes_runtime", false);
}

//if (!isset($metadata))
$metadata = json_decode(file_get_contents('meta/course.json'), true);
if (!array_key_exists('grader', $metadata)) $metadata['grader'] = 'grader';
if (!array_key_exists('grading group', $metadata)) $metadata['grading group'] = 'grading group';
if (!array_key_exists('Grader', $metadata)) $metadata['Grader'] = ucfirst($metadata['grader']);
if (!array_key_exists('Grading group', $metadata)) $metadata['Grading group'] = ucfirst($metadata['grading group']);


/// The following array grants certain users access to the class even if you upload an empty roster or otherwise mess up the course setup. Feel free to add yourself to the set, but remove those it has now at your own risk.
$superusers = array(
    'lat7h'=>array('name'=>'Luther Tychonievich', 'role'=>'Admin'),
    'cr4bd'=>array('name'=>'Charles Reiss', 'role'=>'Admin'),
    'no TA'=>array('name'=>'no TA assigned', 'role'=>'Teaching Assistant'),
    'mst3k'=>array('name'=>'Mystery Theater', 'role'=>'Student', 'grader'=>'no TA'),
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
    if ($_roster !== False && array_key_exists($id, $_roster)) return $_roster[$id];
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
$_all_assignments = False;
function _filter_hidden($entry) { return !array_key_exists("hide", $entry) || !$entry["hide"]; }
/// Helper function to read the set of assignments (needed in almost every view)
function assignments($showhidden=false) {
    global $_assignments, $_all_assignments, $metadata;
    if ($_assignments === False) {
        $_all_assignments = json_decode(file_get_contents("meta/assignments.json"), true);
        foreach($_all_assignments as $k=>$v) {
            if (!array_key_exists('fbdelay', $v)) {
                $_all_assignments[$k]['fbdelay'] = array_key_exists('fbdelay', $metadata) ? $metadata['fbdelay'] : 2;
            }
        }
        $_assignments = array_filter($_all_assignments, _filter_hidden);
    }
    return $showhidden ? $_all_assignments : $_assignments;
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

/// applies letter to score
function letterOf($ratio, $html=False) {
    foreach(coursegrade()['letters'] as $pair) {
        foreach($pair as $letter=>$bottom) {
            if ($ratio >= $bottom) return $letter;
        }
    }
    if ($html && substr($letter, 1) == "-") return $letter[0] + "&minus;";
    return $letter;
}

// ensures everyone has a name and the "grader of" and "grades" links are symmetric
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

/// writes updated user information to both meta/rosters.json and users/$id.json
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

	$reader->rewind();

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
                unlink("users/$i.json");
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

/// A helper because rosters use several terms
function hasFacultyRole($me) {
    return array_key_exists('role', $me) && (
        $me['role'] == 'Instructor'
        || $me['role'] == 'Professor'
        || $me['role'] == 'Teacher'
        || $me['role'] == 'Admin'
    );
}
/// A helper because rosters use several terms
function hasStaffRole($me) {
    return array_key_exists('role', $me) && (
        hasFacultyRole($me)
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
    //} else if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1' || $_SERVER['REMOTE_ADDR'] == '[::1]' || $_SERVER['REMOTE_ADDR'] == 'localhost') {
        //$user = 'lat7h'; // testing
    } else {
        preFeedback("ERROR: you don't appear to be authenticated with NetBadge.");
        var_dump($_SERVER);
        leavePre();
        die("</body></html>\n");
    }

    if (($me = rosterEntry($user)) !== False) {
        if ($initial) {
            $isfaculty = hasFacultyRole($me);
            $isstaff = hasStaffRole($me);
        }
    } else {
        if (strlen($user) == 0) {
            preFeedback("For some reason, NetBadge said you have no computing ID.\nYou can probably fix this by clearing your cookies,\nor by visiting this site in a private (Firefox) or incognito (Chrome) browser window");
        } else {
            preFeedback("ERROR: user $user is not in our roster.");
        }
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
function ensure_file($path, $contents) {
    umask(0);
    if (!is_dir(dirname($path)))
        mkdir(dirname($path), 0777, true);
    if ($contents) {
        $ans = file_put_contents($path, $contents);
    } else {
        $ans = touch($path);
    }
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
    if ($close === False) {
        $close = assignmentTime('due', $assignmentDetails);
        if (array_key_exists('late-days', $assignmentDetails)) {
            $close += 24*60*60*$assignmentDetails['late-days']; // 24 hours != 1 day if DST in between... use anyway
        } else if (array_key_exists('late-policy', $assignmentDetails)) {
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


$_rubs = array();
function rubricOf($slug) {
    global $_rubs;
    if (array_key_exists($slug, $_rubs))
        return $_rubs[$slug];
    
    $aset = assignments();
    if (array_key_exists($slug, $aset) && array_key_exists('rubric', $aset[$slug]))
        $rubric = $aset[$slug]['rubric'];
    else if (file_exists("uploads/$slug/.rubric"))
	$rubric = json_decode(file_get_contents("uploads/$slug/.rubric"), true);
    else if (file_exists("uploads/.rubric"))
        $rubric = json_decode(file_get_contents("uploads/.rubric"), true);
    else if (file_exists("meta/rubric.json"))
        $rubric = json_decode(file_get_contents("meta/rubric.json"), true);
    else
        $rubric = array('kind'=>'percentage');
    
    $_rubs[$slug] = $rubric;
    return $rubric;
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
            //  return "<a href='$link' target='_blank'><img class='$classes width height' src='$link'/></a>";
        } else if (stripos($mime, 'text') !== FALSE && filesize($path) < 4 * 1024 * 1024) {
            $contents = file_get_contents($path);
            $contents = preg_replace('/[^\n\r \t!-~]/', '', $contents);
            return "<div class='$classes width'>File <a href='$link' target='_blank'><tt>$title</tt></a>: <input type='button' style='font-family:monospace' value='toggle visibility' onclick='e=this.nextSibling; e.setAttribute(\"style\", e.getAttribute(\"style\") ? \"\" : \"display:none\")'/><pre><code>" . htmlspecialchars($contents) . "</code></pre></div>";
        } else if (is_dir($path)) {
            $vlink = 'view.php?file='.rawurlencode(explode('/',$path,2)[1]);
            return "<div class='$classes width'>Directory <a href='$vlink'><tt>$title</tt></a></div>";
        } else {
            return "<div class='$classes width'>File <a href='$link' target='_blank'><tt>$title</tt></a> (no preview available)</div>";
        }
    }
}

/**
 * Dead code worth resuscitating...
 * 
 * This used to be used to enable having some groups have in-group-only tasks.
 * We stopped using that feature in the courses that are driving development,
 * and during refactors of the code stopped supporting it at all.
 * Hooking it back up again should be doable, but is not currently a priority
 */
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

function svg_progress_bar($ep, $fp, $mp) {
    $tot = $ep + $fp + $mp;
    $ep *= 100/$tot; $fp *= 100/$tot; $mp *= 100/$tot;
    $tmp = $ep+$fp;
    return "<svg width='100%' height='1em' viewBox='0 0 100 10' preserveAspectRatio='none'>
    <rect x='0' y='0' width='$ep' height='20' class='xp-earned'/>
    <rect x='$ep' y='0' width='$fp' height='20' class='xp-future'/>
    <rect x='$tmp' y='0' width='$mp' height='20' class='xp-missed'/>
    </svg>";
}


/**
 * The assignment details augmented with per-user information:
 * any extensions and excuses;
 * links to download submissions;
 * and various grade information, if present.
 * Added keys may include
 * - excused (boolean)
 * - weight (set of 0 if excused)
 * - grade (object from human grader)
 * - autograde (object from autograder)
 * - ontime (copy of autograde from before deadline)
 * - submissions (all individual submission directories)
 * - submitted (last submission time) -- 0 if no submission
 * - .files (files and their download links)
 * - .partners (array of partners)
 * - .ext-req (contents of extension request file)
 * - rubric (result of rubricOf)
 */
function asgn_details($student, $slug) {
    global $isstaff;
    $nopoints = array(
        'correctness' => 0,
        'feedback' => 'Did not submit',
        'missed' => array('did not submit'),
        'details' => array(array('name'=>'submission','correct'=>0,'weight'=>1,'feedback'=>'failed to submit')),
        'created' => time(),
    );

    $details = assignments()[$slug];
    if (!array_key_exists('rubric', $details))
        $details['rubric'] = rubricOf($slug);
    $details['student'] = $student;
    $details['slug'] = $slug;
    if (file_exists("uploads/$slug/$student/.extension"))
        $details = json_decode(file_get_contents("uploads/$slug/$student/.extension"), TRUE) + $details;
    if (file_exists("uploads/$slug/$student/.excused")) {
        $details['excused'] = TRUE;
        $details['weight'] = 0;
    }
    if ((!array_key_exists('withhold',$details) || $isstaff) && file_exists("uploads/$slug/$student/.grade"))
        $details['grade'] = json_decode(file_get_contents("uploads/$slug/$student/.grade"), TRUE);
    if (file_exists("uploads/$slug/$student/.gradetemplate"))
	$details['grade_template'] = json_decode(file_get_contents("uploads/$slug/$student/.gradetemplate"), TRUE);
    if (file_exists("uploads/$slug/$student/.autograde")) {
        $details['autograde'] = json_decode(file_get_contents("uploads/$slug/$student/.autograde"), TRUE);
        $details['autograde']['created'] = filemtime("uploads/$slug/$student/.autograde");
        if (array_key_exists('grade', $details) && (!array_key_exists('auto', $details['grade']) || $details['grade']['auto'] < $details['autograde']['correctness'])) { // HACK to deal with re-run tests
            $details['grade']['auto']  = $details['autograde']['correctness'];
        }
        // delay should be from submission, not feedback
    } else if (file_exists("uploads/$slug/$student/.autofeedback")) {
        $afb = json_decode(file_get_contents("uploads/$slug/$student/.autofeedback"), TRUE);
        $details['autograde'] = array(
            'feedback'=> $afb['stdout'],
            'created' => filemtime("uploads/$slug/$student/.autofeedback"),
        );
    } else if (closeTime($details) < time()) {
        $details['autograde'] = $nopoints;
    }
    
    if (!array_key_exists('weight', $details)) $details['weight'] = 1;
    
    // add list of submissions and last-not-late autograde
    $details['submissions'] = glob("uploads/$slug/$student/.2*", GLOB_ONLYDIR);
    $deadline = date(".Ymd-His", assignmentTime('due', $details));
    $ontime = False;
    $final = '';
    $dir = null;
    foreach($details['submissions'] as $dir2) {
        $dir = $dir2;
        if (strcmp(basename($dir), $deadline) <= 0) $ontime = $dir;
        $final = $dir;
        // to do: use stat(...)['ino'] == stat(...)['ino'] instead in case of roll-back
    }
    if ($ontime === False) $details['ontime'] = $nopoints;
    else if ($final != $dir) $details['ontime'] = json_decode(file_get_contents("$ontime/.autograde"), TRUE);
    
    // add submission time
    $sentin = 0;
    foreach(glob("uploads/$slug/$student/*") as $path) {
        $feedback = 0;
        if (array_key_exists('feedback-files', $details)) {
            foreach($details['feedback-files'] as $pattern) {
                if (fnmatch($pattern, basename($path), FNM_PERIOD)) $feedback = 1;
            }
        }
        if ($feedback) continue;
        $t = filemtime($path);
        if ($t > $sentin) $sentin = $t;
    }
    if (file_exists("uploads/$slug/$student/.latest")) {
        $latest_lines = explode("\n",trim(file_get_contents("uploads/$slug/$student/.latest")));
        $details['.latest'] = $latest_lines[0];
        $details['.latest-subdir'] = $latest_lines[1];
        foreach (glob("uploads/$slug/$student/." . $details['.latest-subdir'] . "/*") as $path) {
            if (array_key_exists('feedback-files', $details)) {
                foreach($details['feedback-files'] as $pattern) {
                    if (fnmatch($pattern, basename($path), FNM_PERIOD)) $feedback = 1;
                }
            }
            if ($feedback) continue;
            $sentin = filemtime($path);
        }
    }
    $details['submitted'] = $sentin;
    $late_days = ($sentin - assignmentTime('due', $details)) / (60 * 60 * 24);
    $details['submitted-late-days'] = $late_days;
    if ($late_days > 0 && array_key_exists('late-policy', $details)) {
	$late_policy = $details['late-policy'];
        if (count($late_policy) > 0) {
            $late_days = floor($late_days);
            $late_days_p1 = $late_days + 1;
            if ($late_days >= count($late_policy)) {
                $late_days = count($late_policy) - 1;
            }
            $details['policy-late-penalty'] = $late_policy[$late_days];
            if (array_key_exists('grade', $details)) {
                if (array_key_exists('.adjustment', $details['grade'])) {
                    $details['grade']['.adjustment'] = array(
                        'kind' => 'percentage',
                        'mult' => $details['policy-late-penalty'] * $details['grade']['.adjustment']['mult'], # FIXME: duplicated
                        'comments' => $details['grade']['.adjustment']['comments'] . " and $late_days_p1 days late",
                    );
                } else {
                    $details['grade']['.adjustment'] = array(
                        'kind' => 'percentage',
                        'mult' => $details['policy-late-penalty'], # FIXME: duplicated?
                        'comments' => "$late_days_p1 days late",
                    );
                }
            }
        }
    }

    // add download links for current submissions
    $files = array();
    $feedback_files = array();
    foreach(glob("uploads/$slug/$student/*") as $path) {
        $files[basename($path)] = $path;
        if (array_key_exists('feedback-files', $details)) {
            foreach($details['feedback-files'] as $pattern) {
                if (fnmatch($pattern, basename($path), FNM_PERIOD)) {
                    $feedback_files[basename($path)] = $path;
                }
            }
        }
    }

    if (array_key_exists('extends', $details)) {
        foreach($details['extends'] as $slug2) {
            foreach(glob("uploads/$slug2/$student/*") as $path) {
                if (!array_key_exists(basename($path), $files)) {
                    $files[basename($path)] = $path;
                }
            }
        }
    }
    natcasesort($files);
    if (count($files) > 0)
        $details['.files'] = $files;

    if (count($feedback_files) > 0)
        $details['.feedback-files'] = $feedback_files;

    
    // add lists of partners
    $partners = array();
    if (file_exists("uploads/$slug/$student/.partners")) {
        foreach(explode("\n",trim(file_get_contents("uploads/$slug/$student/.partners"))) as $u2) {
            if ($u2 != $student) {
                $whom = rosterEntry($u2);
                if ($whom) $partners[] = "$whom[name] ($u2)";
            }
        }
    }
    if (count($partners) > 0)
        $details['.partners'] = $partners;

    // add conversation
    if (file_exists("uploads/$slug/$student/.chat"))
        $details['.chat'] = json_decode(file_get_contents("uploads/$slug/$student/.chat"), true);
    
    // add extension request
    if (file_exists("meta/requests/extension/$slug-$student")) {
        $details['.ext-req'] = file_get_contents("meta/requests/extension/$slug-$student");
    }
    // add regrade request
    if (file_exists("meta/requests/regrade/$slug-$student")) {
        $details['.regrade-req'] = '(feedback last updated '.date('Y-m-d H:i', filemtime("uploads/$slug/$student/.grade")).'; this request submitted '.date('Y-m-d H:i', filemtime("meta/requests/regrade/$slug-$student")).")\n".file_get_contents("meta/requests/regrade/$slug-$student");
    }

    // add post-hoc adjustments (to support legacy team project adjustment interface); probably worth refactoring as its current implementation is inconsistent withregrade interfaces, etc.
    if (file_exists("uploads/$slug/$student/.adjustment")) { // HACK: should probably refactor...
        $adj = json_decode(file_get_contents("uploads/$slug/$student/.adjustment"), true);
        $details['.adjustment'] = $adj;
        // fields: mult, comments
    }

    // tweak feedback delay to be based on submittion time, not feedback script runtime
    if (count($files) > 0 && array_key_exists('autograde', $details) && array_key_exists('created', $details['autograde'])) {
        $last = 0;
        foreach($files as $submitted) {
            $recent = filemtime($submitted);
            if ($recent > $last) $last = $recent;
        }
        if ($last) $details['autograde']['created'] = $last;
    }
    

    return $details;
}

/**
 * Given array of {"got":20.3, "of":31.5} pairs,
 * removes and returns $count of them to maximize score
 */
function dropper($count, &$scores) {
    $dropped = array();
    for(; $count>0 && count($scores) > 0; $count-=1) {
        $besti = -1;
        $bestloss = 0;
        foreach($scores as $i=>$obj) {
            $loss = $obj['of'] - $obj['got'];
            if ($loss > $bestloss) { $besti = $i; $bestloss = $loss; }
        }
        if ($besti >= 0) {
            $dropped[] = $scores[$besti];
            array_splice($scores, $besti, 1);
        }
    }
    return $dropped;
}

/**
 * Returns group information and sets detail information
 * 
 * {"PA":{"weight":40, "earned":15, "missed":34, "future":20}
 * ,"Lab":{"weight":10, "earned":20, "missed":0, "future":30}
 * }
 * 
 * Adds to $progress the following fields
 * 
 * .gcode = array subset of 'missed', 'excused', 'dropped', 'future', 'contested', 'graded', 'non-credit'
 * .score = number between 0 and 1
 */
function cumulative_status($student, &$progress=False, &$projected_score=False) {
    if ($progress === False) {
        $progress = array();
        foreach(assignments() as $slug=>$details) {
            $progress[$slug] = asgn_details($student, $slug);
        }
    }
    $cg = coursegrade();
    $ans = array();
    $now = time();
    foreach($progress as $slug=>&$details) {
        $gcode = array();
        // ensure this is a group we track and assignment with weight
        if (array_key_exists('excused', $details) && $details['excused']) {
            $gcode[] = 'excused';
            $details['.gcode'] = $gcode;
            continue;
        }
        if (!array_key_exists('group', $details) 
        || $details['weight'] == 0
        || !array_key_exists($details['group'], $cg['weights'])
        || $cg['weights'][$details['group']] < 0) {
            $gcode[] = 'non-credit';
            $details['.gcode'] = $gcode;
            continue;
        }
        if (!array_key_exists($details['group'], $ans))
            $ans[$details['group']] = array('past'=>array(), 'future'=>0);
        
        $future = closeTime($details) > $now || array_key_exists('.ext-req', $details);
        
        // handle missing submissions
        if (!$future                                // closed
        && !array_key_exists('grade', $details)     // not graded
        && array_key_exists('files',$details)       // expected files
        && !array_key_exists('.files', $details)) { // but not submitted
            $gcode[] = 'missed';
            $ans[$details['group']]['past'][] = array('slug'=>$slug, 'got'=>0, 'of'=>$details['weight']);
            $details['.gcode'] = $gcode;
            continue; 
        }
        
        // handle future tasks
        if (!array_key_exists('grade', $details)) {
            $gcode[] = 'future';
            $ans[$details['group']]['future'] += $details['weight'];
            $details['.gcode'] = $gcode;
            continue; 
        }
        
        // record submission
        $earned = score_of_task($details);
        $details['.score'] = $earned;
        $ans[$details['group']]['past'][] = array('slug'=>$slug, 'got'=>$earned*$details['weight'], 'of'=>$details['weight']);
        $gcode[] = 'graded';
        if (array_key_exists('.regrade-req', $details)) $gcode[] = 'contested';
        $details['.gcode'] = $gcode;
    }
    
    
    // drop assignments as needed
    if (array_key_exists('drops', $cg))
        foreach($cg['drops'] as $grp=>$cnt)
            if (array_key_exists($grp, $ans)) {
                $dropped = dropper($cnt, $ans[$grp]['past']);
                foreach($dropped as $obj) 
                    $progress[$obj['slug']]['.gcode'][] = 'dropped';
            }


    // reformat answer
    foreach($ans as $grp=>&$entry) {
        $earned = 0;
        $missed = 0;
        foreach($entry['past'] as $obj) {
            $earned += $obj['got'];
            $missed += $obj['of'] - $obj['got'];
        }
        $total = $entry['future'] + $earned + $missed;
        $entry['future'] /= $total;
        $entry['earned'] = $earned / $total;
        $entry['missed'] = $missed / $total;
        unset($entry['past']);
        $entry['weight'] = $cg['weights'][$grp];
    }
    
    // compute total if desired
    if ($projected_score !== FALSE) {
        $too_little_data = FALSE;
        $projected_score = 0;
        $projected_weight = 0;
        foreach($ans as $grp=>$scores) {
            if ($scores['weight'] != 0 && $scores['earned'] == 0 && $scores['missed'] == 0) {
                $too_little_data = TRUE;
            } else if ($scores['weight'] != 0) {
                $ep = $scores['earned'];
                $fp = $scores['future'];
                $mp = $scores['missed'];
                $projected_score += $scores['weight'] * 100*$ep / ($ep + $mp);
                $projected_weight += $scores['weight'];
            }
        }
        if ($too_little_data) $projected_score = 'insufficient data';
        else $projected_score = ($projected_score/$projected_weight);
    }
    
    return $ans;
}


/**
 * Given grade object, return a displayable summary of grade.
 * The grade object contains the following keys:

{"kind":"hybrid"
,"slug":"PA09"
,"student":"mst3k"
,"grader":"tj1a"
,"timestamp":1546318800
,"auto":0.7931034482758621
,"auto-late":0.9310344827586207
,"late-penalty":0.5
,"auto-weight":0.4
,"human":[{"weight":2,"ratio":0.5,"name":"good variable names"}
         ,{"weight":1,"ratio":1,"name":"proper indentation"}
         ,{"weight":1,"ratio":1,"name":"docstrings present"}
         ,{"weight":0,"ratio":0.5,"name":"well-formatted docstrings (will be worth points in later assignments)"}
         ,{"weight":1,"ratio":0,"name":"effort at reasonable design"}
         ,{"weight":1,"ratio":1,"name":"complicated parts (if any) properly commented"}
         ]
,"comments":"In the future, you might find docs.python.org/3/ useful"
,".mult":{"kind":"percentage","ratio":0.8,"comments":"professionalism penalty"}
,".adjustment":{"mult":1.25,"comments":"you did more than your fair share on this project"}
}

{"kind":"percentage"
,"slug":"Lab03"
,"ratio":23.0
,"comments":"Good job"
}

 */

function score_of_task($details) {
    $gradeobj = $details['grade'];
    if (!$gradeobj || !array_key_exists('kind', $gradeobj)) return NAN;
    if ($gradeobj['kind'] == 'percentage') {
	$score = $gradeobj['ratio'];
	if (array_key_exists('.mult', $gradeobj) && $score > 0.0) {
	    // (with multiplier)
	    $score *= $gradeobj['.mult']['ratio'];
	}
	if (array_key_exists('.adjustment', $gradeobj) && $score > 0.0) {
	    // (with multiplier)
	    $score *= $gradeobj['.adjustment']['mult'];
	}
        if (array_key_exists('.sub', $gradeobj)) {
            // (with subtraction)
            $score -= $gradeobj['.sub']['portion'];
        }
	return $score;
    }

    // correctness
    $score = $gradeobj['auto'];
    $lat = array_key_exists('auto-late', $gradeobj) ? $gradeobj['auto-late'] : $score;
    if ($lat > $score) {
        $pen = array_key_exists('late-penalty', $gradeobj) ? $gradeobj['late-penalty'] : 0.5;
        $score = $score + ($lat-$score)*$pen;
    } else {
        // if not better, use last score. The last will be the one seen during grading, so we need to grade based on it--otherwise overfitting followed by late clean-but-wrong would not be noticed.
        $score = $lat;
    }

    // code coach feedback
    $human = 0;
    $human_denom = 0;
    if (array_key_exists('human', $gradeobj)) {
        foreach($gradeobj['human'] as $entry) {
            $r = $entry['ratio'];
            $human += $entry['weight'] * $r;
            $human_denom += $entry['weight'];
        }
    }
    
    // combined
    $aw = array_key_exists('auto-weight', $gradeobj) ? $gradeobj['auto-weight'] : 0.5;
    $score = ($human_denom > 0 ? $human/$human_denom*(1-$aw) : 0) + $score*$aw;
    if (array_key_exists('.sub', $gradeobj)) {
        // (with subtraction)
        $score -= $gradeobj['.sub']['portion'];
    }
    if (array_key_exists('.mult', $gradeobj) && $score > 0.0) {
        // (with multiplier)
        $score *= $gradeobj['.mult']['ratio'];
    }
    if (array_key_exists('.adjustment', $gradeobj) && $score > 0.0) {
        // (with multiplier)
        $score *= $gradeobj['.adjustment']['mult'];
    }

    return $score;
}

?>
