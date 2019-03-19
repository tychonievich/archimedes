<?php
// accept asynchronous grade postings, comment set updates, and comment set queries without HTML content

include "tools.php";
$noPre = true; // turn off <pre></pre> stuff
logInAs();
if (!$isstaff) die("page restricted to staff");

$issuperuser = array_key_exists('supergraders', $metadata) 
    && in_array($user, $metadata['supergraders']);

if (array_key_exists('addgrade', $_REQUEST)) {
    // retrieve and validate message
    $grade = json_decode(file_get_contents("php://input"), true);
    if (!$grade) die ("invalid JSON payload received");
    if (!array_key_exists('slug', $grade) || !array_key_exists('student', $grade)) die ("grade payload missing required keys");
    $grade['timestamp'] = time();

    // log regrade chatter
    $reqfile = "meta/requests/regrade/$grade[slug]-$grade[student]";
    if (array_key_exists('regrade', $grade) && file_exists($reqfile)) {
        $chatfile = "uploads/$grade[slug]/$grade[student]/.chat";
        if (file_exists($chatfile)) $chatter = json_decode(file_get_contents($chatfile), true);
        else $chatter = array();

        $chatter[] = array(
            'user'=>$grade['student'],
            'show'=>rosterEntry($grade['student'])['name'],
            'kind'=>'regrade', 
            'msg'=>file_get_contents($reqfile),
        );
        unlink($reqfile);
        
        $chatter[] = array(
            'user'=>$user,
            'show'=>$me['name'],
            'kind'=>'regrade', 
            'msg'=>$grade['regrade'],
        );
        unset($grade['regrade']);
        file_put($chatfile, json_encode($chatter)) || die('failed to record decision (may be server permission error?)');
    }
    
    $rub = rubricOf($grade['slug']);
    if ($rub['kind'] != $grade['kind']) die("expected '$rub[kind]' (not '$grade[kind]') for $grade[slug]");
    if ($grade['kind'] == 'hybrid') {
        // add rubric details
        $grade['auto-weight'] = $rub['auto-weight'];
        $grade['late-penalty'] = $rub['late-penalty'];
        // and computed values
        $details = asgn_details($grade['student'], $grade['slug']);
        if (array_key_exists('ontime', $details)) {
            $grade['auto-late'] = $details['autograde']['correctness'];
            $grade['auto'] = $details['ontime']['correctness'];
        } else if (array_key_exists('autograde', $details)) {
            $grade['auto'] = $details['autograde']['correctness'];
        } else {
            $grade['auto'] = 0;
        }
        
        // and weights
        if (count($grade['human']) != count($rub['human'])) die('wrong number of entries in human grading');
        foreach($rub['human'] as $i=>$val) {
            if (!is_array($val)) $val = array('weight'=>1, 'name'=>$val);
            if ($grade['human'][$i]['name'] != $val['name']) die('rubric has changed');
            $grade['human'][$i]['weight'] = $val['weight'];
        }
    }
    
    // post to uploads/assignment/.gradelog and uploads/assignment/student/.grade
    $payload = json_encode($grade);
    file_put("uploads/$grade[slug]/$grade[student]/.grade", $payload)  || die('failed to record grade (may be server permission error?)');
    file_append("users/.graded/$user/$grade[slug]", "$grade[student]\n");
    if (file_exists("uploads/$grade[slug]/$grade[student]/.partners")) {
        foreach(explode("\n",file_get_contents("uploads/$grade[slug]/$grade[student]/.partners")) as $pair) {
            file_put("uploads/$grade[slug]/$pair/.grade", $payload);
            file_append("users/.graded/$user/$grade[slug]", "$pair\n");
        }
    }
    file_append("uploads/$grade[slug]/.gradelog", "$payload\n");

    // inform invoking method of success
    die(json_encode(array("success"=>True,"slug"=>$grade['slug'],"student"=>$grade['student'])));
}


?><?php
// useful functions

function percent_tag($id, $text, $percent, $comment) {
    return "<div class='percentage' id='$id'>
        <input type='text' id='$id|percent' value='$percent' size='4'/>% $text
        <div class='comment'><span>Comment:</span><textarea id='$id|comment'>$comment</textarea></div>
    </div>";
}

function item_tag($id, $name, $select=False) {
    if ($select !== False) {
        $sf = $select == 1.0 ? "checked='checked' " : "";
        $sp = $select == 0.5 ? "checked='checked' " : "";
        $sn = $select == 0.0 ? "checked='checked' " : "";
    } else {
        $sf = ''; $sp = ''; $sn = '';
    }
    return "<div class='item'>
        <label class='full'><input type='radio' name='$id' value='1.0' $sf/>1</label>
        <label class='partial'><input type='radio' name='$id' value='0.5' $sp/>½</label>
        <label class='none'><input type='radio' name='$id' value='0.0' $sn/>0</label>
        <span class='label'>$name</span>
    </div>";
}

function percent_tree($details) {
    $id = "$details[slug]|$details[student]";
    $text = 'correct';
    $ratio = array_key_exists('grade', $details) ? $details['grade']['ratio']*100 : '';
    $comment = array_key_exists('grade', $details) ? htmlspecialchars($details['grade']['comments']) : '';
    return percent_tag($id, $text, $ratio, $comment);
}

function hybrid_tree($details) {
    $id = "$details[slug]|$details[student]";
    
    if (array_key_exists('ontime', $details)) {
        $ontime = round($details['ontime']['correctness'] * 100, 1) . '% correct when due';
        $late = round($details['auograde']['correctness'] * 100, 1) . '% correct eventually';
    } else if (array_key_exists('autograde', $details)) {
        $ontime = round($details['autograde']['correctness'] * 100, 1) . '% correct';
        $late = '';
    } else {
        $ontime = '';
        $late = '';
    }
    
    $items = array();
    foreach($details['rubric']['human'] as $i=>$name) {
        if (is_array($name)) $name = $name['name'];
        if (array_key_exists('grade', $details)
        && array_key_exists('human', $details['grade'])
        && array_key_exists($i, $details['grade']['human'])
        && ($details['grade']['human'][$i]['name'] == $name))
            $select = $details['grade']['human'][$i]['ratio'];
        else $select = False;
        $items[] = item_tag("$id|$i", htmlspecialchars($name), $select);
    }
    $items = implode("\n            ", $items);
    
    $hasmult = array_key_exists('grade', $details) && array_key_exists('.mult',$details['grade']);
    $mult = percent_tag(
        "$id|mult", 
        "grade multiplier (e.g., for hard-coding, other prohibited activity)",
        $hasmult ? $details['grade']['.mult']['ratio']*100 : '',
        $hasmult ? htmlspecialchars($details['grade']['.mult']['comments']) : ''
    );
    
    $comment = (array_key_exists('grade', $details) && array_key_exists('comments', $details['grade'])) ? htmlspecialchars($details['grade']['comments']) : '';
    
    // FIXME: there is currently no way to handle .adjustment files from this interface
    
    return "<div class='hybrid' id='$id'>
        <div class='ontime' id='$id|ontime'>$ontime</div>
        <div class='late' id='$id|late'>$late</div>
        <div class='items' id='$id|items'>
            $items
        </div>
        <div class='comment'><span>Comment:</span><textarea id='$id|comment'>$comment</textarea></div>
        <div class='hide-outer hidden'><strong class='hide-header'>Multiplier (for special cases)</strong><div class='hide-inner'>
        $mult
        </div></div>
    </div>";
}


function grading_tree($details) {
    if ($details['rubric']['kind'] == 'hybrid') return hybrid_tree($details);
    if ($details['rubric']['kind'] == 'percentage') return percent_tree($details);
    if ($details['rubric']['kind'] == 'percent') return percent_tree($details);
    return "<div class='big error'><h1>Error!</h1>Unsupported rubric kind: $details[rubric][kind]</div>";
}

function student_screen($slug, $student, $nof='') {
    $details = asgn_details($student, $slug);
    
    //echo '<pre>';
    //var_dump($details);
    //echo '</pre>';
    
    // submissions
    $subs = array();
    if (!array_key_exists('.files', $details) || count($details['.files']) < 1)
        $subs[] = studentFileTag(false);
    else foreach($details['.files'] as $name=>$path)
        $subs[] = studentFileTag($path);
    
    // identifier
    $names = array(fullRoster()[$student]['name'] . " (<a href='task.php?task=$slug&asuser=$student' target='_blank'>$student</a>)");
    if (file_exists("uploads/$slug/$student/.partners"))
        foreach(explode("\n", file_get_contents("uploads/$slug/$student/.partners")) as $other)
            if ($other != $student) {
                $names[] = fullRoster()[$other]['name'] . " (<a href='task.php?task=$slug&asuser=$other' target='_blank'>$other</a>)";
            }

    // regrade conversation
    if (array_key_exists('.chat', $details) || array_key_exists('.regrade-req', $details)) {
        $rg = array("<div class='group conversation'><div>Regrade conversation:</div>");
        $keep = False;
        foreach($details['.chat'] as $note) if ($note['kind'] == 'regrade') {
            $keep = True;
            $rg[] = '<pre class="';
            $rg[] = $note['user'] == $student ? 'request' : 'response';
            $rg[] = '">';
            $rg[] =  htmlspecialchars($note['msg']);
            $rg[] = "</pre>";
        }
        if (array_key_exists('.regrade-req', $details)) {
            $keep = True;
            $rg[] = "<pre class='request'>";
            $rg[] = htmlspecialchars($details['.regrade-req']);
            $rg[] = "</pre>";
            $rg[] = "<strong>Response:</strong><textarea class='regrade-response' id='$slug|$student|regrade'></textarea>";
        }
        $rg[] = "</div>";
        if ($keep) $rg = implode('', $rg);
        else $rg = '';
    } else $rg = '';
    
    // grading tree -- single function below
    
    // secret info
    if (file_exists("uploads/$slug/$student/.secretfeedback")) {
        $secret = json_decode(file_get_contents("uploads/$slug/$student/.secretfeedback"),true);
        $secret = htmlspecialchars($secret['stdout']);
    } else if (array_key_exists('autograde',$details) && array_key_exists('details', $details['autograde'])) {
        // FIXME: show all test cases in detail
        if (array_key_exists('missed', $details['autograde']) && count($details['autograde']['missed']) > 0) {
            $secret = "Missed tests:\n- " . implode("\n- ", $details['autograde']['missed']);
        } else {
            $secret = 'passed every test case';
        }
    } else {
        $secret = '';
    }
    if (strlen($secret) > 0)
        $secret = "<textarea class='display' disabled='disabled'>$secret</textarea>";
    if (array_key_exists('autograde',$details) && array_key_exists('feedback', $details['autograde'])) {
        $fb = $details['autograde']['feedback'];
        $secret = "<textarea class='display' disabled='disabled'>$fb</textarea>$secret";
        
    }
    
    // assemble
    return implode('', array(
        "<table class='table-columns' id='table|$slug|$student'><tbody><tr><td>",
        implode('', $subs),
        "</td><td><div class='coltop'>You may <a href='download.php?file=$slug/$student'>download a .zip of submitted and tester files</a> for this student.",
        "<div class='student name'>",
        implode(' and ', $names),
        " $nof</div>$rg",
        grading_tree($details),
        "<input type='button' value='submit grade' onclick='grade(",
        json_encode("$slug|$student"),
        ")'/><input type='button' value='skip' onclick='skip(",
        json_encode("$slug|$student"),
        ")'/></div><hr/>$secret</td></tr></tbody></table>",
    ));
}


?><?php
header('Content-Type: text/html; charset=utf-8');
?>﻿<!DOCTYPE html>
<html><head>
    <title><?=$metadata['title']?> Grading View</title>
    <link rel="stylesheet" href="display.css" type="text/css"></link>
    <style>
        .linklist li { margin:1ex 0ex; }
        
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

input, textarea { padding:0ex; margin:0ex; font-size:100%; font-family:inherit; }
pre { margin:0em; }
    
input[type="text"] { border:0.125ex solid gray; background-color:inherit; padding:0.125ex; border-radius:0.5ex; }
input[type="button"] { border:0.125ex solid gray; background:#eeedeb; color:black; padding:0.125ex 1ex; border-radius:0.5ex; }
input[type="button"]:hover { background: white; color: black; }
input + input { margin-left:0.5ex; }

.regrade-response { margin:0.5ex; padding:0.5ex; width: calc(100% - 2ex); font-family: inherit; }

        .error:not(.big) { background-color: rgba(255,0,0,0.25); }
        
        .table-columns { margin:0em; border:0px solid black; padding:0em; }
        table.table-columns { border-collapse:collapse; margin:-1ex; }
        .table-columns td { padding:0ex; vertical-align:top; padding-right:1ex; }
        .table-columns td + td { padding:1ex; border-left:dotted thin; }

        table.table-columns.done, table.table-columns:not(.done) + table.table-columns, table.table-columns:not(.done) + #grading-footer { display:none; }

    </style>
    <script src="dates_collapse.js"></script>
    <script type="text/javascript" src="codebox_<?=array_key_exists("code-lang",$metadata)?$metadata["code-lang"]:"py"?>.js"></script>
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

/** parses the HTML dom to figure out what the actual grade JSON ought to be */
function _grade(id) {
    var root = document.getElementById(id);
    
    function check_percent(val, com, element) {
        if (!/\d+(\.\d+)?/.test(val))
        { element.classList.add('error'); throw new Error('Missing percentage'); }
        
        val = Number(val);
        if (val < 0 || val > 0 && val <= 1 || val > 100)
        { element.classList.add('error'); throw new Error('Invalid percentage'); }
        
        if (com.length < 4)
        { element.classList.add('error'); throw new Error('Too short comment'); }
        
        element.classList.remove('error');
        return val;
    }
    
    if (root.classList.contains('percentage')) {
        var correct = document.getElementById(id+'|percent').value;
        var comment = document.getElementById(id+'|comment').value;
        
        correct = check_percent(correct, comment, root);
        root.classList.remove('error');

        return {"kind":"percentage"
               ,"ratio":correct/100
               ,"comments":comment
               };
    } else if (root.classList.contains('hybrid')) {
        var ans = {kind:'hybrid', human:[]};

        document.getElementById(id).querySelectorAll('input[type="radio"]').forEach(function(x){
            var key = x.parentElement.parentElement.lastElementChild.innerHTML;
            var num = x.name.split('|');
            num = Number(num[num.length-1]);
            if (num >= ans.human.length) ans.human.push(null);
            if (x.checked) {
                ans.human[num] = {ratio:Number(x.value), name:key};
                x.parentElement.parentElement.classList.remove('error');
            }
        });
        var ok = true
        for(var i=0; i<ans.human.length; i+=1) if (ans.human[i] === null) {
            document.getElementById(id+'|items').children[i].classList.add('error');
            ok = false;
        }
        if (!ok) throw new Error('Missing some components');

        var comment = document.getElementById(id+'|comment').value;
        if (comment.length > 0) ans['comments'] = comment;

        var mult_correct = document.getElementById(id+'|mult|percent').value;
        var mult_comment = document.getElementById(id+'|mult|comment').value;
        if (mult_correct.length > 0) {
            mult_correct = check_percent(mult_correct, mult_comment, document.getElementById(id+'|mult'));
            ans['.mult'] = {"kind":"percentage"
                           ,"ratio":mult_correct/100
                           ,"comments":mult_comment
                           };
        }
        
        return ans;
    } else {
        alert('Grader script error: unexpected rubric kind '+JSON.stringify(root.classList));
    }
}

/** Callback for the "submit grade" button: tell the server, hide the student, and ask for new comments */
function grade(id) {
    var ans = {
        grader:"<?=$user?>", 
        slug:id.split('|',2)[0], 
        student:id.split('|',3)[1],
    }
    var tmp = _grade(id);
    for(var key in tmp) ans[key] = tmp[key];
    
    var rg = document.getElementById(id+"|regrade");
    if (rg) {
        if (rg.value.length < 4) { rg.classList.add('error'); throw new Error('Missing regrade'); }
        rg.classList.remove('error');
        ans.regrade = rg.value;
    }

    ajax(ans, 'addgrade=1&asuser=<?=$user?>'); // FIX ME: add checking of response code
    document.getElementById("table|"+id).classList.add('done');
}

/** hides the current student's work and moves on to the next one */
function skip(id, skipped=true) {
    document.getElementById("table|"+id).classList.add('done');
    if (skipped) {
        var foot = document.getElementById('grading-footer');
        foot.firstElementChild.classList.remove('success');
        foot.firstElementChild.classList.add('notice');
        foot.firstElementChild.firstElementChild.innerText = "All assignments either graded or skipped";
        foot.firstElementChild.lastElementChild.innerText = "You skipped some assignments; reload this page to see them.";
    }
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
</head><body onload="imageresize(); setUpCollapses(); (window.onfocus ? window.onfocus() : null); dotimes(); docollapse(); highlight();">
<?php

function gradeableTree($limit=False) {
    global $issuperuser;
    $ans = array();
    $everyone = fullRoster();
    foreach(assignments() as $slug => $details) {
        if ($limit && $slug != $limit) continue;
        $ct = closeTime($details);
        if ($ct == True && $ct < time() || $issuperuser) {
            foreach(glob("uploads/$slug/*") as $dir) {
                if (file_exists("$dir/.extension") 
                && closeTime(json_decode(file_get_contents("$dir/.extension"),true)) > time()) {
                    continue;
                }
                if (count(glob("$dir/*", GLOB_NOSORT)) == 0) { continue; } // no submission
                $sid = explode('/',$dir)[2];
                if (!array_key_exists($sid, $everyone)) continue; // non-student directory or file
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
        echo student_screen($slug, $_REQUEST['student']);
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
            $alltodo = toGrade($slug, $grader, $redo);
            foreach($alltodo as $i=>$student) {
                echo student_screen($slug, $student, " ".($i+1)." of ".count($alltodo));
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
    if ($issuperuser) {
        echo "<center><em>$me[name] has super-grader rights; showing open assignments and spot-check interface</em></center>";
    }
    echo '<h2>Pick an assignment:</h2>';
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



