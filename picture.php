<?php
include "tools.php";
logInAs();

if (!array_key_exists('user', $_REQUEST)) die('Failed to provide a user ID');

$path = trim($_REQUEST['user'], "./\\\t\n\r\0\x0B");
if (!$path) die('Failed to provide a user ID');
if ($path != $user && !$isstaff) die('You are not allowed to view that user');

if (basename($path)[0] == '.' || $path != basename($path)) die('Invalid user name');

$altpath = "../ohq/photos/$path.jpg";
$path = "users/$path.jpg";

if (!file_exists($path)) {
    if (file_exists($altpath)) $path = $altpath;
    else die('No photo found');
}

$finfo = new finfo(FILEINFO_MIME);
$mime = $finfo->file($path);

header('Content-Type: '.$mime);
// header('Content-Disposition: attachment;filename="'.basename($path).'"');

readfile($path);
die();
?>
