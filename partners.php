<?php
include "tools.php";
logInAs();
if (!$isstaff) die("page restricted to staff");
?><!DOCTYPE html>
<html lang="en"><head>
    <title>Project team creation</title>
    <script type="text/javascript" src="columnsort.js"></script>
    <script>//<!--

function query(nameid, section, grader, status) {
    var ans = {};
    for(var id in everyone) {
        me = everyone[id];
        if (nameid && id.indexOf(nameid.toLowerCase()) < 0 && (!me.name || me.name.toLowerCase().indexOf(nameid.toLowerCase()) < 0)) continue;
        if (section && (!me.section || me.section.indexOf(section) < 0)) continue;
        if (grader && (!me.grader || (me.grader.indexOf(grader.toLowerCase()) < 0 && me.grader_name.toLowerCase().indexOf(grader.toLowerCase()) < 0))) continue;
        if (status == 'solo' && me.partners.length > 0) continue;
        if (status == 'paired' && me.partners.length < 1) continue;
        if (status == 'trio' && me.partners.length < 2) continue;
        ans[id] = me;
    }
    return ans;
}

function setOptions(obj) {
    var dl = document.querySelector('datalist');
    while (dl.hasChildNodes()) dl.removeChild(dl.lastChild);
    for(var id in obj) {
        var opt = document.createElement('option');
        opt.value = id;
        opt.appendChild(document.createTextNode(obj[id].name + ' ('+id+') '+JSON.stringify(obj[id].partners)+' '+obj[id].section + ' grader '+obj[id].grader));
        dl.appendChild(opt);
    }

    var tbody = document.querySelectorAll('tbody'); tbody = tbody[tbody.length-1];
    while (tbody.hasChildNodes()) tbody.removeChild(tbody.lastChild);
    for(var id in obj) {
        tbody.appendChild(pairedRow(id));
    }
    sortcolumn('tbody',0,true)
}
function pairedRow(id) {
    var tr = document.createElement('tr');
    tr.id = id;
    tr.insertCell().appendChild(document.createTextNode(id));
    tr.insertCell().appendChild(document.createTextNode(everyone[id].name));
    tr.insertCell().appendChild(document.createTextNode(everyone[id].section));
    tr.insertCell().appendChild(document.createTextNode(everyone[id].grader_name));
    var parts = [];
    for(var i=0; i<everyone[id].partners.length; i+=1) {
        var p = everyone[id].partners[i];
        var txt = everyone[p].name+' ('+p+')';
        if (everyone[id].section != everyone[p].section) txt += ' - '+everyone[p].section;
        if (everyone[id].grader != everyone[p].grader) txt += ' - '+everyone[p].grader_name;
        parts.push(txt);
    }
    tr.insertCell().innerHTML = parts.join('<br/>');
    tr.insertCell().innerHTML = "<input type='text' list='people' name='partner["+id+"]'/><input type='submit' value='submit all changes'/>";

    return tr;
}

function reSet() {
    setOptions(query(
        document.querySelector('#nameid').value,
        document.querySelector('#section').value,
        document.querySelector('#grader').value,
        document.querySelector('#status').value,
    ));
}

    //--></script><style>
        .group { float:left; margin:1em; padding:1em; background-color:#eee;border:thin solid #ddd; }
        .student { margin:0.5ex; padding:0.5ex; background-color:#ffd;border:thin solid #ffb; }
        img { max-width: 10em; display:block; }
        img.hide { display: none; }
        .alt { background: linear-gradient(to right, rgba(127,63,0,0.125), rgba(0,0,0,0)); }
        table { border-collapse: collapse; }
        td,th { padding: 0.25ex 0.5ex; }
    </style>
</head><body>
<?php
if (array_key_exists('partner', $_REQUEST)) {
    echo "<div style='background: rgba(0,0,255,0.25);>";
    $requested = array(); // newly defined groupings
    foreach($_REQUEST['partner'] as $from=>$to) if ($to) {
        $to = trim(strtolower($to));
        if (!array_key_exists($from, fullRoster())) { echo "skipping unknown user: $from<br/>"; continue; }
        if (!array_key_exists($from, $requested)) $requested[$from] = array();
        foreach(explode(',',$to) as $one) {
            if (array_key_exists($one, fullRoster())) {
                if (!array_key_exists($one, $requested)) $requested[$one] = array();
                if (!in_array($one, $requested[$from])) $requested[$from][] = $one;
                if (!in_array($from, $requested[$one])) $requested[$one][] = $from;
            } else {
                echo "not pairing $from with unknown user $one<br/>"; continue; 
            }
        }
    }
    // ensure symmetry of multiple partnerships
    foreach($requested as $id=>$part) {
        if (count($part) > 1) {
            foreach($part as $p) {
                foreach($part as $p2) if ($p != $p2) {
                    if (!in_array($p, $requested[$p2])) $requested[$p2][] = $p;
                    if (!in_array($p2, $requested[$p])) $requested[$p][] = $p2;
                }
            }
        }
    }
    // dissolve all accessed partnerships
    // WARNING: The below loop has a race condition if multiple requests are concurrent (one might adding one removing might make CP1 not match Proj)
    foreach($requested as $id=>$partners) {
        if (file_exists("uploads/Project/$id/.partners")) {
            // remove all partners in group
            foreach(explode("\n", trim(file_get_contents("uploads/Project/$id/.partners"))) as $id2) {
                if (file_exists("uploads/Checkpoint 1/$id2/.partners")) unlink("uploads/Checkpoint 1/$id2/.partners");
                if (file_exists("uploads/Checkpoint 2/$id2/.partners")) unlink("uploads/Checkpoint 2/$id2/.partners");
                if (file_exists("uploads/Project/$id2/.partners")) unlink("uploads/Project/$id2/.partners");
            }
            if (file_exists("uploads/Checkpoint 1/$id/.partners")) unlink("uploads/Checkpoint 1/$id/.partners");
            if (file_exists("uploads/Checkpoint 2/$id/.partners")) unlink("uploads/Checkpoint 2/$id/.partners");
            if (file_exists("uploads/Project/$id/.partners")) unlink("uploads/Project/$id/.partners");
        }
    }
    // record all new partnerships
    foreach($requested as $id=>$partners) {
        if (count($partners) == 0) {
            if (file_exists("uploads/Checkpoint 1/$id/.partners")) unlink("uploads/Checkpoint 1/$id/.partners");
            if (file_exists("uploads/Checkpoint 2/$id/.partners")) unlink("uploads/Checkpoint 2/$id/.partners");
            if (file_exists("uploads/Project/$id/.partners")) unlink("uploads/Project/$id/.partners");
        } else {
            file_put("uploads/Project/$id/.partners", implode("\n", $partners));
            file_put("uploads/Checkpoint 2/$id/.partners", implode("\n", $partners));
            file_put("uploads/Checkpoint 1/$id/.partners", implode("\n", $partners));
        }
    }
    echo "</div>";
}
function labOf($grpstr) { // NOTE: this may need changing every semester/course...
    foreach(array('2501-301','2501-302') as $opt) {
        if (strpos($grpstr, $opt) !== FALSE) return $opt;
    }
}

$everyone = array();
foreach(fullRoster() as $id=>$details) {
    if (True || !hasStaffRole($details)) {
        $parts = array();
        if (file_exists("uploads/Project/$id/.partners"))
            $parts = explode("\n", trim(file_get_contents("uploads/Project/$id/.partners")));
        else
            $parts = array();
        $everyone[$id] = array(
            "name" => $details["name"],
            "grader" => array_key_exists("grader", $details) ? $details["grader"] : 'no TA',
            "grader_name" => array_key_exists("grader_name", $details) ? $details["grader_name"] : 'no TA assigned',
            "section" => hasStaffRole($details) ? 'staff' : labOf($details['groups']),
            "partners" => $parts
        );
    }
}
echo "<script>window.everyone = " . json_encode($everyone) . ";</script>";
?>
    <p>Any changes you make here are logged, and appear live in all other Archimedes tools.
    Use only as directed.
    You have been warned.</p>
    <p>All changes are <em>per force</em> symmetric; if you submit <q>aa1a</q> as <q>bb1b</q>'s partner, 
    both of their previous partnerships are dissolved and both <q>aa1a</q> and <q>bb1b</q> are made to partner with one another.</p>
    <p>While the change box suggests single computing IDs, you can ungroup by putting in non-compids like <q>none</q> and make groups of 3 by adding 2 IDs separated by commas, like <q>lat7h,up3f</q></p>

<datalist id='people'></datalist>

<label>Filter by name: <input type="text" id="nameid" onchange="reSet()"></label></br>
<label>Filter by section: <input type="text" id="section" onchange="reSet()" value="1"></label></br>
<label>Filter by grader: <input type="text" id="grader" onchange="reSet()"></label></br>
<label>Search by status: <select id="status" onchange="reSet()"><option value="all">all</option><option value="solo">solo</option><option value="paired">grouped</option><option value="trio">groups of 3+</option></select></label></br>

<form method="POST" action="<?=$_SERVER['SCRIPT_NAME']?>">
<table><thead>
    <tr>
        <th onclick="sortcolumn('tbody',0,true)">ID ⇕</th>
        <th onclick="sortcolumn('tbody',1,true)">Name ⇕</th>
        <th onclick="sortcolumn('tbody',2,true)">Section ⇕</th>
        <th onclick="sortcolumn('tbody',3,true)">Grader ⇕</th>
        <th onclick="sortcolumn('tbody',4,true)">Partner ⇕</th>
    </tr>
</thead><tbody id='tbody'>
</tbody></table>
</form>
<script>
    reSet();
</script>
</body></html>
