<?php

header('Content-Type: text/csv; charset=utf-8'); 
include "tools.php";
logInAs();
if (!hasFacultyRole($me)) { die("Only faculty may view this page"); }

$header_shown = FALSE;
foreach(fullRoster() as $id=>$details) {
    if (hasStaffRole($details) || $id == 'mst3k') continue;
    $section = array_key_exists('groups', $details) ? strpos($details['groups'], "-00") : 0;
    if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
    else { $section = ''; } 


    $bits = FALSE;
    $overall = cumulative_status($id, $bits);
    //var_dump($details);
    //var_dump($overall);
    $ep = 0; $fp = 0; $mp = 0;
    foreach($overall as $grp=>$scores) {
        $ep += $scores['weight'] * $scores['earned'];
        $fp += $scores['weight'] * $scores['future'];
        $mp += $scores['weight'] * $scores['missed'];
    }
    $final = $ep + $mp > 0 ? 100*$ep / ($ep + $mp) : '';
    $bar =  svg_progress_bar($ep, $fp, $mp);

    if (!$header_shown) {
        echo "compid";
        echo ",name";
        echo ",section,";
        echo ",cumulative,";
        foreach($overall as $grp=>$scores) {
            echo ",$grp total [$scores[weight]]";
        }
        echo ",";
        foreach($overall as $grp=>$scores)
            foreach($bits as $slug=>$data)
                if ($data['group'] == $grp)
                    echo ",$slug";
        echo "\n";
        $header_shown = TRUE;
    }

    echo $id;
    echo ",\"$details[name]\""; // Name
    echo ",\"$section\","; // Groups
    echo ",$final,"; // cumulative
    foreach($overall as $grp=>$scores) {
        echo "," . ($scores['earned']/($scores['earned']+$scores['missed']));
    }
    echo ",";
    foreach($overall as $grp=>$scores)
        foreach($bits as $slug=>$data)
            if ($data['group'] == $grp)
                echo ",".$data[".score"];
    echo "\n";
}
?>﻿
