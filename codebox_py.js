
var re_comment = /(#[^\n]*)/;
var re_string = /((?:\br?b|\bbr)?"""(?:[^"\\]|\\[\s\S]|""?(?=[^"]))*"""|(?:r?b|br)?'''(?:[^'\\]|\\[\s\S]|''?(?=[^']))*'''|(?:r?b|br)?"(?:[^"\\\n]|\\[\s\S])*"|(?:r?b|br)?'(?:[^'\\\n]|\\[\s\S])*')/;
var re_number = /\b((?:[0-9]*\.[0-9]+(?:[eE][-+][0-9]+)?|[0-9]+\.(?:[eE][-+][0-9]+)?|[0-9]+[eE][-+][0-9]+|0[Bb][01]+|0[Oo][0-7]+|0[Xx][0-9a-fA-F]+|0|[1-9][0-9]*)[jJ]?)\b/;
var re_keyword = /\b(elif|global|as|if|from|raise|for|except|finally|import|pass|return|else|break|with|class|assert|yield|try|while|continue|del|def|lambda|nonlocal|and|is|in|not|or|None|True|False)\b/;

var tokenizer = new RegExp([re_comment.source, re_string.source, re_number.source, re_keyword.source].join('|'), 'g');
var token_types = [null, 'comment', 'string', 'number', 'keyword'];

function htmlspecialchars(s) {
	return s.replace(/\&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;')
}
function highlight() {
	var highlight = document.querySelectorAll('pre code');
	for(var i=0; i<highlight.length; i+=1) {
		var code = highlight[i];
		if (code.parentElement.classList.contains('highlighted')) continue;
		var src = code.innerText.replace(/\t/g, '    ');
		var bits = src.split(tokenizer);
		var newcode = '';
		for(var j=0; j<bits.length; j+=token_types.length) {
			newcode += htmlspecialchars(bits[j]);
			if (j+1 == bits.length) break;
			for(var k=1; k<token_types.length; k+=1)
				if (bits[j+k]) newcode += '<span class="'+token_types[k]+'">'+htmlspecialchars(bits[j+k])+'</span>';
		}
		var lines = newcode.split('\n');
		var wid = String(lines.length).length;
		src = '';
		for(var j=0; j<lines.length; j+=1) {
			src += '<span class="lineno">'+String(j+1).padStart(wid)+'</span>' + lines[j] + '\n';
		}
		code.innerHTML = src + '<input type="button" value="line numbers" onclick="togglelineno()"/><input type="button" value="wrap" onclick="togglewrap()"/>';
		code.parentElement.classList.add('highlighted');
	}
}
function togglewrap() {
	var s = document.querySelectorAll('.highlighted');
	for(var i=0; i<s.length; i+=1) {
		if (s[i].style.whiteSpace == 'pre') {
			s[i].style.whiteSpace = 'pre-wrap';
			s[i].style.overflowX = '';
		}
		else {
			s[i].style.whiteSpace = 'pre';
			s[i].style.overflowX = 'auto';
		}
	}
}
function togglelineno() {
	var s = document.querySelectorAll('.lineno');
	for(var i=0; i<s.length; i+=1) {
		if (s[i].style.display == 'none') s[i].style.display = '';
		else s[i].style.display = 'none';
	}
}
