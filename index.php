<?php header('Content-Type: text/html; charset=utf-8'); 
$metadata = json_decode(file_get_contents('meta/course.json'), true);
?>﻿<!DOCTYPE html>
<html><head>
    <title>Submissions – <?=$metadata['title']?></title>
    <link rel="stylesheet" href="display.css" type="text/css"></link>
    <script src="codebox_py.js"></script>
    <script src="dates_collapse.js"></script>
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
/* -- superceded by the ohq/roster.php photo upload
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
*/

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
        
        $reqfile = "meta/requests/extension/$_POST[extension_assignment]"."-"."$_POST[extension_student]";
        
        $chatfile = "uploads/$_POST[extension_assignment]/$_POST[extension_student]/.chat";
        if (file_exists($chatfile)) $chatter = json_decode(file_get_contents($chatfile), true);
        else $chatter = array();

        if (file_exists($reqfile)) {
            $chatter[] = array(
                'user'=>$_POST['extension_student'],
                'show'=>rosterEntry($_POST['extension_student'])['name'],
                'kind'=>'extension', 
                'msg'=>file_get_contents($reqfile),
            );
            unlink($reqfile);
        }
        
        $extendfile = "uploads/$_POST[extension_assignment]/$_POST[extension_student]/.extension";
        if ($_POST['extension_decision'] == 'Approve') {
            if (stripos($_POST['due'], 'now') !== False) {
                preFeedback("Note: relative times may have a timezone-sized offset incorrectly applied...");
            }
            $object = array();
            if (array_key_exists('due', $_POST) && strtotime($_POST['due']) !== False)
                $object['due'] = date('Y-m-d H:i', strtotime($_POST['due']. " America/New_York"));
            // TO DO: revisit late policy part
            if (array_key_exists('late', $_POST) && is_numeric(json_decode($_POST['late'], true))) 
                $object['late-days'] = json_decode($_POST['late'], true);
            else if (array_key_exists('late', $_POST) && is_array(json_decode($_POST['late'], true))) 
                $object['late-policy'] = json_decode($_POST['late'], true);
            $object['close'] = closeTime($object + assignments()[$_POST['extension_assignment']]); // needed to overwrite optional close in assignment itself
            if (!file_put($extendfile, json_encode($object))) preFeedback("Failed to write .extension file");
            else {
                preFeedback("Recorded new deadline of $object[due] (close time ".prettyTime($object['close']).") for $_POST[extension_assignment]/$_POST[extension_student]");
            }
            $chatter[] = array(
                'user'=>$user, 
                'show'=>$me['name'], 
                'kind'=>'extension', 
                'msg'=>'Set new deadline as '.$object['due'],
            );
        } else {
            $chatter[] = array(
                'user'=>$user, 
                'show'=>$me['name'], 
                'kind'=>'extension', 
                'msg'=>$_POST['rejection'],
            );
        }
        if (!file_put($chatfile, json_encode($chatter))) preFeedback("Failed to put decision into .chat");
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
echo "<h1>".($isself ? "Welcome," : "Viewing as")." <span class='name'>$me[name]</span> ($user)</h1><a style='text-align:center; display:block;' href='$metadata[url]'>Return to course page</a>\n";

if (!$isself) {
    echo "<img src='picture.php?user=$user' alt='no picture of $user found' class='snapshot'/>";
}

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
        if (file_exists("$dname/.latefeedback")) unlink("$dname/.latefeedback");
        if (file_exists("$dname/.autograde")) unlink("$dname/.autograde");
        link($_POST['make_live'], "$dname/$fname");
        ensure_file("meta/queued/$slug-$user", basename(dirname($_POST['make_live'])));
        if (file_exists("$dname/.grade")) {
            user_success_msg("roll-back completed: <tt>$dname/$fname</tt> now aliases <tt>$_POST[make_live]</tt>, and the autograder has been queued to review <tt>meta/queued/$slug-$user</tt>. Note, however, that this was previous graded; we advise <a href='grade.php?student=$user&assignment=$slug'>manually regrading</a>.");
        } else {
            user_success_msg("roll-back completed: <tt>$dname/$fname</tt> now aliases <tt>$_POST[make_live]</tt>, and the autograder has been queued to review <tt>meta/queued/$slug-$user</tt>");
        }
    }
} // end roll-back posting


leavePre();


// display extra information, if applicable
if (array_key_exists('groups', $me) && !($isstaff && $isself)) echo "<p>We list you as part of: $me[groups]</p>\n";
if (array_key_exists('grader_name', $me)) echo "<p>Your $metadata[grader] is $me[grader_name].</p>\n";




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
    Visit the <a href="codecoach.php"><?=$metadata['grading group']?> site</a>,
    the <a href="partners.php">project partner site</a>,
    or the <a href="grade.php">the grading site</a>.
    </div>
    <?php if ($isself && file_exists('meta/ta_feedback.json')) { ?><div class="action">See a <a href="tafeedback.php">snapshot of student feedback</a> about your office hour help as of <?=prettyTime( filemtime('meta/ta_feedback.json'))?>.</div><?php } ?>
    
    <?php
}
if ($isfaculty) {
    $extensions = array();
    $extensions_dates = array();
    foreach(glob("meta/requests/extension/*") as $request_path) {
        $request = basename($request_path);
        $i = strrpos($request, '-');
        $assignment_name = substr($request, 0, $i);
        $student_id = substr($request, $i+1);
        if (!array_key_exists($student_id, $extensions)) $extensions[$student_id] = array();
        $extensions[$student_id][$assignment_name] = file_get_contents($request_path);
        if (!array_key_exists($student_id, $extensions_dates)) $extensions_dates[$student_id] = array();
        $extensions_dates[$student_id][$assignment_name] = prettyTime(filemtime($request_path));
    }
    $n = count($extensions);
    if ($n > 0) {
        echo "<div class='hide-outer hidden important'><strong class='hide-header'>$n pending extension requests</strong><div class='hide-inner'>\n";
        echo "<dl>\n";
        foreach($extensions as $student_id => $requests) {
            $student_name = fullRoster()[$student_id]['name'];
            $student_sections = fullRoster()[$student_id]['groups'];
            echo "<dt>$student_name ($student_id) in sections $student_sections</dt><dd><dl>";
            foreach($requests as $assignment_name => $text) {
                $datetime = $extensions_dates[$student_id][$assignment_name];
                echo "<dt>$assignment_name (submitted $datetime)</dt><dd><pre class='rawtext'>";
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
                    and days of late period (number of days, like </code>2</code>): <input type='text' name='late'/>
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
    </form>
    <p>To link photos to these accounts, see <a href="../ohq/roster.php">the OHQ photo upload page</a>.</p>
    </div>

    <!--
    <div class="action"><form action='<?=$_SERVER['REQUEST_URI']?>' method='post' enctype="multipart/form-data">
    Upload <label> student (and staff) photos (.zip):
    <input type="file" name="photo_archive"/></label>
    <input type="submit"/>
    <br/>(You can use <a href="download.php?file=support/collab_photo.py">collab_photo.py</a> to create this archive)
    </form></div>
    -->

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

// get time
$now = time();

// preserve get arguments
$plain_str = $_GET;
if (array_key_exists('submitted', $plain_str)) { unset($plain_str['submitted']); }
if (array_key_exists('task', $plain_str)) { unset($plain_str['task']); }
$plain_str = http_build_query($plain_str);
$ext = "&$plain_str";
$end = "?$plain_str";


////////////////////////////////////////////////////////////////////////////////
////////////////////// collect user submission details /////////////////////////
$mine = array();
foreach(assignments() as $slug=>$details) {
    $mine[$slug] = asgn_details($user, $slug);
}
$overall = cumulative_status($user, $mine);

////////////////////////////////////////////////////////////////////////////////
//////////////////////// show cumulative performance ///////////////////////////

?><div class='hide-outer hidden'><strong class='hide-header'>Cumulative performance</strong><div class='hide-inner'>
<table class='vbar' style='width:100%; table-layout:fixed'>
<thead><tr><?php 
foreach($overall as $grp=>$details)
    echo "<th width='".(100*$details['weight'])."%'>$grp</th>";
?></tr></thead><tbody><tr><?php
foreach($overall as $slug=>$details) {
    $ep = $details['earned'];
    $fp = $details['future'];
    $mp = $details['missed'];
    echo "<td>";
    echo svg_progress_bar($ep, $fp, $mp);

    $per = ($details['earned']+$details['missed']);
    $left = round(100*$details['future']);
    if ($per == 0) {
        echo "<br/>$left% to go";
    } else {
        $per = round(100*$details['earned']/$per);
        echo "<br/>$per%, with $left% to go";
    }

    echo "</td>";
}
?></tr></tbody></table>
<a href="grade_breakdown.php<?=$end?>">View full grade computation</a>
</div></div>



<table class="assignments"><thead>
<tr><th>Task</th><th>Status</th><th>Deadline</th></tr>
</thead><tbody><?php
////////////////////////////////////////////////////////////////////////////////
////////////////////////// list assignments ////////////////////////////////////



// show assignments
foreach($mine as $slug=>$details) {

    // compute time status
    $due = assignmentTime('due', $details);
    $close = closeTime($details);
    $open = assignmentTime('open', $details);
    
    $class = ((!$due || $open > $now) ? "pending" : ($due > $now ? "open" : ($close > $now ? "late" : "closed")));

    $title = '';
    if (array_key_exists('title', $details)) $title = $details['title'];
    echo "<tr class='assignment $class'><td><a href='task.php?task=$slug$ext'><strong>$slug</strong> $title</a></td><td>";

    if (array_key_exists('excused', $details) && $details['excused']) echo 'excused';
    else if (array_key_exists('.ext-req', $details)) echo 'extension requested';
    else if (array_key_exists('.regrade-req', $details)) echo 'request awating review';
    else if ($class == 'closed') {
        if (array_key_exists('grade', $details)) echo 'full feedback available';
        else if (array_key_exists('files', $details) && !array_key_exists('.files', $details))
            echo 'not submitted';
        else echo 'awaiting feedback';
    } else if ($class == 'pending') echo 'not yet open';
    else if (!array_key_exists('files', $details)) echo 'not submittable online';
    else if (!array_key_exists('.files', $details)) echo 'not yet submitted';
    else if (array_key_exists('autograde', $details)) {
        if ($class == 'late') {
            echo 'test cases available';
        } else {
            $fbdelay = 0;
            if (array_key_exists('fbdelay', $details)) $fbdelay = 60*60*$details['fbdelay'];
            if (($isstaff && $isself) || $details['autograde']['created'] < time()-$fbdelay) {
                echo 'preliminary feedback available';
            } else {
                echo 'awaiting preliminary feedback';
            }
        }
    } else {
        echo 'awaiting feedback';
    }
    
    if (array_key_exists('.gcode', $details))
        foreach($details['.gcode'] as $code)
            if ($code == 'dropped' || $code == 'non-credit')
                echo " ($code)";

    echo '</td><td>';
    
    if ($open > $now) echo "opens " . prettyTime($open);
    else if ($due > $now) echo "due " . prettyTime($due);
    else if ($close > $now) echo "closes " . prettyTime($close);
    else echo "closed " . prettyTime($close);
    
    echo '</td></tr>';
}

?></tbody></table>
</body></html>
