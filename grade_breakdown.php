<?php

header('Content-Type: text/html; charset=utf-8'); 
include "tools.php";
logInAs();

$id = $user;
$details = $me;

$section = array_key_exists('groups', $details) ? strpos($details['groups'], "-00") : 0;
if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
else { $section = ''; } 

$bits = FALSE;
$overall = cumulative_status($id, $bits);
$ep = 0; $fp = 0; $mp = 0;
foreach($overall as $grp=>$scores) {
    $ep += $scores['weight'] * $scores['earned'];
    $fp += $scores['weight'] * $scores['future'];
    $mp += $scores['weight'] * $scores['missed'];
}
$final = $ep + $mp > 0 ? 100*$ep / ($ep + $mp) : '';

echo "$details[name] ($id)\n";

echo '<table border="1"><thead><tr><th>Task</th><th>Weight within kind</th><th>Weight overall</th><th>Score</th><th>Status</th></tr></thead><tbody>
';

echo "<tr><td>Cumulative</td><td></td><td>100%</td><td>$final%</td></tr>\n";

foreach($overall as $grp=>$scores) {
    $raw = ($scores['earned']/($scores['earned']+$scores['missed'])) * 100;
    $weight = $scores['weight']*100;
    echo "<tr><td>$grp</td><td></td><td>$weight%</td><td>$raw%</td></tr>\n";
}

foreach($overall as $grp=>$scores) {
    $of = 0;
    foreach($bits as $slug=>$data)
        if ($data['group'] == $grp 
        && !in_array('dropped', $data['.gcode']) 
        && !in_array('excused', $data['.gcode'])
        )
            $of += $data['weight'];
    foreach($bits as $slug=>$data)
        if ($data['group'] == $grp) {
            $dropped = in_array('dropped', $data['.gcode']) || in_array('excused', $data['.gcode']);
            if (in_array('future', $data['.gcode'])) $raw = '(pending)';
            else $raw = $data[".score"]*100;
            if (!$dropped) {
                $weight = $scores['weight']*$data['weight']*100/$of;
                $weight_in = $data['weight']/$of*100;
            } else {
                $weight = 0;
                $weight_in = 0;
            }
            $extra = implode(" <small>and</small> ", $data['.gcode']);
            echo "<tr><td>$slug</td><td>$weight_in%</td><td>$weight%</td><td>$raw%</td><td>$extra</td></tr>\n";
        }
}

/*
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
*/
echo '</tbody></table>';


?>﻿
