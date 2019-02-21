function sensibleDateFormat(d) {
    // toLocaleFormat has been deprecated and removed from some browsers, so do this manually
    var s = d.toString().split(' '); // for day-of-week and timezone-specific time
    var dow = s[0];
    var tz = s[s.length-1];
    var date = String(d.getFullYear()).padStart(4,'0')+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
    // var time = d.toTimeString().substr(0,5);
    if (d.getHours() > 12) { var time = (d.getHours()-12) + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + ' pm'; }
    else { var time = d.getHours() + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + (d.getHours() == 12 ? ' pm' : ' am'); }
    if (time == '0 am') return dow + ' ' + date;
    return dow + ' ' + date + ' ' + time;// + ' ' + tz;
}

function smallDateFormat(d) {
    // toLocaleFormat has been deprecated and removed from some browsers, so do this manually
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var s = d.toString().split(' '); // for day-of-week and timezone-specific time
    var dow = s[0];
    var tz = s[s.length-1];
    var date = d.getDate() + ' ' + months[d.getMonth()];
    // var time = d.toTimeString().substr(0,5);
    if (d.getHours() > 12) { var time = (d.getHours()-12) + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + ' pm'; }
    else { var time = d.getHours() + (d.getMinutes() != 0 ? ':' + String(d.getMinutes()).padStart(2,'0') : '') + (d.getHours() == 12 ? ' pm' : ' am'); }
    if (time == '0 am') return dow + ' ' + date;
    return dow + ' ' + date + ' ' + time;// + ' ' + tz;
}


function reformat(el) {
    var dt = new Date(Number(el.getAttribute('ts')+'000'));
    var now = new Date();
    var ms = dt - now; // + if future, - if past
    var as = Math.abs(Math.round(ms/1000));
    var dayDiff = new Date(now.toDateString()) - new Date(dt.toDateString());
    dayDiff /= 24*60*60*1000;
    var relative = '';
    if (as < 8*60*60) {
        if (as < 60*60) {
            var m = Math.floor(as/60);
            relative = m + ' minute' + (m == 1 ? '' : 's') + (ms < 0 ? ' ago' : ' from now');
        } else {
            var h = Math.floor(as/3600);
            var m = Math.floor((as/60)%60);
            // var s = Math.floor(as%60);
            relative = h + ':' + (m < 10 ? '0'+m : m) + /*':' + (s < 10 ? '0'+s : s) +*/ (ms < 0 ? ' ago' : ' from now');
        }
    } else if (dayDiff == 0) { // today in local timezone
        if (ms < 0) relative = 'earlier ';
        else relative = 'later ';
        if (dt.getHours() < 12) relative += 'this morning';
        else if (dt.getHours() < 17) relative += 'this afternoon';
        else if (dt.getHours() < 20) relative += 'this evening';
        else relative += 'tonight';
    } else if (Math.abs(dayDiff) == 1) {
        if (ms < 0) relative = 'yesterday';
        else relative = 'tomorrow';
        if (dt.getHours() < 12) relative += ' morning';
        else if (dt.getHours() < 17) relative += ' afternoon';
        else if (dt.getHours() < 20) relative += ' evening';
        else relative += ' night';
        relative = relative.replace('yesterday night', 'last night');
    } else if (Math.abs(dayDiff) < 7) {
        if (ms < 0) relative = 'last ';
        relative += ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][dt.getDay()];
        if (dt.getHours() < 12) relative += ' morning';
        else if (dt.getHours() < 17) relative += ' afternoon';
        else if (dt.getHours() < 20) relative += ' evening';
        else relative += ' night';
    } else {
        var w = Math.round(as/(7*24*60*60));
        relative = w + ' week' + (w == 1 ? '' : 's') + (ms < 0 ? ' ago' : ' from now');
    }
    el.innerHTML = smallDateFormat(dt) + ' (' + relative + ')';
}

function dotimes() {
    function uclock() {
        var fixers = document.getElementsByClassName('datetime');
        for(var i=0; i<fixers.length; i+=1) {
            reformat(fixers[i]);
        }
    }
    uclock();
    window.setInterval(uclock, 60*1000);
}

function docollapse() {
    // something here does not work on safari, but I can't seem to figure out what
    var hiders = document.getElementsByClassName('hide-outer');
    for(var i=0; i<hiders.length; i+=1) {
        hiders[i].firstElementChild.onclick = function(e) {
            var parent = this.parentElement;
            if (parent.classList.contains('hidden')) {
                parent.classList.remove('hidden');
                parent.classList.add('shown');
            } else {
                parent.classList.remove('shown');
                parent.classList.add('hidden');
            }
        }
    }
    var breakdowns = document.querySelectorAll('table + dl dt');
    for(var i=0; i<breakdowns.length; i+=1) {
        breakdowns[i].onclick = function() {
            if (this.classList.contains('collapsed')) {
                this.nextElementSibling.classList.remove('collapsed');
                this.classList.remove('collapsed');
            } else {
                this.nextElementSibling.classList.add('collapsed');
                this.classList.add('collapsed');
            }
        }
        breakdowns[i].nextElementSibling.classList.add('collapsed');
        breakdowns[i].classList.add('collapsed');
    }
}
