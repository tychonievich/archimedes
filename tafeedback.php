<!DOCTYPE html>
<html lang="en"><head>
    <title>TA Feedback</title>
    <script type="text/javascript" src="columnsort.js"></script>
</head><body><?php

include "tools.php";
logInAs();
if (!$isstaff) die("page restricted to staff");

$fb = json_decode(file_get_contents('meta/ta_feedback.json'), True);

if (array_key_exists($user, $fb)) {
    ?>
    <p>Welcome, <?=$me['name']?> (<?=$user?>)</p>
    <p>You have been rated by <?=$fb[$user]['count']?> students in office hours.</p>
    <table><thead><tr><th>Percent</th><th>Checkbox item</th></tr></thead><tbody>
        <?php
        foreach($fb[$user] as $k=>$v) {
            if ($k == 'comments' || $k == 'count' || $k == '') continue;
            ?><tr><td><?=$v?>%</td><td><?=$k?></td></tr><?php
        }
        ?>
    </tbody></table>
    <p>Free-response comments:</p>
    <?php
    sort($fb[$user]['comments']);
    foreach($fb[$user]['comments'] as $v) {
        echo "<blockquote><p>";
        echo htmlspecialchars($v);
        echo "</p></blockquote>";
    }
    
} else if ($isfaculty) {
    ?>
    <ul>
    <li><strong>H</strong>elpful</li>
    <li><strong>P</strong>olite</li>
    <li><strong>Li</strong>stened to my questions</li>
    <li>focused on my <strong>Le</strong>arning more than on solving my problem</li>
    <li>took enough <strong>T</strong>ime</li>
    <li><strong>R</strong>u<strong>s</strong>hed</li>
    <li>focused on <strong>S</strong>olving my problem more than on my learning</li>
    <li>was <strong>c</strong>ondescending</li>
    <li><strong>R</strong>u<strong>d</strong>e</li>
    <li><strong>U</strong>nhelpful</li>
    </ul>
    <table><thead>
    <tr>
        <th onclick="sortcolumn('tbody',0,true)">ID ⇕</th>
        <th onclick="sortcolumn('tbody',1,true)">Name ⇕</th>
        <th onclick="sortcolumn('tbody',2,true)">Count</th>
        <th onclick="sortcolumn('tbody',3,true)">H</th>
        <th onclick="sortcolumn('tbody',4,true)">P</th>
        <th onclick="sortcolumn('tbody',5,true)">Li</th>
        <th onclick="sortcolumn('tbody',6,true)">Le</th>
        <th onclick="sortcolumn('tbody',7,true)">T</th>
        <th onclick="sortcolumn('tbody',8,true)">Rs</th>
        <th onclick="sortcolumn('tbody',9,true)">S</th>
        <th onclick="sortcolumn('tbody',10,true)">C</th>
        <th onclick="sortcolumn('tbody',11,true)">Rd</th>
        <th onclick="sortcolumn('tbody',12,true)">U</th>
        <th onclick="sortcolumn('tbody',13,true)">Comments ⇕</th>
    </tr>
</thead><tbody id='tbody'><?php

    $terms = array(
        'helpful',
        'polite',
        'listened to my questions',
        'focused on my learning more than on solving my problem',
        'took enough time',
        'rushed',
        'focused on solving my problem more than on my learning',
        'was condescending',
        'rude',
        'unhelpful',
    );

    foreach($fb as $ta=>$details) {
        $t = rosterEntry($ta);
        echo "<tr><td>$ta</td><td>$t[name]</td><td>$details[count]</td>";
        foreach($terms as $i=>$term) {
            if (array_key_exists($term, $details)) {
                $amt = intval($details[$term]*2.55);
                $neg = 255-$amt;
                $per = $details[$term]/100;
                if ($i < 5) {
                    echo "<td style='color:white; background:rgba(0,0,0,$per); text-align:center;'>$details[$term]</td>";
                } else {
                    echo "<td style='color:white; background:rgba(0,0,0,$per); text-align:center;'>$details[$term]</td>";
                }
            } else {
                echo "<td style='color:white; background:rgba(0,0,0,0); text-align:center;'>0</td>";
            }
        }
        echo "<td><ul>";
        sort($details['comments']);
        foreach($details['comments'] as $v) {
            echo "<li>";
            echo htmlspecialchars($v);
            echo "</li>";
        }
        echo "</ul></td></tr>";
    }

?></tbody></table><?php

} else {
    die("Sorry, we don't have enough feedback to show it to you yet.");
}


?></body></html>
