#!/bin/env python3

import sys,csv,json,time,getpass

try:
    slug = sys.argv[1]
    outof = float(sys.argv[2])
    csvfile = open("atbottom.csv")
except:
    print("USAGE: python3",sys.argv[0],'"slug" max_points')
    print("  then put the \"with CSV at bottom\" CSV on the command line")
    quit()    

now = int(time.time())

r = csv.reader(open('atbottom.csv')) #r = csv.reader(sys.stdin)
for row in r:
    if len(row) == 4 and row[0] != 'userid':
        try:
            sid = row[0]
            score = float(row[3])/outof
        except: continue
        txt='{ "kind": "percentage", "ratio": '+str(score)+', "comments":"see scans and rubric for details" }'
        print('mkdir -p',repr('uploads/'+slug+'/'+sid))
        print("echo", repr(txt), '>', repr('uploads/'+slug+'/'+sid+'/.grade'))
print('chmod 777', repr('uploads/'+slug), repr('uploads/'+slug)+'/*/')
print('chmod 666', repr('uploads/'+slug)+'/*/.grade')

