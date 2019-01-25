'''
This file is intended as a tester for tasks:
it takes a task.yaml and a task.py and tells if the task passes the task.
It is not intended to be used in production, as it is not efficient and does not implement timeouts.

If you get complaints about not being able to import yaml, try running

````bash
pip3 install PyYAML
````

There are 3 classes of output:

- public feedback, which will look something like
    
        PASSED: first example in writeup
        FAILED: second example in writeup
        Passed 2 out of 5 other tests

- late feedback, which will look something like
    
        Failed when given
            input 1: 23
            input 2: 11
            
        Failed when given
            input 1: 19
            input 2: not a number

    if an input/output based program, or
    
        Failed case st_jeor(74.6, 163.9, 19, 'female')
        Failed case st_jeor(20, 11, 23, 'male')
    
    if a function-based assignment
        
    
- grader logs, which will have full details and likely not be shown to anyone
    
    {args:[1,2,3]
    ,kwargs:{'z':11},
    ,inputs:...}

- correctness score: a number between 0 and 1

This file is based on earlier code by the same author: https://github.com/tychonievich/pypractice
'''



from sys import argv
from os.path import exists
import testmaker, yaml, json

if len(argv) != 3 or not argv[1].endswith('.yaml') or not argv[2].endswith('.py'):
    print('USAGE: python3', argv[0], 'path/to/task.yaml', 'path/to/implementation.py')
    exit(1)
if not exists(argv[1]):
    print('No such file or directory:', argv[1])
    exit(2)
if not exists(argv[2]):
    print('No such file or directory:', argv[2])
    exit(3)


with open(argv[1]) as f: t = testmaker.loadyaml(yaml.safe_load(f))

print(json.dumps(t.report(argv[2]), indent=2))
