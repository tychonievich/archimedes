﻿<?php header('Content-Type: text/html; charset=utf-8'); ?>﻿<!DOCTYPE html>
<html><head>
    <title>Archimedes Grade Viewer</title>
    <script type="text/javascript" src="columnsort.js"></script>
    <style>
        table.nopad { border-collapse: collapse; }
        table.nopad, .nopad tr, .nopad td { border: none; padding:0em; margin:0em; }
        .xp-bar { width: 100%; padding: 0em; white-space: pre; border: thin solid black; line-height:0%;}
        .xp-bar span { height: 1em; padding:0em; margin: 0em; border: none; display:inline-block; }
        .xp-earned { background: rgba(0,191,0,1); }
        .xp-missed { background: rgba(255,127,0,0.5); }
        .xp-future { background: rgba(0,0,0,0.125); }
        
        table.alternate { border-collapse: collapse; width:100%; }
        table.alternate td { padding: 1ex; }
        table.alternate tr:nth-child(2n) { background-color:rgba(255,127,0,0.125); }

        dd.collapsed { display:none; }
        dt.collapsed:before { content: "+ "; }
        dt.collapsed:after { content: " …"; }
        dt.collapsed { font-style: italic; }

        .xp-bar { width: 100%; padding: 0em; white-space: pre; border: thin solid black; line-height:0%;}
        .xp-bar span { height: 1em; padding:0em; margin: 0em; border: none; display:inline-block; }
        .xp-earned { background: rgba(0,191,0,1); fill:rgba(0,191,0,1); }
        .xp-missed { background: rgba(255,127,0,0.5); fill:rgba(255,127,0,0.5); }
        .xp-future { background: rgba(0,0,0,0.125); fill:rgba(0,0,0,0.125); }
    </style>
    <script>//<!--
function load() {
    sortcolumn('tbody',1,true);
    sortcolumn('tbody',2,true);
    setUpCollapses();
    
    document.querySelectorAll('table.alternate > tbody > tr').forEach(function(tr){ console.log(tr.children[0].innerText+','+tr.children[3].children[0].innerHTML.split(' ')[3]);})
}

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
        breakdowns[i].nextElementSibling.classList.add('collapsed');
        breakdowns[i].classList.add('collapsed');
    }
}

//--></script>
</head><body onload="load()">
<?php
include "tools.php";
logInAs();
if (!hasFacultyRole($me)) { die("<p>Only faculty may view this page</p></body></html>"); }


?>
<p>See also the <a href="gradesheet_csv.php">CSV version of the raw scores</a>.</p>
<table class="alternate"><thead><tr>
        <th onclick="sortcolumn('tbody',0,true)">ID ⇕</th>
        <th onclick="sortcolumn('tbody',1,true)">Name ⇕</th>
        <th onclick="sortcolumn('tbody',2,true)">Section ⇕</th>
        <th onclick="sortcolumn('tbody',3,true)">Grade ⇕</th>
        <th>Letter</th>
        <th onclick="sortcolumn('tbody',5,true)">Earned ⇕</th>
        <th>Progress</th>
</tr></thead>
<tbody id="tbody">
    <?php

foreach(fullRoster() as $id=>$details) {
    if (hasStaffRole($details)) continue;
    $section = array_key_exists('groups', $details) ? strpos($details['groups'], "-00") : 0;
    if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
    else { $section = ''; } 

    $final = 0;
    $ignore = False;
    $overall = cumulative_status($id, $ignore, $final);
    $ep = 0; $fp = 0; $mp = 0;
    foreach($overall as $grp=>$scores) {
        $ep += $scores['weight'] * $scores['earned'];
        $fp += $scores['weight'] * $scores['future'];
        $mp += $scores['weight'] * $scores['missed'];
    }
    $bar =  svg_progress_bar($ep, $fp, $mp);

    echo '<tr>';
    echo "<td><a href='index.php?asuser=$id'>$id</a></td>"; // ID
    echo "<td>$details[name]</td>"; // Name
    echo "<td>$section</td>"; // Groups
    echo "<td>$final</td><td>";
    echo letterOf($final/100, true);
    echo "<td>".sprintf("%05.2f",$ep*100.0)."</td>";
    echo "</td><td>$bar</td>";
    echo '</tr>';
}

?></tbody>
</table>

</body></html>

