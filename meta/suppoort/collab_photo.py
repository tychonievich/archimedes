import re, zipfile, urllib.parse, os.path, sys

if len(sys.argv) < 2:
    print('Usage: python3', sys.argv[0], 'path/to/downloaded_roster_page.html')
    print('''
    Go to Collab (in Firefox or Chrome)
    Click on the roster
    scroll to the bottom repeatedly until all of the photos have loaded
    Use your browser's Save option, picking "web page, complete" as the type
    Run this script, passing in the downloaded .html file as its argument
    
    A .zip archive named roster.zip will appear in the same directory as the roster html,
    containing a photo of each student (named, e.g., mst3k.jpg)''')
    quit()

if os.path.dirname(sys.argv[1]):
    os.chdir(os.path.dirname(sys.argv[1]))

with open(sys.argv[1]) as f:
    txt = f.read()

entry = re.compile('<div class="roster-member">[\s\S]*?<img class="rosterPicture" src="([^"]*)"[\s\S]*?User ID(?:[^>]*<[^>]*>){3}\s*([a-z0-9]{4,7})')

data = {}
if os.path.exists('roster.zip'):
    with zipfile.ZipFile('roster.zip', 'r') as myzip:
        for name in myzip.namelist():
            data[name] = myzip.read(name)

with zipfile.ZipFile('roster.zip', 'w') as myzip:
    for link,cid in entry.findall(txt):
        path = urllib.parse.unquote(link)
        if os.path.getsize(path) > 1600: # not the placeholder .gif
            myzip.write(path, cid+'.jpg')
            if cid+'.jpg' in data: data.pop(cid+'.jpg')
    for k,v in data.items():
        myzip.writestr(k, v)
