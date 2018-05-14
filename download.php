<?php
include "tools.php";
logInAs();

if (!array_key_exists('file', $_REQUEST)) die('Failed to provide a file name');

$path = trim($_REQUEST['file'], "./\\\t\n\r\0\x0B");
if (!$path) die('Failed to provide a file name');
if (($isstaff && $isself) && strpos($path, 'support') === 0) $path = "meta/$path";
else $path = "uploads/$path";

if (realpath($path) != getcwd()."/$path") die('Invalid file name');
if (basename($path)[0] == '.') die('Invalid file name');

if (is_dir($path)) {
	$parts = explode('/', trim($path, '/'));
	if ($parts[0] == 'uploads') {
		$submitted = array();
		foreach(glob("$path/*") as $fname) {
			$submitted[basename($fname)] = $fname;
		}
		$a = assignments()[$parts[1]];
		if (array_key_exists('extends', $a)) {
			foreach($a['extends'] as $slug2) {
				foreach(glob("uploads/$slug2/$parts[2]/*") as $path) {
					$n = basename($path);
					if (!array_key_exists(basename($path), $submitted)) {
						$submitted[$n] = $path;
					}
				}
			}
		}

		$zip = new ZipArchive;
		$tmp = tempnam(sys_get_temp_dir(), '.sub');
		if (($res = $zip->open($tmp, ZipArchive::CREATE)) === True) {
			if ($isstaff && $isself) {
				$hide = array();
				if (array_key_exists('support', $a)) {
					foreach($a['support'] as $pattern) {
						foreach(glob("meta/support/$pattern") as $fname) {
							$zip->addFile($fname, basename($fname));
							$hide[] = basename($fname);
						}
					}

				}
				if (array_key_exists('tester', $a)) {
					foreach(glob("meta/support/$a[tester]") as $fname) {
						$zip->addFile($fname, basename($fname));
						$hide[] = basename($fname);
					}
				}
				if (count($hide) > 0) {
					$zip->addFromString('STAFF_ONLY.md', "The following file(s) must not be released to students:\n\n-   `".implode("`\n-   `", $hide)."`");
				}
			}
			foreach($submitted as $name=>$fname) {
				if (!is_dir($fname)) {
					$zip->addFile($fname, $name);
				}
			}
			$zip->close();
		} else {
			die("Failed to zip $path");
		}
		$name = implode('-', $parts).'.zip';
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment;filename="'.$name.'"');
		readfile($tmp);
		unlink($tmp);
		die();

	}

/*
	if (strpos($path, "uploads/") === 0) {
		$parts = explode('/', substr($path, 8));
		$zip = new ZipArchive;
		$tmp = tempnam(sys_get_temp_dir(), '.sub');
		if (($res = $zip->open($tmp, ZipArchive::CREATE)) === True) {
			if ($isstaff && $isself) {
				$a = assignments()[$parts[0]];
				$hide = array();
				if (array_key_exists('support', $a)) {
					foreach($a['support'] as $pattern) {
						foreach(glob("meta/support/$pattern") as $fname) {
							error_log(var_export($fname, True));
							$zip->addFile($fname, basename($fname));
							$hide[] = basename($fname);
						}
					}
				}
				if (array_key_exists('tester', $a)) {
					foreach(glob("meta/support/$a[tester]") as $fname) {
						$zip->addFile($fname, basename($fname));
						$hide[] = basename($fname);
					}
				}
				if (count($hide) > 0) {
					$zip->addFromString('STAFF_ONLY.md', "The following file(s) must not be released to students:\n\n-   `".implode("`\n-   `", $hide)."`");
				}
			}
			foreach(glob("$path/*") as $fname) {
				if (!is_dir($fname)) {
					$zip->addFile($fname, basename($fname));
				}
			}
			$zip->close();
		} else {
			die("Failed to zip $path");
		}
		$name = implode('-', $parts).'.zip';
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment;filename="'.$name.'"');
		readfile($tmp);
		unlink($tmp);
		die();
	}
*/
	die('Cannot download directories');
}

if (!($isstaff && $isself) && strpos($path, "/$user/") === FALSE) die('Invalid file name');

$finfo = new finfo(FILEINFO_MIME);
$mime = $finfo->file($path);

header('Content-Type: '.$mime);
header('Content-Disposition: attachment;filename="'.basename($path).'"');

readfile($path);
die();
?>
