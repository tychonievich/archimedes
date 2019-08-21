<?php header('Content-Type: text/html; charset=utf-8'); 
include "tools.php";
logInAs();

$slug = $_GET['task'];
if (!array_key_exists($slug, assignments())) {
    die("<p>Malformed request; task <q><tt>".htmlspecialchars($slug)."</tt></q> invalid.</p><script>setTimeout(function(){window.location = window.location.href.replace(/\/[^\/]*$/g, '');}, 5000);</script>");
}

?>﻿<!DOCTYPE html>
<html><head>
    <title><?=$slug?> – <?=$metadata['title']?> – <?=$me['name']?></title>
    <link rel="stylesheet" href="display.css" type="text/css"></link>
    <script type="text/javascript" src="codebox_<?=array_key_exists("code-lang",$metadata)?$metadata["code-lang"]:"py"?>.js"></script>
    <script src="dates_collapse.js"></script>
</head><body onload="dotimes(); docollapse(); highlight();">
<?php


$details = asgn_details($user, $slug);

$plain_str = $_GET;
if (array_key_exists('submitted', $plain_str)) { unset($plain_str['submitted']); }
$plain_str = http_build_query($plain_str);
$ext = "&$plain_str";
$end = "?$plain_str";



function accept_submission() {
    global $user, $details, $slug, $metadata, $isstaff, $isself;
    if (!array_key_exists('submission', $_FILES)) return;
    if (count($_FILES['submission']['error']) == 1 && $_FILES['submission']['error'] == UPLOAD_ERR_NO_FILE) {
        user_error_msg("Upload action received, but no file was sent by your browser. Please try again.");
        return;
    }
    if (!array_key_exists('slug', $_POST) || ($_POST['slug'] != $_GET['task'])) {
        user_error_msg("Received file upload without an associated assignment, which shouldn't be possible; if this persists, please email your professor, describing what you did to get this message, and attaching the file(s) to your email.");
        return;
    }
    if (!array_key_exists('files', $details)) {
        user_error_msg("Tried to uploaded files for <strong>$slug</strong>, which does not accept uploads.");
        return;
    }
    if (assignmentTime('open', $details) > time() && !($isstaff && $isself)) {
        user_error_msg("Tried to upload files for <strong>$slug</strong>, which is not yet open.");
        return;
    }
    if (closeTime($details) < time() && !($isstaff && $isself)) {
        user_error_msg("Tried to upload files for <strong>$slug</strong>, which has already closed.");
        return;
    }
    



    $now = date_format(date_create(), "Ymd-His");
    $realdir = "uploads/$slug/$user/.$now/";
    $linkdir = "uploads/$slug/$user/";
    $got = false;
    foreach($_FILES['submission']['name'] as $i=>$fname) {
        $name = $_FILES['submission']['name'][$i];
        $error = $_FILES['submission']['error'][$i];
        $tmp = $_FILES['submission']['tmp_name'][$i];
        $got = true;
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
            user_error_msg("Failed to receive <tt>".htmlspecialchars($name)."</tt> because of an unexpected problem; try a second time, and if the problem persists email your professor the entire text of this error message and attach the file in question to your email.");
            continue;
        }
        if (file_exists($linkdir . $name)) {
            rename($linkdir . $name, $linkdir . '.backup-' . $name);
        }
        file_put($linkdir . '.latest', $fname);
        if (!link($realdir . $name, $linkdir . $name)) {
            user_error_msg("Received <tt>".htmlspecialchars($name)."</tt> but failed to put it into the right location to be tested (not sure why; please report this to your professor).");
            continue;
        }
        user_success_msg("Received <tt>".htmlspecialchars($name)."</tt> for <strong>$slug</strong>. File contents as uploaded shown below:" . studentFileTag("uploads/$slug/$user/$name"));
        $got = true;
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
        if (file_exists($linkdir . '.latefeedback')) unlink($linkdir . '.latefeedback');
        if (file_exists($linkdir . '.autograde')) unlink($linkdir . '.autograde');
        if (array_key_exists('no-queue', $metadata) && array_key_exists($details['group'], $metadata['no-queue'])) {
            $msg = $metadata['no-queue'][$details['group']];
            if (strlen($msg) > 0) {
                user_notice_msg("$msg.");
            }
        } else {
            if (!ensure_file("meta/queued/$slug-$user", ".$now")) {
                user_notice_msg("Failed to queue <tt>".htmlspecialchars($name)."</tt> for automated feedback (not sure why; please report this to your professor).");
                continue;
            }
        }
        if (file_exists($linkdir . '.backup-' . $name)) {
            unlink($linkdir . '.backup-' . $name);
        }
    }
    $details = asgn_details($user, $slug); // rebuild to see submissions
    return $got;
}



function accept_extension() {
    global $user, $slug, $details;
    if (array_key_exists('extension_request', $_POST) && strlen(trim($_POST['extension_request'])) > 0 && (!array_key_exists('submission', $_FILES) || count($_FILES['submission']) == 0)) {
        if (!array_key_exists('slug', $_POST) || ($_POST['slug'] != $_GET['task'])) {
            user_error_msg("Received request without an associated assignment, which cannot be processed by this site. Please email your professor directly.");
        } else {
            if (file_exists("meta/requests/extension/$slug-$user")) {
                user_notice_msg("New extension request replacing old for $slug.");
            }
            if (!file_put("meta/requests/extension/$slug-$user", $_POST['extension_request'])) {
                user_error_msg("Internal server error prevented request from being posted. Please email your professor directly.");
            } else {
                user_success_msg("Extension request for <strong>$slug</strong> posted; it will be reviewed and either your deadlines will change on this site or notice of non-extension will be posted as a comment below. In most cases you will receive no notice of a decision other than a change to this site.");
                $details['.ext-req'] = $_POST['regrade_request'];
            }
        }
    } // end extension request posting

    if (array_key_exists('regrade_request', $_POST) && (!array_key_exists('submission', $_FILES) || count($_FILES['submission']) == 0)) {
        if (!array_key_exists('slug', $_POST) || ($_POST['slug'] != $_GET['task'])) {
            user_error_msg("Received request without an associated assignment, which cannot be processed by this site. Please email your professor directly.");
        } else if (strlen($_POST['regrade_request']) < 12) {
            user_error_msg("Requests must include more detail.");
        } else {
            if (file_exists("meta/requests/regrade/$slug-$user")) {
                user_notice_msg("New request replacing old for $slug.");
            }
            if (!file_put("meta/requests/regrade/$slug-$user", $_POST['regrade_request'])) {
                user_error_msg("Internal server error prevented request from being posted. Please email your professor directly.");
            } else {
                user_success_msg("Request to review feedback on <strong>$slug</strong> posted; it will be reviewed and a response posted below. In most cases you will receive no notice of a decision other than a change posted there.");
                $details['.regrade-req'] = $_POST['regrade_request'];
            }
        }
    } // end regrade request posting

}


function roll_back() {
    global $user, $slug, $details;
    if (array_key_exists("make_live", $_POST)) {
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
            if (file_exists("$dname/.autograde")) unlink("$dname/.autograde");
            if (file_exists("$dname/.grade")) unlink("$dname/.grade");
            link($_POST['make_live'], "$dname/$fname");
            file_put("$dname/.latest", $fname);
            ensure_file("meta/queued/$slug-$user", basename($dname));
            user_success_msg("roll-back completed: <tt>$dname/$fname</tt> now aliases <tt>$_POST[make_live]</tt>, any previous feedback has been removed, and the autograder has been queued to review <tt>meta/queued/$slug-$user</tt>.");
        }
    } // end roll-back posting
}

if (array_key_exists('CONTENT_LENGTH', $_SERVER) && floatval($_SERVER['CONTENT_LENGTH']) > (1<<27)) {
    user_error_msg("You appear to have attempted to send some very large file, which our server's security settings caused us not to receive.");
}
if (!accept_submission() && array_key_exists('submitted', $_GET) && $_GET['submitted']) {
    user_error_msg("You appear to have attempted to submit something for ".$_GET['submitted']." but no file arrived. This sometimes happens if you had the submission page open for an extended time before attempting to submit; please try submitting again.");
}
accept_extension();
roll_back();

if (array_key_exists('submitted', $_GET) && $_GET['submitted']) {
    echo "<p>Return to <a href='task.php$end'>this assignment's submission page</a> or the <a href='index.php$end'>assignments list</a> or the <a href='$metadata[url]'>main course page</a>.</p>";
    die('</body></html>');
}


function show_grade($gradeobj) {
    if (!$gradeobj || !array_key_exists('kind', $gradeobj))
        return "<div class='xp-missed'>It appears the grade data on the server is malformed. Please visit Piazza, search to see if someone else has already reported this, and if not make an open question there identifying the task for which this message appeared.</div>";
    $ans = array();
    if ($gradeobj['kind'] == 'percentage') {
	$ans[] = '<table class="feedback"><tbody>';
	$score = $gradeobj['ratio'];
    } else {
	$ans[] = '<table class="feedback"><tbody>';

	// correctness
	if ($gradeobj['auto-weight'] > 0) {
	    $score = $gradeobj['auto'];
	    _show_grade_obj_row($ans, $score, "Functional correctness (as determined by test cases)", true);
	    $lat = array_key_exists('auto-late', $gradeobj) ? $gradeobj['auto-late'] : $score;
	    if ($lat > $score) {
		_show_grade_obj_row($ans, $lat, "Late submission functional correctness (as determined by test cases)", true);
		$pen = array_key_exists('late-penalty', $gradeobj) ? $gradeobj['late-penalty'] : 0.5;
		$score = $score + ($lat-$score)*$pen;
	    }
	    $ans[] = '<tr class="break"><td colspan="2"></td></tr>';
	} else { $score = 0; }

	// staff feedback
	$human = 0;
	$human_denom = 0;
	foreach($gradeobj['human'] as $entry) {
	    $r = $entry['ratio'];
	    $human += $entry['weight'] * $r;
	    $human_denom += $entry['weight'];
	    _show_grade_obj_row($ans, $r, $entry['name']);
	}
    }
    // comment
    if (array_key_exists('comments', $gradeobj))
	_show_grade_obj_row($ans, false, $gradeobj['comments']);

    if ($gradeobj['kind'] == 'percentage') {
	_show_grade_obj_row($ans, $score, 'Score before adjustments', true);
    }
    
    $ans[] = '<tr class="break"><td colspan="2"></td></tr>';

    // combined
    if ($gradeobj['kind'] == 'percentage') {
    } else {
	$aw = array_key_exists('auto-weight', $gradeobj) ? $gradeobj['auto-weight'] : 0.5;
	$score = $human/$human_denom*(1-$aw) + $score*$aw;
    }
    if (array_key_exists('.mult', $gradeobj)) {
        // (with multiplier)
        _show_grade_obj_row($ans, $gradeobj['.mult']['ratio'], $gradeobj['.mult']['comments'], true, '× ');
        $score *= $gradeobj['.mult']['ratio'];
    }
    if (array_key_exists('.adjustment', $gradeobj)) {
        // (with multiplier)
        _show_grade_obj_row($ans, $gradeobj['.adjustment']['mult'], $gradeobj['.adjustment']['comments'], true, '× ');
        $score *= $gradeobj['.adjustment']['mult'];
    }
    _show_grade_obj_row($ans, $score, 'Overall score', true);
    
    $ans[] = '</tbody></table>';
    return implode('', $ans);
}
function _show_grade_obj_row(&$ans, $ratio, $comment, $percent=False, $prefix='') {
    $ans[] = '<tr>';
    if ($ratio !== FALSE) {
	$ans[] = '<td class="';
	$ans[] = (($ratio >= 1) ? 'full' : ($ratio > 0 ? 'partial' : 'no'));
	$ans[] = ' credit"';
	$ans[] = '>';
	$ans[] = $prefix;
        if ($percent) {
            $ans[] = round($ratio*100, 1);
            $ans[] = '%';
        } else {
            $ans[] = ($ratio >= 1) ? 'Yes!' : ($ratio > 0 ? 'Kind‑of' : 'No');
        }
	$ans[] = '</td>';
	$ans[] = '<td style="white-space: pre-wrap">';
    } else {
	$ans[] = '<td style="white-space: pre-wrap; text-align: left" colspan="2">';
    }
    $ans[] = htmlspecialchars($comment);
    $ans[] = '</td></tr>';
}



function grader_fb($details) {
    global $metadata;
    echo "<div class='hide-outer'><strong class='hide-header'>$metadata[grader] feedback</strong><div class='hide-inner'>";
    echo show_grade($details['grade']);
    echo '</div></div>';
}
function testcase_fb($details) {
    if (!array_key_exists('details', $details['autograde']))
        return "Test case listing not enabled for $details[slug]; showing preliminary feedback instead.\n".preliminary_fb($details);
    
    echo '<div class="hide-outer"><strong class="hide-header">test case feedback</strong><div class="hide-inner">';
    if (array_key_exists('missed', $details['autograde'])
    && count($details['autograde']['missed']) > 0) {
        //* // show one test case
        echo '<p>As of ';
        echo prettyTime($details['autograde']['created']);
        echo ', passed ';
        echo count($details['autograde']['details']) - count($details['autograde']['missed']);
        echo ' test cases</p><p>One example failed test (there may be others): <code>';
        echo htmlspecialchars($details['autograde']['missed'][0]);
        echo '</code></p>';
        /*/ // show all test cases
        echo 'Failed test cases as of ';
        echo prettyTime($details['autograde']['created']);
        echo ':<ul><li><pre>';
        echo implode('</pre></li><li><pre>', $details['autograde']['missed']);
        echo '</pre></li></ul>';
        //*/
    } else {
        echo 'Passed all automated tests.'; 
    }
    echo '</div></div>';
}
function preliminary_fb($details) {
    global $isself, $isstaff;
    $fbdelay = 0;
    if (array_key_exists('fbdelay', $details)) $fbdelay = 60*60*$details['fbdelay'];
    if (($isstaff && $isself) || $details['autograde']['created'] < time()-$fbdelay) {
        echo '<div class="hide-outer"><strong class="hide-header">preliminary feedback</strong><div class="hide-inner"><pre class="feedback stdout">';
        echo $details['autograde']['feedback'];
        echo '</pre></div></div>';
    } else {
        echo '<div>Preliminary feedback will become available ';
        echo prettyTime($details['autograde']['created'] + $fbdelay);
        echo '</div>';
    }
}












// get times
$now = time();
$due = assignmentTime('due', $details);
$close = closeTime($details);
$open = assignmentTime('open', $details);

// meta-properties
$submitted = array_key_exists('.files', $details);
$submittable = (($open < $now && $close > $now) || hasStaffRole($me)) && array_key_exists('files', $details);
$show_cases =  $due < $now 
    && $submitted
    && !array_key_exists('.ext-req', $details);
$extendable = ($now < $due || !$submitted) 
    && array_key_exists('files', $details) 
    && !array_key_exists('.files', $details) 
    && !(array_key_exists('no-extension', $metadata) && array_key_exists($details['group'], $metadata['no-extension']));

$regradable = TRUE;
if (array_key_exists('no-regrade', $metadata) && array_key_exists($details['group'], $metadata['no-regrade']))
    $regradable = $metadata['no-regrade'][$details['group']];

// time category
$status = ($now < $open) ? 'is not yet open' 
        :( ($now < $due) ? 'is due ' . prettyTime($due)
        :( ($now < $close) ? 'was due '.prettyTime($due)
        :( 'has closed')));
// time category css class
$class = ((!$due || $open > $now) ? "pending" : ($due > $now ? "open" : ($close > $now ? "late" : "closed")));


// display basic information
echo "<h1>$slug – ";
if (array_key_exists('title', $details)) echo $details['title'];
echo "</h1>";

if (array_key_exists('link_description', $details)) {
    $link_description = $details['link_description'];
} else {
    $link_description = 'Task description';
}

if (array_key_exists('link', $details))
    if (substr($details['link'],0,2) == '//' || strpos($details['link'], '://') !== FALSE) {
        echo "<p><a href='$details[link]'>$link_description</a>.</p>";
    } else {
        echo "<p><a href='$metadata[writeup_prefix]$details[link]'>$link_description</a>.</p>";
    }
else if (array_key_exists('writeup', $details))
    echo "<p><a href='$metadata[writeup_prefix]$details[writeup]'>$link_description</a>.</p>";
else if (array_key_exists('title', $details)) {
    echo "<p><a href='$metadata[writeup_prefix]";
    echo strtolower($slug);
    echo "-";
    echo strtolower($details['title']);
    echo ".html'>$link_description</a>.</p>";
}
echo "<p>Return to <a href='index.php$end'>Assignments list</a> or <a href='$metadata[url]'>main course page</a>.</p>";

echo "<p>This assignment $status.</p>";

function file_download_link($name, $path) {
    $dl = rawurlencode(explode('/',$path,2)[1]);
    $mtime = prettyTime(filemtime($path));
    return "<a title='$name' href='download.php?file=$dl'>$name</a> $mtime";
}


// display submission status
echo '<div class="submissions">';
if ($submitted) {
    echo "Your files (<a href='view.php?file=$slug/$user$ext'>view all</a>) (<a href='download.php?file=$slug/$user$ext'>download all as .zip</a>):<ul class='filelist'>";
    foreach($details['.files'] as $name=>$path) {
        echo "<li>";
        echo file_download_link($name, $path);
        echo "</li>";
    }
    echo '</ul>';
} else if (array_key_exists('files', $details)) {
    echo 'You have not yet submitted this assignment.';
} else {
    echo "<p>Online submissions are not enabled for this assignment.</p>";
}
echo '</div>';



// display feedback
if ((($due < $now) || ($isstaff && $isself))
&& array_key_exists('grade', $details)
&& !array_key_exists('.ext-req', $details)) {
    grader_fb($details);
} else if (array_key_exists('.files', $details)) {
    if (($due < $now)
    && array_key_exists('autograde', $details)
    && !array_key_exists('.ext-req', $details)) {
        testcase_fb($details);
    } else if (array_key_exists('autograde', $details)
    && array_key_exists('feedback', $details['autograde'])) {
        preliminary_fb($details);
    }
}



// display upload tag
if ($submittable) {
    echo "<form action='$_SERVER[SCRIPT_NAME]?submitted=$slug$ext' method='post' enctype='multipart/form-data' class='$class'>
    <input type='hidden' name='slug' value='$slug'/>
    <p>You may ";
    if (array_key_exists('.files', $details) && count($details['.files']) > 0)
        echo 're';
    echo "submit ";
    $patterns = $details['files'];
    if (is_string($patterns)) $patterns = array($patterns);
    foreach($patterns as $i=>$s) {
        if ($i != 0) echo " or ";
        echo "<tt>";
        echo htmlspecialchars($s);
        echo "</tt>";
    }
    if (!$isself) {
        echo " for ";
        echo rosterEntry($user)['name'];
        echo " ($user)";
    }
    if ($class == 'late') {
	if (array_key_exists('late-policy', $details)) {
	    $late_days = (time() - assignmentTime('due', $details) + 60) / (60 * 60 * 24);
	    $late_days = floor($late_days);
	    $late_days_p1 = $late_days + 1;
	    $late_policy = $details['late-policy'];
	    if ($late_days >= count($late_policy)) {
		$late_days = count($late_policy) - 1;
	    }
	    $penalty = $late_policy[$late_days];
	    $penalty_percent = floor($penalty * 100.0);
	    echo ", though it is now late (estimated $penalty_percent% credit for $late_days_p1 day late submission)";
	} else {
	    echo ", though it is now late (see the course syllabus for what that means)";
	}
    }
    echo ":</p><center><input type='file' multiple='multiple' name='submission[]'/><input type='submit' name='upload' value='Upload file(s)'/></center></form>";
}


if (array_key_exists('.chat', $details)) {
    echo "<div class='group conversation'><div>Past conversations with course staff:</div>";
    foreach($details['.chat'] as $note) {
        echo '<pre class="';
        echo $note['user'] == $user ? 'request' : 'response';
        echo '">';
        echo htmlspecialchars($note['msg']);
        echo "</pre>";
    }
    echo "</div>";
}

// extension
if (array_key_exists('.ext-req', $details)) {
    echo "<div class='group conversation'>";
    echo '<p>You have a pending extension request awaiting faculty approval:</p>';
    // edit?
    echo '<pre class="request">';
    echo htmlspecialchars($details['.ext-req']);
    echo '</pre></div>';
} else if ($extendable) {
    echo "<div class='group extension'>";
    echo '<p>If special circumstances will prevent your submitting on time, please describe why and propose a new deadline:</p>';
    echo "<form action='$_SERVER[SCRIPT_NAME]$end' method='post' enctype='multipart/form-data'>
    <input type='hidden' name='slug' value='$slug'/>
    <textarea name='extension_request'></textarea><br/><input type='submit' value='request deadline extension'/>
    </form>";
    echo "</div>";
}

// regrade
if (array_key_exists('.regrade-req', $details)) {
    echo "<div class='group conversation'>";
    echo '<p>You have a pending feedback review request awaiting staff review:</p>';
    echo '<pre class="regrade-request">';
    echo htmlspecialchars($details['.regrade-req']);
    echo '</pre></div>';
    // edit?
} else if (array_key_exists('grade', $details)) {
    if ($regradable === TRUE) {
        echo "<div class='group regrade'>";
        echo '<p>If the above feedback does not correctly characterize your submission, please describe what we misunderstood:</p>';
        echo "<form action='$_SERVER[SCRIPT_NAME]$end' method='post' enctype='multipart/form-data'>
        <input type='hidden' name='slug' value='$slug'/>
        <textarea name='regrade_request'></textarea><br/><input type='submit' value='request review of feedback'/>
        </form>";
        echo "</div>";
    } else {
        echo "<div class='group regrade'>$regradable</div>";
    }
}


if ($isfaculty) {
    $subs = glob("uploads/$slug/$user/.2*");
    $sub_cnt = count($subs);
    if ($sub_cnt > 0) {
        sort($subs);
        echo "<div class='hide-outer hidden'><strong class='hide-header'>$sub_cnt submissions (faculty view only; excludes by-partner submission)</strong><div class='hide-inner'>";
        echo "<form action='$_SERVER[SCRIPT_NAME]$end' method='post' enctype='multipart/form-data'>";
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
        echo "</form>";
        echo "</div></div>";
    }
}


?>


</body></html>
