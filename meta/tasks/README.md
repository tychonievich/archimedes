The markup in this document is based on [pypractice](https://github.com/tychonievich/pypractice/blob/master/request-for-problems.md),
with several adjustments.

# Task specification

Tests are written in [YAML](http://yaml.org), a human-readable data specification language.
JSON is a valid subset of YAML, so feel free to use JSON if you prefer.

```yaml
source: exercise 4.03 from http://www.spronck.net/pythonbook/
solution: |-
    CENTS_IN_DOLLAR = 100
    CENTS_IN_QUARTER = 25
    CENTS_IN_DIME = 10
    CENTS_IN_NICKEL = 5

    amount = int(input('How many cents? '))
    cents = amount

    dollars = int( cents / CENTS_IN_DOLLAR )
    cents -= dollars * CENTS_IN_DOLLAR
    quarters = int( cents / CENTS_IN_QUARTER )
    cents -= quarters * CENTS_IN_QUARTER
    dimes = int( cents / CENTS_IN_DIME )
    cents -= dimes * CENTS_IN_DIME
    nickels = int( cents / CENTS_IN_NICKEL )
    cents -= nickels * CENTS_IN_NICKEL
    cents = int( cents )

    print( amount / CENTS_IN_DOLLAR, "consists of:" )
    print( "Dollars:", dollars )
    print( "Quarters:", quarters )
    print( "Dimes:", dimes )
    print( "Nickels:", nickels )
    print( "Pennies:", cents )
cases:
    - inputs: [94]
      outputs: [null, '/.*\b0.94\b.*\b0\b.*\b3\b.*\b1\b.*\b1\b.*\b4\b.*/']
      feedback: 94¢ coinage computation

    - inputs: [94]
      feedback: 94¢ coinage and formatting

    - inputs: [1156]
      feedback: case in author-provided code

    - inputs: [0]
    - inputs: [1200]
    - inputs: [120000]
    - inputs: [75]
    - inputs: [50]
    - inputs: [25]
    - inputs: [20]
    - inputs: [10]
    - inputs: [5]
    - inputs: [4]
    - inputs: [3]
    - inputs: [2]
    - inputs: [1]
```


## Required Fields

-   A `solution`.  This must be valid Python code that solves the problem correctly.  It needn't be pretty.
-   A `func`tion name if and only if this is a test for a function, not a program.
    Currently a separate task file is needed for each function in a multi-function assignment.
    
    If the `func`'s value is an object,
        
    - each key is taken as a function name
    - any fields that could appear at the top-level scope (except for `func`) may appear distinctly for each `func`
    - each may have a different `weight`, defaulting to 1 id not specified
    
-   Some way of specifying test cases (see [Specifying test cases] below).

## Optional Fields

-   `exact: False`{.yaml} to indicate that output and return comparisons should not be performed by default.

-   `maychange: True`{.yaml} to give students permission to modify the contents of arguments if they so desire; without this modifying arguments is an automatic fail.

-   `mustchange: True`{.yaml} to have an implicit constraint added that requires student code post-invocation arguments to be `==` to reference-code post-invocation arguments.

-   a list of permitted `imports` (by default, all `import` statements are banned; any you want to permit need to be listed explicitly)

    ````yaml
    imports: [math, re]
    ````

-   `constraints` on code behavior, each taking the form of either a single python expression or the body of a python function with access to the variables `outputs` and `retval` (see [On `outputs`] below). May be paired with a `message` to present a particular message to the user if the constraint is violated.

    ````yaml
    constraints:
      - "type(retval) is str"
      - 
        rule: |
          if retval in outputs:
            return False
          else:
            return outputs.index(reval) > 0
        message: "You should print what is returned after the first input() is run"
    ````


-   constraints on souce code, including
    
    -   `ban: [prohibited, identifiers]`{.yaml} to prevent the use of particular identifiers (such as `abs`, `print`, or `sqrt`).

    -   `recursive: True`{.yaml} to require a recursive function in the source code.

    -   `loops: False`{.yaml} to prohibit the use of `while`{.python} and `for`{.python}.

    -   `ast: |-` code that handles a global ast-valued variable `tree` to either raise a `ValueError` with a user-centric message string not so raise.
        
    
    ````yaml
    ban:
      - pow
      - log
    recursive: True
    loops: False
    ast: |-
      import ast
      for f in ast.walk(tree):
          if isinstance(f, ast.Pow):
              raise ValueError("use of ** prohibited")
    ````

## Specifying test cases

Many different specification formats are supported.

### Available variables

Each test case specifies some subset of

-   `args`, a list of values to be passed to the `func`tion as positional arguments
    
    if an element of `args` is a string both beginning and ending with a backtick,
    the contents between the backticks will be `eval`ed by the testmaker
    
-   `kwargs`, a dict of named values to be passed to the `func`tion as keyword aguments
-   `inputs`, a list of things to simulate the user typing in response to each `input(...)` command
-   `retval`, the value returned by the `func`tion
-   `outputs`, a list of things printed with `print` and `input` separated by inputs.
-   `predicate`, a function body with parameters `retval`, `outputs`, `globals`, `args`, and `kwargs`. The `args` and `kwargs` passed to a `predicate` are the post-invocation values, to permit checking functions that are supposed to modify their arguments.

Each case may also specify

-   `name`, shown to the user after the due date to describe the test cases missed,
    defaulting to a representation of `args`, `kwargs`, and `inputs`
-   `feedback`, shown to the user before the due date to describe test cases passed or missed;
    without this, test cases are counted, not listed individually
-   `hide` -- if `true`, prevents this test case from showing up even after due date passes (e.g., as a safeguard against hard coding)
-   `message`, currently logged internally only, defaulting to 
    `"wrong result"` for `predicate`s and `constraints`, 
    `"returned wrong value"` for `retval`s, and 
    `"printed wrong text"` for `outputs`.
-   `weight` -- the relative importance of this case, defaulting to 1.

#### On `outputs`

`outputs` always has exactly one more element than `inputs` and is split around inputs,
so the following program

````python
print("Hi!")
ignore = input("Who are you? ")
print("Nice to meet you.")
````

would have, as its `outputs`, `["Hi!\nWho are you? ", "Nice to meet you.\n"]`{.python}
Outputs is always at least one element long, so even

````python    
pass
````

will have, as its `outputs`, `[""]`{.python}

#### On missing values

If `args`, `kwargs`, and/or `inputs` are not specified, they default to `[]`{.python} or `{}`{.python}.

If one of `retval` and `outputs` is specified but not the other, the other is not checked.
If neither `retval` nor `outputs` is specified and `exact: False`{.yaml} is not specified either,
then both `retval` and `outputs` must be identical to those produced by the `solution`.
If neither `retval` nor `outputs` is specified and `exact: False`{.yaml} is specified,
neither `retval` nor `outputs` are checked except as required by `constraints`.

### Controlling visibility

This system provides three levels of feedback.

- `"feedback"` will be a string listing passed/failed messages listed under `feedback`,
    with "passed $x$ out of $y$ additional tests" for tests that have no `feedback`
    and that are not hidden with `hide: true`{.yaml}.
    Hidden cases are not shown at all.

- `"missed"` is an array of strings, each one being the `name`
    (defaulting to a summary of the function arguments and typed inputs)
    of one failed test that is not hidden with `hide: true`{.yaml}.

- `"details"` is an array of objects, one per test performed.

The intended use is to release `"feedback"` on a limited, early basis
and provide `"missed"` at some cost to the user.
But there are many other options available:
you could use `name`s to hide the details of `"missed"` test cases,
or use visible 0-`weight` cases and `hide` all non-0 `weight` cases,
etc.

### Specification formats

Test cases may be specified in any of the following ways:

#### Explicit test cases

Each test case may include any subset of case specification fields:

````yaml
cases:
  - args: [1, -14]
    inputs: [3]
    outputs: "[None]*2"
  - inputs: ['']
    args: [2, 3]
    outputs: "['/.*[Nn]ame.*[:?] $/', None]"
    message: "prompt must end '? ' or ': '"
    name: "(formatting)"
  - inputs: ['1,234', '1234']
    args: [0, 0]
    predicate: "outputs.length == 3"
    message: "you should keep asking for input until a number is typed"
````

#### Constraints

Constraints are applied on all cases, regardless of other `outputs`, `retval`, and/or `predicate` checks.

````yaml
constraints:
  - rule: "type(retval) is int"
    message: "must return an integer"
````

#### Just `args` or `outputs`

If you simply want to say "it should match the reference for these cases",
you can list `args` or `inputs` as top-level fields of the test case.

<table><thead><tr><th>This input</th><th>is the same as this input</th></tr></thead>
<tbody><tr><td>
````yaml
args:
  - [0, 0]
  - [5, 0]
````
</td><td>
````yaml
cases:
  - args: [0, 0]
  - args: [5, 0]
````
</td></tr><tr></td>
````yaml
inputs:
  - ['']
  - ['so wise!']
````
</td><td>
````yaml
cases:
  - inputs: ['']
  - inputs: ['so wise!']
````
</td></tr></tbody></table>

## Comparisons

When providing `outputs` or `retval`s, a flexible comparison function is used.
If `w` is the wanted value (specified in the YAML file) and `g` is the got value (produced by user code),
all of the following will register as a match:

-   `w == g`
-   `w` is same/sub type of exception as `g`
-   casting `g` to `type(w)` makes `w == g` work
-   `abs(w-g) < 1e-6`
-   if `search` is a member of `w`, `w.search(g)` -- intended for compiled `re` objects
-   if `re.match("/.+/", w)`, strip the `/`s and from `w`, compile the rest as a regex, and run `w.search(g)`
-   if `w` is callable, `w(g)`
-   `w is None and type(g) is str`
-   `all(compare_result(w,g) for w,g in zip(w,g))`

