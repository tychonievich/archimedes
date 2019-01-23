import os.path, shutil, tempfile

_function = type(lambda x:x)
_bifunc = type(repr)
_module = type(shutil)

class TestPermissionError(PermissionError): pass

def argwrap(f):
    '''wraps a function in another function, to prevent func.whatever = ... from poluting the original'''
    def ans(*args, **kargs):
        return f(*args, **kargs)
    return ans

def fwrap(v):
    '''calls argwrap iff v is a user-defined function type'''
    return argwrap(v) if type(v) is _function else v

def block(v, n):
    if callable(v):
        def blocked(*args, **kargs):
            raise TestPermissionError(n+' has been disabled')
        return blocked
    else: return None

def wrap(mod, name, blacklist=(), whitelist=None):
    '''creates a new module object and copies from given module into it.
    If whitelist is a list of strings, the named attributes are copied.
    Otherwise all except blacklist's elements are copied'''
    ans = _module(name)
    if type(mod) is _module: mod = mod.__dict__
    if whitelist is not None:
        for elem in whitelist:
            if elem in mod:
                ans.__dict__[elem] = fwrap(mod[elem])
            else:
                print('cannot whitelist missing', name+'.'+elem)
        for n,v in mod.items():
            if n not in whitelist:
                tmp = block(v,n)
                if tmp is not None:
                    ans.__dict__[n] = tmp
    else:
        for n,v in mod.items():
            if n not in blacklist:
                ans.__dict__[n] = fwrap(v)
            else:
                tmp = block(v,n)
                if tmp is not None:
                    ans.__dict__[n] = tmp
    ans.__name__ = name
    return ans

class IOStub:
    '''A tool for wrapping print and input to write to lists
    and open to be stuck in a sandbox directory.
    To do: also add __import__ with os, os.path, etc, wrapping'''
    def __init__(self, *inputs):
        self.workingdir = None
        self.reset(*inputs)
        self.allow()
    def __del__(self):
        if self.workingdir:
            shutil.rmtree(self.workingdir, ignore_errors=True)
    def reset(self, *inputs, filereset=False):
        if len(inputs) == 1 and type(inputs[0]) in (tuple, list, set):
            inputs = inputs[0]
        self.inputs = [str(_) for _ in inputs]
        self.outputs = ['']
        if filereset:
            if self.workingdir:
                shutil.rmtree(self.workingdir, ignore_errors=True)
            self.workingdir = None
    def allow(self, *mods):
        if len(mods) == 1 and type(mods[0]) in (tuple, list, set):
            mods = mods[0]
        self._permitted = mods
    def print(self, *args, **kargs):
        if ('file' in kargs): print(*args, **kargs)
        else:
            end = kargs.get('end', '\n')
            sep = kargs.get('sep', ' ')
            self.outputs[-1] += sep.join(str(_) for _ in args) + end
    def input(self, prompt=''):
        self.outputs[-1] += str(prompt)
        if len(self.inputs) == 0:
            raise EOFError('invoked input(...) after all inputs provided')
        self.outputs.append('')
        return self.inputs.pop(0)
    def _safepath(self, path):
        if os.path.isabs(path) or '..' in path:
            raise TestPermissionError('prohibited file name '+path)
        if self.workingdir is None:
            self.workingdir = tempfile.mkdtemp(prefix='pytest_')
        return os.path.join(self.workingdir, path)
    def _safeify(self, func, pos=(), name=()):
        def wrap_func(*args, **kargs):
            args = list(args)
            for spot in pos:
                if len(args) > spot:
                    args[spot] = self._safepath(args[spot])
            for key in name:
                if key in kargs:
                    kargs[key] = self._safepath(kargs[key])
            return func(*args, **kargs)
        return wrap_func
    def open(self, file, *args, **kargs):
        return open(self._safepath(file), *args, **kargs)
    
    def imp(self, name, globals=None, locals=None, fromlist=(), level=0):
        if name not in self._permitted:
            raise TestPermissionError('not allowed to import '+name)
        mod = __import__(name, globals, locals, fromlist, level)
        if name == 'os' or name == 'os.path':
            mod = wrap(mod, 'os',
            whitelist=(
                'error', 'stat_result',
                'chmod', 'getrandom', 'link', 'makedirs', 'mkdir',
                'mkfifo', 'readlink', 'remove', 'removedirs', 'rename',
                'renames', 'replace', 'rmdir', 'stat', 'lstat', 'strerror', 'symlink',
                'sync', 'truncate', 'unlink', 'walk',
                
                'path', 'name', 'curdir', 'sep', 'extsep', 'altsep', 'linesep'
            ))
            mod.chmod = self._safeify(mod.chmod, (0,), ('path',))
            mod.mkdir = self._safeify(mod.mkdir, (0,), ('path',))
            mod.mkfifo = self._safeify(mod.mkfifo, (0,), ('path',))
            mod.readlink = self._safeify(mod.readlink, (0,), ('path',))
            mod.remove = self._safeify(mod.remove, (0,), ('path',))
            mod.rmdir = self._safeify(mod.rmdir, (0,), ('path',))
            mod.stat = self._safeify(mod.stat, (0,), ('path',))
            mod.lstat = self._safeify(mod.lstat, (0,), ('path',))
            mod.truncate = self._safeify(mod.truncate, (0,), ('path',))
            mod.unlink = self._safeify(mod.unlink, (0,), ('path',))
            mod.makedirs = self._safeify(mod.makedirs, (0,), ('name',))
            mod.removedirs = self._safeify(mod.removedirs, (0,), ('name',))
            mod.walk = self._safeify(mod.walk, (0,), ('top',))
            mod.renames = self._safeify(mod.renames, (0,1), ('old','new'))
            mod.link = self._safeify(mod.link, (0,1), ('src','dst'))
            mod.rename = self._safeify(mod.rename, (0,1), ('src','dst'))
            mod.replace = self._safeify(mod.replace, (0,1), ('src','dst'))
            mod.symlink = self._safeify(mod.symlink, (0,1), ('src','dst'))
            p = mod.path
            p = wrap(p, 'os.path',
            whitelist=(
                'basename', 'commonpath', 'commonprefix', 'dirname', 'isabs', 'isdir', 'join', 'normcase', 'normpath', 'sameopenfile', 'split', 'splitdrive', 'splitext',
                'exists', 'getatime', 'getctime', 'getmtime', 'getsize', 'isfile', 'islink', 'ismount', 'lexists', 'samefile',
            ))
            for n in ('exists', 'isfile', 'islink', 'ismount', 'lexists', ):
                p.__dict__[n] = self._safeify(p.__dict__[n], (0,), ('path',))
            for n in ('getatime', 'getctime', 'getmtime', 'getsize',):
                p.__dict__[n] = self._safeify(p.__dict__[n], (0,), ('filename',))
            p.samefile = self._safeify(p.samefile, (0,1), ('f1','f2'))
            mod.path = p
        elif name == 'urllib.request':
            raise BaseException('TO DO: implement url whitelists')
        else:
            mod = wrap(mod, name)
        return mod

def safe_builtins():
    ans = wrap(__builtins__, 'builtins', blacklist={
        '__import__', 'input', 'open', 'print', # edit behavior
        'copyright', 'credits', 'help', 'license', 'exit', 'quit', # carry payloads
        'eval', 'exec', 'compile', # allow getting around ast-base checks
        '_'  # _ is only defined in repl mode (e.g., cat your_code.py | python -i)
    })
    # note: this approach works with cpython, but not pypy
    # pypy kind of works if these are made globals (but avoid del)
    ans.help = argwrap(help)
    ans.exit = argwrap(exit)
    ans.quit = argwrap(quit)
    
    ans.__stdio_stub__ = IOStub()
    ans.print = ans.__stdio_stub__.print
    ans.input = ans.__stdio_stub__.input
    ans.open = ans.__stdio_stub__.open
    ans.__import__ = ans.__stdio_stub__.imp
    
    # override '__import__'
    return ans


def check_hidden(fname, src=None, ban_tokens=(), allow=()):
    import ast, _ast
    if src is None:
        with open(fname, 'r') as f:
            src = f.read()

    if ban_tokens:
        import tokenize, io
        for token in tokenize.tokenize(io.BytesIO(bytes(src, 'utf-8')).readline):
            if token[1] in ban_tokens:
                raise TestPermissionError('use of '+ repr(token[1]) + ' prohibited')

    tree = ast.parse(src, fname)
    for node in ast.walk(tree):
        if isinstance(node, _ast.Name):
            name = node.id
        elif isinstance(node, _ast.Attribute):
            name = node.attr
        elif isinstance(node, _ast.alias):
            name = node.name
        else:
            # print(node, node._fields)
            # for n in node._fields:
            #     print('  ', n, eval('node.'+n))
            continue
        if name.startswith('__') and name not in ('__name__', '__doc__')+allow or '.__' in name:
            raise TestPermissionError(name+' reserved for to test harness implementation')
    return tree

def safe_execable(fname, code=None, imports=[], ban_tokens=(), allow=()):
    '''Creates an io and import wrapper and a builtin stub, compiles,
    and returns the code object, the global dictionary, and the io wrapper.
    
    Throws a TestPermissionError if the code contains hidden variable access.
    Executing the code object will throw a TestPermissionError if it tries to import non-whitelisted modules.
    
    What is returned can be safely run:
    ----
    co, g, io = safe_executable('test.py', code=None, imports=['math'])

    io.reset('3', '2.5', 'yes') # simulate user input
    exec(co, g)
    for o,i in zip(io.outputs, ['3', '2.5', 'yes', '']):
        print(o+i) # display what an interactive session would have looked like
    ----
    
    May raise a SyntaxError
    '''
    tree = check_hidden(fname, code, ban_tokens, allow)
    bi = safe_builtins()
    io = bi.__stdio_stub__
    io.allow(*imports)
    # name: __main__ or os.path.splitext(os.path.basename(fname))[0]
    g = {'__builtins__':bi, '__name__':os.path.splitext(os.path.basename(fname))[0]}
    
    co = compile(tree, 'submission.py', 'exec')
    
    return co, tree, g, io

'''
For a module test, need to know
    ([inputs], {output predicates})
    an output predicate may be one of
        - def pred(outputs):
            return True or False
        - [str or re or None, ...] one for each output, evaluated as 
            for o,g in zip(outputs, key):
                if g is None: continue
                if type(g) is str and o != g: return False
                elif g.search(o) is None: return False
            return True
    any predicate may be paired with one or two (user, grader) strings
For a function test, need to know
    (funcname, args, kwargs={}, inputs=(), predicate)
    predicate may be
        - def pred(retval, outputs):
            return True or False
        - value, evaluated as or of
            retval == value
            value.search(retval) is not None
            type(value)(retval) == value
Either may also provide more elaborate test harness:
    def runTests(code_object, globals, io_wrapper):
        return [(True|False, usermsg, gradermsg), ...]
'''


if __name__ == "__main__":
    # testing code: modwrap should never be __main__
    code = '''
import os.path
if os.path.exists('user.log'):
    with open('user.log', 'r') as f:
        name = f.readline().strip()
        age = int(f.readline().strip())
    print('Welcome back,', name+'!')
else:
    name = input('Welcome; what is your name? ').strip()
    age = int(input('Hi, '+name+', how old are you? '))
    with open('user.log', 'w') as f:
        print(name, file=f)
        print(age, file=f)
print('Being', age+1,'will be even better than being', str(age)+'!')
'''
    co,tree,g,i = safe_execable('older.py', code, ['os', 'os.path'])
    
    i.reset('Guy', '35')
    exec(co,g)
    print(i.outputs)

    i.reset(filereset=False)
    exec(co,g)
    print(i.outputs)

    i.reset('Gal', 58, filereset=True)
    exec(co,g)
    print(i.outputs)

    i.reset('Gal', 58.5, filereset=True)
    try:
        exec(co,g)
    except BaseException as ex:
        print('Execution produced error:', repr(ex))
    print(i.outputs)

    print(g.keys())
    print({k:v for k,v in g.items() if not k.startswith('_')})
    
