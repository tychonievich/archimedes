"""
This file is designed to be run in the background at all times.
It works as follows


1. (PHP) creates an upload directory and puts files in it
2. (PHP) creates a queue entry file with the date folder inside it
3. (Self) notices changes to queue and reads file
4. (Self) spawns a tester with a timeout
5. (Self) writes JSON .grade to upload file, .autofeedback to its parent directory
6. (PHP) reveals information per its internal rules

In addition (Self) listens for changes to the tasks;
a future extension will re-queue tests as needed

Requires

- Python 3
- https://pypi.org/project/pyinotify/
- https://pypi.org/project/PyYAML/

This file is based on earlier code by the same author: https://github.com/tychonievich/pypractice
"""

import os.path, sys


root = os.path.dirname(os.path.dirname(sys.argv[0]))
tasks = os.path.join(root, 'meta/tasks') # {slug}.yaml
queue = os.path.join(root, 'meta/queued') # {slug}-{user} with contents .{date}
uploads = os.path.join(root, 'uploads/{slug}/{user}/{date}')

testers = {}

# task/name.yaml
def newtask(path):
    """Given the path to a task.yaml, build a tester for it,
    replacing tester with same file basename if present.
    Does not (currently) re-run tests when a tester is updated."""
    global testers
    import testmaker, yaml
    print('newtask',(path,))
    with open(path) as f: p = yaml.safe_load(f)
    t = testmaker.loadyaml(p)
    name = path.rsplit('/', 1)[-1].rsplit('.', 1)[0]
    testers[name] = t
    print(' -=> loaded new task', name, 'from', path)

# given a queue entry path, return other used paths
def ppath(path):
    """Parse queue path into related paths and values"""
    import glob
    slug, user = os.path.basename(path).split('-')
    
    if os.path.exists(path):
        with open(path) as f: date = f.read().strip()
        subdir = uploads.format(slug=slug, user=user, date=date)
        pyfile = glob.glob(os.path.join(subdir, '*.py'))
        if len(pyfile) == 1: pyfile = pyfile[0] # HACK: assumes single-file submissions
        else: pyfile = None
        dst = os.path.join(subdir, '.autograde')
    else:
        subdir, pyfile, dst = None, None, None
    
    afb = uploads.format(slug=slug, user=user, date='.autofeedback')
    lfb = uploads.format(slug=slug, user=user, date='.latefeedback')
    
    return subdir, pyfile, dst, afb, lfb, slug

    

# given a path to a queue entry, which has a datestamp as its payload
# find all relevant tasks and run their testers
def newcode(path):
    """Handle testing a queue entry"""
    global testers
    import json
    print('newcode', (path,))

    subdir, pyfile, dst, afb, lfb, slug = ppath(path)
    
    if not pyfile or not subdir or not os.path.exists(subdir):
        print('no submission found in', subdir)
        os.unlink(path) # remove queue for absent file
        return

    if not slug in testers:
        print('no testers found for', subdir)
        with open(afb, 'w') as f:
            print('automated feedback not enabled for this assignment', file=f)
            # ¿use os.utimes to bypass feedback delay?

        # do not use os.unlink(path) because testers may be added later
        return
    
    # pypractice used create-in-tmp move-to-dest model to trigger vibe's
    # DirectoryWatcher's moved_to listneger because it had no close_write
    # listener. This is not needed in the current system because PHP can't
    # listen-and-push at all.
    
    result = testers[slug].report(pyfile)
    try:
        with open(afb, 'w') as f: json.dump({'stdout':result['feedback']}, f)
    except:
        with open(afb, 'w') as f: json.dump({'stdout':"internal error generating automated feedback"}, f)

    try:
        with open(lfb, 'w') as f:
            m = result['missed']
            if len(m) > 0:
                json.dump({'stdout':'Failed test cases:\n-------------------------\n' + '\n-------------------------\n'.join(m)}, f)
            else:
                json.dump({'stdout':'Passed all tests'},f)
    except:
        with open(afb, 'w') as f: json.dump({'stdout':"internal error testing your code"}, f)
        
    try:
        with open(dst, 'w') as f: json.dump(result, f, indent=2)
    except BaseException as ex:
        with open(dst, 'w') as f: json.dump({'correct':0,'error':testmaker.ex_msg(ex)}, f, indent=2)
    
    dst2 = os.path.join(os.path.dirname(os.path.dirname(dst)), os.path.basename(dst))
    if os.path.exists(dst) and not os.path.exists(dst2):
        os.link(dst, dst2)
    
    os.unlink(path) # remove queue now that grade finished
    return
    
    
class PIDWrap:
    """
    A time-out wrapper, with both CPU-time and wall-clock-time limits;
    if given a limit of x seconds, will get x CPU seconds or x*10 wall-clock seconds,
    whichever is smaller.
    """
    count = 0
    limit = max(1,len(os.sched_getaffinity(0))) * 10
    objs = set()
    
    def __init__(self, limit, func, args=(), kargs={}):
        self.limit = limit
        self.func = func
        self.args = args
        self.kargs = kargs
        self._status = 'pending'
        self.status() # starts if there are processes to spare
        PIDWrap.objs.add(self)
    def begin(self):
        import resource, time
        self.pid = os.fork()
        if self.pid == 0:
            used = resource.getrusage(resource.RUSAGE_SELF)
            resource.setrlimit(resource.RLIMIT_CPU, (used.ru_utime+self.limit, -1))
            self.func(*self.args, **self.kargs)
            quit()
        else:
            self.killat = time.time() + 10*self.limit
            self._status = 'running'
            PIDWrap.count += 1
    def status(self):
        """
        Returns one of
        
        "pending"           - not yet begun (will begin if resources to do so available)
        "running"           - still running
        "finished"          - completed, ready to be removed
        "crashed"           - completed with non-0 exit code
        "wallclock timeout" - killed by time check
        "cpu timeout"       - killed by rlimit check
        """
        import time
        if self._status == 'pending' and PIDWrap.count < PIDWrap.limit:
            self.begin()
        if self._status == 'running':
            ans = os.waitid(os.P_PID, self.pid, os.WEXITED | os.WNOHANG)
            if ans is None: 
                if time.time() >= self.killat:
                    os.kill(self.pid, 9)
                    os.waitpid(self.pid, 0)
                    self._status = 'wallclock timeout'
                else:
                    self._status = 'running'
            elif ans.si_status == 24: self._status = 'cpu timeout'
            elif ans.si_status != 0: self._status = 'crashed'
            else: self._status = 'finished'
            if self._status != 'running': PIDWrap.count -= 1
        return self._status


if __name__ == "__main__":

    import pyinotify as pin # to notice when new files are ready for testing
    import sys

    wm = pin.WatchManager()
    mask = pin.IN_CLOSE_WRITE | pin.IN_MOVED_TO | pin.IN_CREATE
    wm.add_watch(queue, mask, rec=False)
    wm.add_watch(tasks, mask, rec=False)

    class EventHandler(pin.ProcessEvent):
        '''Given a multiprocessing pool and a list,
        for each file event, requests the pool handle file
        and puts the request handler in the list'''
        def newfile(self, path):
            if queue in path:
                PIDWrap(1, newcode, (path,)) # 1 CPU second, 10 wall-clock seconds
            elif tasks in path:
                newtask(path) # PIDWrap(1, newtask, (path,))
            else:
                print('unexpected path name:', path) # pass
        def newdir(self, path, watch=True):
            """Because rec=False initially, this should never happen, but here just in case"""
            assert False
            if watch:
                wm.add_watch(path, mask, rec=True)
            # the following may result in double-processed files but prevents a different race condition
            for d,sds,fns in os.walk(path):
                for fn in fns:
                    self.newfile(os.path.join(d,fn))
        def process_default(self, event):
            if event.dir:
                # print('watching', event.pathname)
                self.newdir(event.pathname);
            elif not event.mask&(pin.IN_CREATE | pin.IN_IGNORED):
                # print('handling', event.pathname)
                self.newfile(event.pathname)
            # else ignore
            

    handler = EventHandler()
    notifier = pin.Notifier(wm, handler)
    
    # Manually check extant files
    for d, sds, fns in os.walk(tasks):
        for fn in fns:
            if fn.endswith('.yaml'):
                handler.newfile(os.path.join(d,fn))
    for d, sds, fns in os.walk(queue):
        for fn in fns:
            handler.newfile(os.path.join(d,fn))
    
    # wait for inotiofy events
    while True:
        evts = False
        if PIDWrap.count == 0:
            evts = notifier.check_events() # block
        else:
            evts = notifier.check_events(timeout=100) # 100 ms = 0.1 sec
        if evts:
            notifier.read_events() # runs handler for all events in queue
            notifier.process_events()
        for obj in tuple(PIDWrap.objs):
            if obj.status() not in ['running', 'pending']:
                print(obj.status(), obj.args)
                if obj.status() != 'finished' and obj.func == newcode:
                    # did not finish normally; make sure some feedback is given
                    try:
                        import json
                        path = obj.args[0]
                        subdir, pyfile, dst, afb, lfb, slug = ppath(path)
                        if not os.path.exists(afb):
                            try:
                                with open(afb, 'w') as f:
                                    json.dump({'stdout':'unable to test code ('+obj.status()+')'},f)
                            except BaseException as ex: 
                                print(testmaker.ex_msg(ex)[1])
                        if not os.path.exists(lfb):
                            try:
                                with open(lfb, 'w') as f:
                                    json.dump({'stdout':'unable to test code ('+obj.status()+')'},f)
                            except BaseException as ex: 
                                print(testmaker.ex_msg(ex)[1])
                        os.unlink(path) # remove queue entry
                    except BaseException as ex: 
                        print(testmaker.ex_msg(ex)[1])

                # print(obj.args, obj.status())
                PIDWrap.objs.remove(obj)
