import json, os, sys, os.path, glob, shutil, runpy, io, datetime, tempfile
import pyinotify as pin

def justme():
	os.chdir(os.path.dirname(os.path.realpath(__file__)))
	slug = '/tmp/'+os.path.realpath(__file__).replace('/','.') + '.pid'
	try:
		with open(slug) as f:
			pid = f.read()
		with open('/proc/'+pid+'/cmdline') as f:
			cmd = f.read()
		assert 'autograder' in cmd
		print('already running; exiting', file=sys.stderr)
		os._exit(1)
	except:
		with open(slug, 'w') as f:
			f.write(str(os.getpid()))


assignments = {}
home = os.path.dirname(os.path.realpath(__file__))

def log(*args):
	print(datetime.datetime.now(), *args, file=sys.stderr)


class PIDWrap:
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



def parse_assignments(and_retest=False):
	global assignments
	with open('assignments.json', 'rb') as f:
		asgn = json.load(f)
	asgn, assignments = assignments, asgn
	if and_retest:
		for k,v in assignments.items():
			if k not in asgn or asgn[k].get('support') != v.get('support') or asgn[k].get('tester') != v.get('tester'):
				retest(k)

def shellcopy(src, dst, times=None):
	ans = False
	for f in glob.glob(src):
		if os.path.exists(f):
			shutil.copy(f, dst)
			ans = True
			if times is not None:
				times.append(os.path.getmtime(f))
	return ans


def dotest(student, aname):
	if aname not in assignments:
		log('Failed to test', aname, 'because it is not in the list of assignments')
		return False, 0
	
	a = assignments[aname]
	if 'tester' not in a:
		log('Failed to test', aname, 'because it has no tester')
		return False, 0
	
	os.chdir(home)
	try:
		with tempfile.TemporaryDirectory() as work:
			if 'files' in a:
				if not os.path.isdir('../uploads/'+aname+'/'+student):
					log(aname + '/' + student, 'does not exist')
					return False, 0
				ok = False
				mtimes = []
				if type(a['files']) is str:
					ok |= shellcopy('../uploads/'+aname+'/'+student+'/'+a['files'], work, times=mtimes)
				else:
					for f in a['files']:
						ok |= shellcopy('../uploads/'+aname+'/'+student+'/'+f, work, times=mtimes)
				if not ok:
					log(aname+':', student, 'has not uploaded any files')
					return False, 0
			for f in a.get('support', []) + [a['tester']]:
				if not shellcopy('support/'+f, work):
					log('Missing support file', f, 'for', aname)
					return False, 0
			os.chdir(work)
			sys.path.insert(0, work)
			
			oldout, olderr, oldin = sys.stdout, sys.stderr, sys.stdin
			sys.stdout, sys.stderr, sys.stdin = io.StringIO(), io.StringIO(), io.StringIO()
			try:
				scope = runpy.run_path(a['tester'])
				out, err = sys.stdout.getvalue(), sys.stderr.getvalue()
				if 'feedback_dict' in scope:
					ans = scope['feedback_dict']
					if 'immediate' in ans:
						now = ans.pop('immediate')
						if now: mtimes = [0]
					return ans, max(mtimes)
				return {'stdout':out, 'stderr':err}, max(mtimes)
			except BaseException as ex:
				out, err = sys.stdout.getvalue(), sys.stderr.getvalue()
				if out and out[-1] != '\n': out += '\n'
				out += 'your code raised an exception (or caused our testing code to do so)'
				if err and err[-1] != '\n': err += '\n'
				err += a['tester']+' raised '+repr(ex)
				return {'stdout':out, 'stderr':err}, max(mtimes) # seen at normal time
				# return {'stdout':out, 'stderr':err}, 0 # seen instantly
			finally:
				sys.stdout, sys.stderr, sys.stdin = oldout, olderr, oldin
	finally:
		os.chdir(home)

def recordfeedback(aname, student):
	path = '../uploads/'+aname+'/'+student+'/'
	try: 
		ans, mtime = dotest(student, aname)
		if ans:
			with open(path+'.autofeedback', 'w') as f:
				json.dump(ans, f, separators=(',',':'))
			os.utime(path+'.autofeedback', times=(mtime, mtime))
			log('graded', aname, student, path)
		else:
			with open(path+'.autofeedback', 'w') as f:
				json.dump({'stdout':'automated feedback failed to run properly','stderr':''}, f, separators=(',',':'))
			os.utime(path+'.autofeedback', times=(0, 0))
	except BaseException as ex: 
		log('grading', aname, 'for', student, 'raised', ex)
		with open(path+'.autofeedback', 'w') as f:
			json.dump({'stdout':'automated feedback failed to run properly','stderr':repr(ex)}, f, separators=(',',':'))
		os.utime(path+'.autofeedback', times=(0, 0))

def retest(aname):
	for path in glob.glob('../uploads/'+aname+'/*/'):
		student = path.split('/')[3]
		recordfeedback(aname, student)
		
def _dequeue(qentry):
	qentry = os.path.basename(qentry)
	try: os.unlink('queued/'+qentry)
	except: pass
	i = qentry.rfind('-')
	recordfeedback(qentry[:i], qentry[i+1:])

def dequeue(qentry):
	qentry = os.path.basename(qentry)
	i = qentry.rfind('-')
	a, s = qentry[:i], qentry[i+1:]
	PIDWrap(10, _dequeue, (qentry,)).destination = '../uploads/'+a+'/'+s+'/.autofeedback'



if __name__ == '__main__':
	justme()
	parse_assignments()
	# set up inotify

	wm = pin.WatchManager()
	mask = pin.IN_CLOSE_WRITE | pin.IN_MOVED_TO
	watched = wm.add_watch(home+'/queued', mask, rec=False)
	watched = wm.add_watch(home, mask, rec=False)

	class EventHandler(pin.ProcessEvent):
		'''Given a multiprocessing pool and a list,
		for each file event, requests the pool handle file
		and puts the request handler in the list'''
		def process_default(self, event):
			if event.pathname.endswith('assignments.json'):
				parse_assignments(True)
			elif '/queued/' in event.pathname and not event.mask & pin.IN_IGNORED:
				dequeue(event.pathname)
			else:
				log('ignoring event on', event.pathname)

	handler = EventHandler()
	notifier = pin.Notifier(wm, handler)
	
	# clear out anything there
	for f in glob.glob('queued/*'):
		dequeue(f)
	
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
				# print(obj.args, obj.status())
				PIDWrap.objs.remove(obj)
				if not os.path.exists(obj.destination):
					log(obj.destination,'not created:',obj._status)
					with open(obj.destination, 'w') as f:
						json.dump({'stdout':'code timed out','stderr':'killed testing process with status "'+obj._status+'"'}, f, separators=(',',':'))
					os.utime(obj.destination, times=(0, 0))
					
