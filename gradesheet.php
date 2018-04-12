<?php header('Content-Type: text/html; charset=utf-8'); ?>﻿<!DOCTYPE html>
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
	</style>
	<script>//<!--
function load() {
	sortcolumn('tbody',1,true);
	sortcolumn('tbody',2,true);
}
//--></script>
</head><body onload="load()">
<?php
include "tools.php";
logInAs();
if (!hasFacultyRole($me)) { die("<p>Only faculty may view this page</p></body></html>"); }
?>
<table class="alternate"><thead><tr>
        <th onclick="sortcolumn('tbody',0,true)">ID ⇕</th>
        <th onclick="sortcolumn('tbody',1,true)">Name ⇕</th>
        <th onclick="sortcolumn('tbody',2,true)">Section ⇕</th>
        <th onclick="sortcolumn('tbody',3,true)">Grade ⇕</th>
</tr></thead>
<tbody id="tbody"><?php

foreach(fullRoster() as $id=>$details) {
	if (hasStaffRole($details)) continue;
	$section = strpos($details['groups'], "-00");
	if ($section > 0) { $section = substr($details['groups'], $section-4, 8); }
	else { $section = ''; } 
	echo "<tr><td><a href='index.php?asuser=$id'>$id</a></td><td>$details[name]</td><td>$section</td><td>";
	echo grade_in_course($id);
	echo "</td></tr>";
}

?></tbody>
</table>

</body></html>

