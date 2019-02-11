import modwrap, yaml, copy

'''
The main goal of this over testmaker.py is

parse task/whatever.yaml into a Test object
    
    solution:       string that can be exec'd
    func:           function name or None for module test
    imports:        list of permitted module names
    recursive:      True
    loops:          False
    ban:            list of strings to ban at tokenization
    ast:            list of [predicate(ast) -> None or raise Exception]
    re:             list of regular expressions that need to search() positively
    ban_re:         list of regular expressions that need to not search() positively
    constraints:    list of predicates, each either
        - string s -- used as eval(s, {'retval':, 'output':, 'args':, 'kwargs':, 'input':}) -> True/False
        - dict {rule:..., message:...} -- rule is eval'd as above, custom error message on failure
    args:           list of lists
    cases:          list of dicts {args:[], kwargs:{}, inputs:[], outputs:[], retval:any, predicate:code}
    
    
    solution:
        compile to a code object using wrapped builtins like user code
        exec user and solution, compare results
    outputs:
        if none, skip check
        if list, use as-is
        if string, eval(...) using wrapped builtins
    retval:
        if none, skip check
        else, use as-is
    predicate:
        the string body of a function to be called and (outputs, retval) arguments
        hence, always exec'd and then called
    constraints:
        always eval'd

This file is based on earlier code by the same author: https://github.com/tychonievich/pypractice
'''

def req_recursion(node):
    '''An ast predicate tshat requires a recursive function somewhere in the tree'''
    # recursion occurs when a node invokes itself
    # that requires a depth-first traveral, not just a random walk
    import ast, _ast
    fstack = []
    recursive = set()
    def visit(node, dep=0):
        if isinstance(node, _ast.FunctionDef): fstack.append(node.name)
        if isinstance(node, _ast.Call) and isinstance(node.func, _ast.Name) and node.func.id in fstack:
            recursive.add(node.func.id)
        for n in ast.iter_child_nodes(node):
            visit(n, dep+2)
        if isinstance(node, _ast.FunctionDef): fstack.pop()
    visit(node)
    if len(recursive) == 0:
        raise ValueError('required a recursive function')
def no_loops(node):
    '''An ast predicate that requires no loops (for, while, or async for)'''
    import ast, _ast
    for f in ast.walk(node):
        if isinstance(f, (_ast.AsyncFor, _ast.For, _ast.While)):
            raise ValueError('use of loops prohibited')
def ban(*names):
    '''An ast predicate maker to prohibit particular variable, module, and function names (slightly more forgiving that banning lexemes)'''
    def ans(f):
        '''An ast predicate to prohibit particular variable, module, and function names (slightly more forgiving that banning lexemes)'''
        import ast, _ast
        for node in ast.walk(f):
            if isinstance(node, _ast.Name) and node.id in names:
                raise ValueError('use of name "'+node.id+'" prohibited')
            elif isinstance(node, _ast.Attribute) and node.attr in names:
                raise ValueError('use of name "'+node.attr+'" prohibited')
                
    return ans
    

def ex_msg(ex):
    '''turns an exception into a one-line and multi-line message.'''
    # Skips the top-level invocation, which is always from the testing harness.'''
    msg = ''
    tr = ex.__traceback__# .tb_next
    line = None
    fname = None
    while tr is not None:
        msg += '  File "'+tr.tb_frame.f_code.co_filename+'", line '+str(tr.tb_lineno)+'\n'
        line = tr.tb_lineno
        fname = tr.tb_frame.f_code.co_filename
        tr = tr.tb_next
    msg += ex.__class__.__name__+': ' + str(ex)
    if fname is not None and fname.endswith('testmaker.py'):
        smsg = 'test harness raised '+ex.__class__.__name__+':\n  '+str(ex)
    else:
        smsg = 'raised '+ex.__class__.__name__+(' on line '+str(line) if line is not None else '')+': '+str(ex)
    return smsg, msg

def case_str(case, func='f'):
    ans = ''
    if case.get('args',()) or case.get('kwargs',{}):
        ans += func+'('
    if case.get('args',()):
        ans += ', '.join(repr(_) for _ in case['args'])
    if case.get('kwargs',{}):
        if len(ans) > 0: ans += ', '
        ans += ', ',join(_k+'='+repr(_v) for _k,_v in case['kwargs'].items())
    if case.get('args',()) or case.get('kwargs',{}):
        ans += ')'
    if case.get('inputs',()):
        if len(ans) > 0: ans += '\n'
        ans += '\n'.join('input '+str(_+1)+': '+str(case['inputs'][_]) for _ in range(len(case['inputs'])))
    if ans.startswith('input 1:') and 'input 2:' not in ans:
        ans = ans.replace('input 1:', 'input:')
    return ans

def compare_result(wanted, got):
    '''A flexible comparison function
    w == g
    w is same/sub type of exception as g
    casting g to type(w) makes w == g work
    abs(w-g) < 1e-6
    w.search(g)
    if re.match("/.+/", w), treat w as regex and w.search(g)
    w(g)
    w is None and type(g) is str
    all(compare_result(w,g) for w,g in zip(w,g))
    '''
    if wanted == got: return True
    if wanted is None and got == ['']: return True
    if isinstance(wanted, BaseException):
        return isinstance(got, type(wanted))
    try:
        if type(got) is str and 'search' in dir(wanted) and wanted.search(got) is not None:
            return True
    except: pass
    try:
        if abs(wanted - got) < 1e-6: return True
    except: pass
    if type(got) is str and wanted is None: return True
    try:
        if type(wanted) in (tuple, list) and len(wanted) == len(got):
            return all(compare_result(w,g) for w,g in zip(wanted, got))
    except: pass
    if type(wanted) is str and type(got) is str:
        if wanted.strip() == got.strip(): return True
        if len(wanted) > 2 and wanted[0] == '/' and wanted[-1] == '/':
            import re
            if re.search(wanted[1:-1], got) is not None: return True
    if callable(wanted):
        try:
            if wanted(got): return True
        except: pass
    try:
        if type(wanted)(got) == wanted: return True
    except: pass
    return False

def run(exe, funcname=None, inputs=None, args=(), kwargs={}):
    co,tree,gl,io = exe
    if inputs is not None: io.reset(inputs)
    exec(co, gl)
    if funcname:
        if funcname not in gl:
            raise NameError('no function named '+funcname)
        elif not callable(gl[funcname]):
            raise ValueError(funcname+' is not a function')
        retval = gl[funcname](*args, **kwargs)
    else:
        retval = None
    return retval, io.outputs

def asfunc(src, header):
    if 'return' not in src and 'raise' not in src:
        src = src.strip()
        src = src.rsplit('\n', 1)
        src = ''.join(src[:-1])+'\nreturn '+src[-1]
    return header.strip(' :')+':\n    ' + src.strip().replace('\n', '\n    ')

def loadyaml(obj):
    """Given the results of yaml.safe_load(...), returns either (a) a single Tester
    of (b) a dict of Testers, one per func, with weight information"""
    if 'func' in obj and type(obj['func']) is dict:
        return MultiTester(obj)
    else:
        return Tester(obj)

class MultiTester:
    def __init__(self, obj):
        self.funcs = {}
        for fname in obj['func']:
            base = {**{k:v for k,v in obj.items() if k != 'func'}, **obj['func'][fname]}
            base['func'] = fname
            self.funcs[fname] = {
                'tester':Tester(base),
                'weight':base.get('weight',1),
            }
    def report(self, fname):
        bits = {k:v['tester'].report(fname) for k,v in self.funcs.items()}
        result = {
            'correctness':0,
            'feedback':'',
            'missed':[],
            'details':[]
        }
        num, denom = 0, 0
        for k,v in bits.items():
            result['feedback'] += '{}\n{}\n{}\n'.format('-'*30, (' '+k+' ').center(30,'-'), v['feedback'])
            result['missed'].extend(v.get('missed',[]))
            w = self.funcs[k]['weight']
            for e in v.get('details',[]):
                e.setdefault('weight',1)
                e['weight'] *= w / len(v.get('details',[]))
            result['details'].extend(v.get('details',[]))
            num += w * v['correctness']
            denom += w
        result['correctness'] = num / denom
        result['feedback'] += '-'*30
        return json_ready(result)

def json_ready(obj):
    """removes exceptions"""
    if type(obj) is dict:
        return {k:json_ready(v) for k,v in obj.items()}
    elif type(obj) in (list, tuple):
        return [json_ready(v) for v in obj]
    elif isinstance(obj, BaseException):
        return ex_msg(obj)
    else:
        return obj

class Tester:
    def __init__(self, obj):
        self.allow = obj.get('imports', ())
        self.banned = obj.get('ban', ())
        self.astchecks = []
        if obj.get('recustive') is True: self.astchecks.append(req_recursion)
        if obj.get('loops') is False: self.astchecks.append(no_loops)
        if 'ast' in obj:
            self.astchecks.append(compile(obj['ast'], 'ast_check.py', 'exec'))

        if 'solution' in obj:
            self.solution = self.compile(obj['solution'], loose_rules=True)
        self.func = obj.get('func')
        self.exact = obj.get('exact', True)
        self.cases = []
        for case in obj.get('cases', ()):
            ans = {} #{'args':(), 'kwargs':{}, 'inputs':(), 'outputs':None, 'retval':None}
            ans.update(case)
            if type(ans.get('outputs')) is str:
                ans['outputs'] = self.compile(asfunc(ans['outputs'], 'def printed()'))
            if type(ans.get('predicate')) is str:
                ans['predicate'] = self.compile(asfunc(ans['predicate'], 'def predicate(retval, outputs, args, kwargs)'))
            if 'name' not in ans: ans['name'] = case_str(ans, self.func or 'f')
            if 'weight' not in ans: ans['weight'] = 1
            self.cases.append(ans)
        for args in obj.get('args', ()):
            ans = {'args':tuple(args)}
            if 'name' not in ans: ans['name'] = case_str(ans)
            self.cases.append(ans)
        for inputs in obj.get('inputs', ()):
            ans = {'inputs':tuple(inputs)}
            if 'name' not in ans: ans['name'] = case_str(ans)
            self.cases.append(ans)
        self.constraints = []
        for constraint in obj.get('constraints', ()):
            ans = {}
            if type(constraint) is str:
                ans['rule'] = self.compile(asfunc(constraint, 'def rule(retval, outputs)'))
            else:
                ans['rule'] = self.compile(asfunc(constraint['rule'], 'def rule(retval, outputs)'))
                if 'message' in constraint: ans['message'] = constraint['message']
            self.constraints.append(ans)
        self.mustchange = obj.get('mustchange', False)
        self.maychange = self.mustchange or obj.get('maychange', False)
        

    def compile(self, src, filename='solution', mode='exec', loose_rules=False):
        if loose_rules:
            return modwrap.safe_execable(filename, code=src, imports=self.allow)
        else:
            return modwrap.safe_execable(filename, code=src, imports=self.allow, ban_tokens=self.banned)


    
    def test(self, filename):
        try:
            user = modwrap.safe_execable(filename, imports=self.allow, ban_tokens=self.banned)
            try:
                for check in self.astchecks:
                    if callable(check):
                        check(user[1]) # should throw an exception on violation
                    else:
                        exec(check, {'tree':user[1]})
            except ValueError as ex:
                return [{
                    'name':'Coding rules: '+str(ex),
                    'correct':False,
                    'weight':1,
                    'feedback':str(ex),
                }]
                
            results = []
            for case in self.cases:
                report = {
                    'name':case['name'],
                    'correct':False,
                    'weight':case.get('weight',1),
                    'feedback':case.get('feedback'),
                }
                if 'args' in case: report['args'] = case['args']
                if 'kwargs' in case: report['kwargs'] = case['kwargs']
                if 'input' in case: report['input'] = case['input']
                if case.get('hide'): report['hide'] = True
                
                
                try:
                    _uca, _uck = copy.deepcopy(case.get('args',())), copy.deepcopy(case.get('kwargs',{}))
                    _gca, _gck = copy.deepcopy(case.get('args',())), copy.deepcopy(case.get('kwargs',{}))
                    uo, go = [], []
                    try:
                        ur, uo = run(user, self.func, case.get('inputs'), _uca, _uck)
                    except BaseException as ex:
                        ur = ex
                    try:
                        gr, go = run(self.solution, self.func, case.get('inputs'), _gca, _gck)
                    except BaseException as ex:
                        gr = ex
                    
                    if isinstance(ur, BaseException) and not isinstance(gr, BaseException):
                        raise ur
                    
                    if self.func: report['return'] = ur
                    if uo != [""]: report['output'] = uo
                    
                    if len(user[3].inputs) > 0:
                        report['error'] = 'inputs '+ str(user[3].inputs)+' unread'
                        results.append(report)
                        continue
                    
                    
                    
                    # run constraints first (they can have specialized messages)
                    failed = False
                    for con in self.constraints:
                        r,o = run(con['rule'], 'rule', None, (ur, uo), {})
                        if r is False:
                            report['error'] = case.get('message', 'wrong result')
                            results.append(report)
                            failed = True
                            break
                    if failed: continue
                    if not self.maychange and (_uca != case.get('args',()) or _uck != case.get('kwargs',{})):
                        report['error'] = case.get('message', 'modified argument(s)')
                        results.append(report)
                        continue
                    if self.mustchange and (_uca != _gca or _uck != _gck):
                        report['error'] = case.get('message', 'argument(s) not changed correctly')
                        results.append(report)
                        continue
                    
                    # if there is a predicate, use that
                    # if not but there is retval and/or outputs, use them, running them if needed
                    # else if exact, compare ur and gr, uo and go
                    if 'predicate' in case:
                        r,o = run(case['predicate'], 'predicate', None, (ur, uo, _uca, _uck), {})
                        if r == False:
                            report['error'] = case.get('message', 'wrong result')
                            results.append(report)
                            continue
                    elif 'retval' in case or 'outputs' in case:
                        if 'retval' in case and not compare_result(case['retval'], ur):
                            report['error'] = case.get('message', 'returned wrong value')
                            report['expected'] = case['retval']
                            results.append(report)
                            continue
                        if 'outputs' in case:
                            rv = case['outputs']
                            if type(rv) is tuple:
                                rv = run(rv, 'printed')[0]
                            if not compare_result(rv, uo):
                                report['error'] = case.get('message', 'printed wrong text')
                                report['expected'] = rv
                                results.append(report)
                                continue
                    elif self.exact:
                        if not compare_result(gr, ur):
                            if isinstance(gr,BaseException) and not isinstance(ur,BaseException):
                                report['error'] = case.get('message', 'expected to raise an Exception')
                            else:
                                report['error'] = case.get('message', 'returned wrong value')
                                report['expected'] = gr
                            results.append(report)
                            continue
                        if not compare_result(go, uo):
                            report['error'] = case.get('message', 'printed wrong text')
                            report['expected'] = go
                            results.append(report)
                            continue

                    # if made it here, passed all tests
                    report['correct'] = True
                    results.append(report)

                except modwrap.TestPermissionError: raise
                except BaseException as ex:
                    if isinstance(case.get('retval'), BaseException):
                        report['correct'] = True
                    else:
                        report['error'] = ex_msg(ex)[0]
                    results.append(report)
            return results
        except BaseException as ex:
            return ex

    def report(self, filename):
        """Run tests and return a dict with 4 entries:
        feedback:       string to be shown before due
        missed:         list of test cases missed
        correctness:    a number between 0 and 1
        details:        every case tested in full detail
        """
        res = self.test(filename)
        if isinstance(res, SyntaxError):
            return {
                "feedback":res.__class__.__name__+': ' + str(res),
                "missed":[res.__class__.__name__+': ' + str(res)],
                "correctness":0,
                "details":ex_msg(res)
            }
        elif isinstance(res, BaseException):
            msg,tb = ex_msg(res)
            return {
                "feedback":"Unexpected crash of autotester. If this happens more than once, inform a course instructor.",
                "missed":["Unexpected crash of autotester. If this happens more than once, inform a course instructor."],
                "correctness":0,
                "details":ex_msg(res)
            }
        ans = {
            "correctness":0,
            "feedback":[],
            "missed":[],
            "details":[],
        }
        yes, no, pts, denom = 0,0,0,0
        holdout = False
        for rep in res:
            ans['details'].append(rep)
            
            if rep.get('feedback'):
                ans['feedback'].append('{}: {}'.format(('PASSED' if rep['correct'] else 'FAILED'), rep['feedback']))
                if str(rep.get('error','')).startswith('raised '):
                    ans['feedback'][-1] += '\n    (' + str(rep.get('error',''))+')'
            else:
                if rep['correct']: yes += 1
                else: no += 1
            
            if not rep['correct'] and not rep.get('hide'):
                ans['missed'].append(rep['name'])
            elif not rep['correct']: holdout = True
            
            if rep['correct']: pts += max(0, rep['weight'])
            else: pts += min(0, rep['weight'])
            denom += max(0, rep['weight'])
        
        if denom: ans['correctness'] = pts / denom
        
        if yes and no:
            ans['feedback'].append('Passed {} out of {} additional tests'.format(yes, yes+no))
        elif yes:
            ans['feedback'].append('Passed all additional tests')
        elif no:
            ans['feedback'].append('Failed all additional tests')
        ans['feedback'] = '\n'.join(ans['feedback'])
        
        if len(ans['missed']) == 0 and holdout:
            ans['missed'].append('other hidden test cases')
        
        return json_ready(ans)
        
