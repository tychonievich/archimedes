"""
The purpose of this file is to replace an optional assignment,
such as an exam wrapper, with a required assignment, such as an exam,
if the required assignment's score is higher.
"""

import json, glob, sys, os.path, time

if len(sys.argv) != 3:
    print("USAGE:", sys.argv[0], '../uploads/required_task ../uploads/optional_task')
    print("WARNING: This tool overwrites old grades with no backup; use only if confident you should")
    exit(1)

def score_of(grade):
    """Computes numeric score given either a percentage of hybric score object
    WARNING: currently ignore multipliers and lateness"""
    if grade['kind'] == 'percentage':
        return grade['ratio']
    elif grade['kind'] == 'hybrid':
        h = sum(_['ratio'] * _.get('weight',1) for _ in grade['human'])
        t = sum(_.get('weight',1) for _ in grade['human'])
        r = h/t
        if grade.get('auto-weight', 0) > 0:
            r *= 1-grade['auto-weight']
            r += grade['auto-weight'] * grade['auto']
        return r

for have in glob.glob(os.path.join(sys.argv[1], '*/.grade')):
    with open(have) as fp: req = score_of(json.load(fp))
    
    optional = os.path.join(sys.argv[2], have[len(sys.argv[1]):])
    anything = os.path.join(optional[:-len('.grade')], '*')
    if os.path.exists(optional):
        with open(optional) as fp: opt = score_of(json.load(fp))
    elif len(glob.glob(anything)) > 0:
        print('skipping ungraded submission', anything)
        continue
    else:
        opt = 0
    if opt < req:
        if os.path.exists(optional):
            os.rename(optional, optional+'-'+str(time.time()))
        with open(optional, 'w') as fp:
            json.dump({
                "kind":"percentage",
                "ratio":req,
                "comments":"Using score from "+os.path.basename(sys.argv[1].strip('/'))+" as it was higher than your score for "+os.path.basename(sys.argv[2].strip('/'))
            }, fp)
    # print(have, req, opt)
