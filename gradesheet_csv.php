<?php

header('Content-Type: text/csv; charset=utf-8'); 
include "tools.php";
logInAs();
if (!hasFacultyRole($me)) { die("Only faculty may view this page"); }

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

?>﻿
