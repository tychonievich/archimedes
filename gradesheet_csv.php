<?php

header('Content-Type: text/csv; charset=utf-8'); 
include "tools.php";
logInAs();
if (!hasFacultyRole($me)) { die("Only faculty may view this page"); }



foreach(fullRoster() as $id=>$details) {
    if (hasStaffRole($details)) continue;
    $section = array_key_exists('groups', $details) ? strpos($details['groups'], "-00") : 0;
    if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
    else { $section = ''; } 

    $overall = cumulative_status($id);
    $ep = 0; $fp = 0; $mp = 0;
    foreach($overall as $grp=>$scores) {
        $ep += $scores['weight'] * $scores['earned'];
        $fp += $scores['weight'] * $scores['future'];
        $mp += $scores['weight'] * $scores['missed'];
    }
    $overall = $ep + $mp > 0 ? 100*$ep / ($ep + $mp) : '';
    $bar =  svg_progress_bar($ep, $fp, $mp);


    echo $id;
    echo ",\"$details[name]\""; // Name
    echo ",\"$section\""; // Groups
    echo ",$overall"; // cumulative
    echo "\n";
}

/*
function csv_row_for($user) {
    if ($user == null) {
        $row = array(
            'student',
            'letter',
            'score',
            'pending',
        );
        foreach(base_grade_map() as $k=>$v) {
            $row[] = "All ${k}s [$v[weight]]";
            foreach($v['items'] as $n=>$d) {
                $row[] = "$n [$d[weight]]";
            }
        }
        return $row;
    }
    $sheet = grade_map($user);
    $row = array(
        $user,
        $sheet['letter'],
        $sheet['grade'],
        $sheet['future'] / ($sheet['future']+$sheet['missed']+$sheet['earned'])
    );
    foreach($sheet['details'] as $k=>$v) {
        $cell = $v['missed'] + $v['earned'];
        $row[] = $cell != 0? $v['earned'] / $cell : '';
        foreach($v['items'] as $n=>$d) {
            $row[] = $d['weight'] ? $d['score'] : (array_key_exists('notes',$d) ? $d['notes'] : '');
        }
    }
    return $row;
}



$fh = fopen("php://output", "w");
fputcsv($fh, csv_row_for(null));

foreach(fullRoster() as $id=>$details) {
    if (hasStaffRole($details)) continue;
    fputcsv($fh, csv_row_for($id));
}

fclose($fh);
*/
?>﻿
