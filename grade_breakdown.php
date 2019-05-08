<!DOCTYPE HTML>
<html><head><title>Points Breakdown</title>
<style>
    .cumulative { background-color: rgba(255,255,0,0.25); }
    .group { background-color: rgba(0,0,0,0.125); }
</style></head><body><?php

header('Content-Type: text/html; charset=utf-8'); 
include "tools.php";
logInAs();

$id = $user;
$details = $me;

$section = array_key_exists('groups', $details) ? strpos($details['groups'], "-00") : 0;
if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
else { $section = ''; } 

$bits = FALSE;
$final = 0;
$overall = cumulative_status($id, $bits, $final);

echo "$details[name] ($id)\n";

echo '<table border="1"><thead><tr><th>Task</th><th>Weight within kind</th><th>Weight overall</th><th>Score</th><th>Status</th></tr></thead><tbody>
';

echo "<tr class='cumulative'><td colspan='3'>Cumulative</td><td>$final</td></tr>\n";

foreach($overall as $grp=>$scores) {
    $weight = $scores['weight']*100;
    if (($scores['earned']+$scores['missed']) == 0) {
        echo "<tr class='group'><td colspan='2'>$grp total</td><td>$weight%</td><td>insufficient data</td></tr>\n";
    } else {
        $raw = ($scores['earned']/($scores['earned']+$scores['missed'])) * 100;
        echo "<tr class='group'><td colspan='2'>$grp total</td><td>$weight%</td><td>$raw%</td></tr>\n";
    }
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

echo '</tbody></table>';


?>﻿</body></html>
