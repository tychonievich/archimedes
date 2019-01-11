This file describes the code available at <https://github.com/tychonievich/archimedes>

# Overview

When first designed, this was a webpage where students could upload files.
Then we added autograding feedback, then rapid TA view of submissions,
then rubrics, and more complicated rubrics, and... it keeps getting bigger.
This version has a submission system, gradebook, and an extension and regrade request system.
It will likely expand more over time.

The name "Archimedes" is not carefully selected.
When I asked our sysadmins for a server to run the second version of this on
they gave me one from a rack of computers another faculty member had recently had,
and the former faculty member (I don't even know who they were) had named the computer in question "Archimedes".


# Directory Structure

Use netbadge to protect web access;
use `.htaccess` to restrict access to folder `uploads/`, `meta/`, `users/`;
ensure Apache has write-access to all three restricted folders and any subfolders you add.

-   `uploads/`
    -   slug`/`
        -   `.gradelog` *append-only, one JSON object per line; keys timestamp,grader,user,slug,grade,comments; comments' value is an object with keys note, minor, major, etc and values as lists*
        -   `.rubric` *a JSON object defining the rubric for this assignment (optional)*
        -   userid`/`
            -   `.extension` *only present in unusual circumstances; a JSON object with fields "due" and "late"*
            -   file.py
            -   `.excused` *only present in unusual circumstances; it's contents are never read, only its presence*
            -   `.`20171231`-`235959`/` *upload time*
                -   file.py
            -   `.autofeedback` *inserted by autograder*
            -   `.grade` *the most recent JSON line for this assignment from .gradelog*
            -   `.view` *the ID of the grader currently viewing this for grading, if any*
-   `meta/`
    -   `assignments.json` *all the data the system knows about each assignment (required)*
    -   `roster.json` *all the data the system knows about each user (generated)*
    -   `coursegrade.json` *rules for combining grades into a course grade (optional)*
    -   `buckets.json` *a JSON object defining the default set of buckets for assignments (optional)*
    -   `.`20171231`-`235959`-roster.json` *backup data*
    -   `queued/`
        -   slug`-`userid *created to signal ifnotify watchers that this needs autograding; recursive inotify watch unwise because there are too many directories for some inotify limits*
    -   `support/`
        -   test_file.py
        -   gradetools.py
        -   ...
    -   `requests/`
        -   `extension/`
            -   slug`-`userid *whatever they wrote in raw form*
        -   `regrade/`
            -   slug`-`userid *whatever they wrote in raw form*
-   `users/`
    -   userid`.json` *a copy of a single user's record; must match `meta/roster.json` (generated)*
    -   userid`.jpg` *a picture of this user (optional)*
    -   `.`userid *(graders only) the path of the most-recently viewed submission for grading*
    -   `.graded/`
        -   userid`/`
            -   `.`slug *computing IDs of students who have been graded, one per line in chronological order, with no other information*
-   `spreadsheet-reader/`
    -   a copy of <https://github.com/nuovo/spreadsheet-reader> (tested with v.0.5.11, though we don't use any special functions so it should be fairly version-agnostic)


# File Formats

## `roster.json`

You should never need to edit this yourself; simply upload roster spreadsheets.
The upload does some consistency processing (keeps `users/mst3k.json` in sync with `meta/roster.json`, fills in missing names, adds cross-links for graders, etc.).

As of December 2017 (it changes without notice), a collab roster export .xlsx will populate:

-   id
-   name
-   email address
-   role
-   groups

Role is used internally: any role that contains `teach`, `instruct`, or `prof`; or that is the exact string `TA`; will be treated as a staff role and given extra permissions on the site.
The exact (case-sensitive) strings `Instructor`, `Professor`, and `Teacher` give the ability to upload new rosters, handle extension requests, and other course-administration tasks.

`name` is used extensively for display purposes, and will default to `name unknown` if not provided in any roster.

The particular heading `grader` is used to assign students to particular TAs as graders, and should be the computing ID of some user with a staff role.

You can add as many additional fields as you like simply by uploading additional spreadsheets.
As long as one column is a computing ID and contains a header that ends ` id` in some case, it should work fine.


## `assignments.json`

A single JSON object.
Each key must be a valid directory name, and must be unique; it will be used to construct the uploads directory structure and will also be displayed to users as the primary ID of assignments to upload.
Each value should itself be a JSON object; two keys are required for uploads to be enabled:

-   `due` should be the due date and time, as a string, in any format PHP can parse (it is quite flexible).

-   `files` should be either a single string, or a list of strings, which are glob patterns.
    To permit any file at all, try `*`.
    If a list, uploads matching any element of the list are accepted.
    
    Note: PHP also has an upload size limit, usually defaulting to 8MB and overridable in `.htaccess`.

The following keys, if present, also have defined meaning:

-   `open`, a date and time at which uploads are first accepted

-   `late-policy`, a list of numeric point multipliers to assign for each 24-hour period, or part thereof, that they are late.
    For example `[0.9, 0.8]` means they can be up to 48 hours late and if they are, e.g., 27 hours late they get only 80% of the points their submission earns in grading (penalties are not cumulative).
    
    If `close` is also specified and is later than the last element of the `late-policy` list, the last element is assumed to apply until the close date as well. 

-   `close`, a date and time after which submission are no longer accepted.
    
    If there is no `close`, it defaults to `due` + 24 hours per element of `late-penalty`.
    If there is no `close` or `late-penalty`, it defaults to the same as `due`.

-   `total`, the maximum number of points for this assignment.

    If there is no `total`, grades will be displayed to the student in percentages, not points.
    Grades are always recorded in ratios (between 0 and 1) so `total` can be changed after grading without issue.

-   `support`, a list of file names (stored in `meta/support/`) that are needed to run the tests of this assignment.

-   `tester`, a single testing file to run to generate `.autofeedback`; if you need multi-file tests, identify one driver file as `tester` which uses other files, listed as `support`.

-   `extends`, a list of other assignment keys whose submissions should be assumed to also have been submitted for this assignment. Order matters: the first listed assignment in extends with a given file has its' copy used.

-   `rubric`, a rubric object that has the same format as, and supersedes, `.rubric`.

-   `group`, an assignment group name for course grade computation.

-   `weight`, the relative grade importance of this assignment compared to others in its `group`.

    Defaults to `1` if omitted.
    For example, if two midterms and the final exam are the entries of the `"Exam"` group
    and the final has `"weight":2`, the total grade for the exam group will be $(m_1 + m_2 + 2 m_3) \div 4$.
    
## `coursegrade.json`

Three required fields:

-   `letters`: an array (from highest to lowest) of objects, each with a single key (a letter grade, like `B-`) and a ratio-of-full-points value (like `0.8`) needed to get that grade.

    If these are not scaled 0--1, letters greater than 1 will never be assigned.
    If a grade is below the last entry, the last entry will be used for it anyway.

-   `weights`: an object with keys as names of groups of assignments (these form the valid set of values for `assignments.json`'s `groups` fields) and values as overall grade weight of that group.
    
    There is no need to normalize `weights`: the system will divide by the sum of all available weights automatically.

-   `drops`: an object with keys as names of groups of assignments and values as a number of submissions of this group that are dropped. Missing entries default to 0.


### Per-section Assignments

Optionally, assignment groups may also be restricted based on a `group` field in `users.json`, if any.

-   `exclude`: an object with keys as names of groups of assignments and values as arrays of user `group`s that do not include this group of assignments in their course grade.

-   `include`: an object with keys as names of groups of assignments and values as arrays of user `group`s that do include this group of assignments in their course grade.

An assignment group is counted for a given user in the following cases:

-   If the assignment group is in `include`, it is counted only if at least one of the user's `group`s is in `include`.
-   Otherwise, if the assignment group is in `exclude`, it is counted only if none of the user's `group`s is in `exclude`.
-   Otherwise, it is counted.


## `buckets.json` and `.rubric`

Rubrics are specified in JSON objects.
Three kinds of rubrics are currently available.

### Percentages

The default grading system unless `buckets.json` as been created;
it requires a number and a comment for every grade,
allowing just one number and just one comment.

```json
{"kind":"percentage"}
```

In the event that you want to be able to add multiple free-form comments, *buckets* are a good way of avoiding multiple jeopardy.
If you don't like the structure of *buckets*, you could also get free-form graded comments by using `{"kind":"breakdown","parts":[]}`{.json} to get just the multipliers list.
These will be multiplied, not added, which is nicer to the student: ten −10% comments will be `pow(0.9, 10)`, or about 35%.

### Breakdowns

The most common form of rubric, a breakdown splits the available points into one or more categories,
each with its own rubric.
Every breakdown also allows some additional multiplicative percentages, suitable for adding not-in-the-breakdown grade adjustments such as extra credit or penalties outside the primary purpose of the assignment.

```json
{"kind":"breakdown"
,"parts":[{"name":"correctness"
          ,"ratio":0.6666666666666666
          ,"rubric":{...}
          }
         ,{"name":"dialog"
          ,"ratio":0.3333333333333333
          ,"rubric":{...}
          }
         ]
}
```


### Buckets

```json
{"kind":"buckets"
,"buckets":[{"name":"notes","score":1.0,"spillover":0}
           ,{"name":"mistakes","score":0.9,"spillover":3}
           ,{"name":"moderate errors","score":0.7,"spillover":2}
           ,{"name":"serious errors","score":0.5,"spillover":-3.5}
           ,{"name":"score-zeroing errors","score":0.0,"spillover":0}
           ]
}
```

### Binary

TO DO: add this option

```json
{"kind":"binary"}
```

## `.autofeedback` and `.grade`

A single JSON object, containing a set of top-level fields and optionally a nested grade detail.

### Top-level fields:

All of the following are optional:

-   `timestamp`, a date-time string indicating when this data was generated.

-   `stdout`, a string of `pre`formatted data to show to the student.

-   `stderr`, a string of `pre`formatted data to show to the grader.

-   `grader` and `student`, computing IDs.

-   `grade`, the ratio of possible points earned (between 0 and 1).

-   `.mult`, a list of *percentage* objects (currently used only for late penalties).

-   `details`, a *nested grade breakdown* representing the actual grade of the assignment.

-   `pregrade`, a *nested grade breakdown* representing a guess to be used to pre-fill a grader's view.

### Nested grade breakdown

Information that, combined with a `.rubric`, represents exactly how grading was performed.
It is meaningless without an associated `.rubric`, and has a different structure for each `.rubric` `kind`.

#### Percentage

A JSON object containing two entries:

-   `ratio`, a number between 0 and 1 representing how correct the graded item is.
-   `comment`, a string describing why the ratio was assigned.

#### Breakdown

A JSON object containing optional entries `.mult` and `.earned` and any subset of the breakdown categories in the associated rubric.

-   `.mult`'s value is a list of *percentages* by which the entire breakdown score is multiplied.

-   `.earned`'s value is a ration (between 0 and 1) of the total points from this breakdown (present as a cache to simplify student views).

-   Each other entry's value is a nested grade breakdown.

#### Buckets

A JSON array, containing exactly the number of elements of the associated rubric.
Each element is an array of strings, the comments of the associated bucket.

## `.gradelog`

One `.grade`-formatted JSON object per line, in append-only format.
Replicates data in `.grade` files, but keeps historical record and simplifies full-course grade reports.

# Known bugs and missing features

If there is no grader, only one grader, or files have only been submitted for one grader, then there is no link to get to the review page.

There is no easy way to do attendance-like grade yet.

Many of the views could be much more compact in the common case.

Keyboard navigation in grading view not yet designed or implemented.

There is no upload interface for `assignments.json`, `buckets.json`, or `coursegrade.json`.

There is no way to seed comments for a rubric other than by grading.

If a rubric changes, all existing grades become challenging to interpret.

The entire code base is in desperate need of a refactoring and commenting pass.

# Porting guidelines

This entire system was designed to work with UVA CS's systems.
However, it should be fairly straightforward to port to other systems:

1.  Install Apache (or any other PHP-enabled web server).

2.  This project uses the most widely deployed database in the world: a directory-tree file system.
    Internally, it assumes a *nix file system on a local drive;
    file systems that do not use the `/` directory separator,
    that do not skip `.dotfiles` in wildcard matches,
    or that cut corners commonly cut in network-mounted file systems
    will not work.

3.  Fix the log-in system.

    This code assumes an Apache module that,
    on a correctly configured system, interfaces with UVA's NetBadge system,
    rejects those not authenticated,
    and sets `$_SERVER['PHP_AUTH_USER']` prior to loading any `.php` scripts.
    However, it is also written to isolate that dependence into a single function:
    `logInAs` in `tools.php`, which is always invoked with no arguments except internally by `logInAs` itself.
    Modifying that function should allow it to work with any other log-in system.

4.  Fix the roster assumptions.
    Because UVA uses a house build of Sakai (called Collab) as its officially supported LMS,
    a few elements of that tool's roster as assumed:
    
    -   the same ID used in the login system is in every table with a header ending `ID`
    -   there is a header `role` that distinguishes students from TAs from faculty
    -   if some assignments are limited by section, sections must be given in a comma-separated string under a heading `groups`
    
    See the line commented `// normalize the various forms of computing ID` and the functions `hasFacultyRole` and `hasStaffRoll` in `tools.php` if you need to change these assumptions for your rosters.
    Note that originally this information was much more widely spread through the code,
    and it is possible I missed some when pulling it together.

6.  Add yourself to the global `$superusers` array in `tools.php`.

5.  Set up the correct directory structure, including at least a minimal `assignments.json`.

    We use the following root-directory `.htaccess`, to enable UVA's netbadge plugin:
    
        require valid-user
    
    And the following `.htaccess` in `meta/`, `uploads/`, and `users/`:
    
        deny from all

    
    

# Old notes that may still have some value

-   a **grade** consists one of the following:
    -   a **percentage**, being a score and a comment
    -   a *breakdown* and a set of *multiplier*s, where
        -   a **breakdown** is a set of weighted *grades*
        -   a **multiplier** is a *percentage*
        -   `result = sum(weight*grade for (weight, grade) in breakdown) * product(multipliers)`
    -   a *bucket chain* and *payload*, where
        -   a **bucket chain** is an ordered list of *buckets*
            -   each **bucket** is 
                -   a *percentage* with a severity-identifying comment and a score worth less than the previous bucket
                -   a **spillover count**; reaching or exceeding this number of payload items in the bucket causes a single entry in the next bucket
        -   a **payload** is a set of comments allocated to buckets
        -   result = score of last bucket with payload, linearly extrapolated to spillover limit

The intended purpose of a multiplier is to capture out-of-band mistakes:
late penalties are the classic example, but this might also be where you penalize someone for using cuss words for variable names, or bad indentation on a problem that does not have indentation in the breakdown, or plagiarism, etc.

The default bucket chain is:

-   Notes, 100%, no spillover
-   Minor errors, 90%, spillover 3
    -   problems that suggest inattention or imprecision of understanding or execution
-   Major errors, 70%, spillover 2
    -   problems that suggest a localized misunderstanding 
-   Serious errors, 50% spillover 6
    -   problems that suggest a significant misunderstanding
-   Score-zeroing errors, 0%
    -   failure to submit, gibberish, etc

In a `.grade`, store

-   percentage: `{"ratio":0.78, "comment":"a solid C+ performance"}`{.json}
-   breakdown: `{"function header":..., "computation":..., "style":..., ".mult":[{...}, {...}]}`{.json}
-   payload: `[["next time, use more descriptive variable names"],[],["should return, not print"],[],[]]`{.json}

A `.grade` is incomplete without an associated `.rubric`.

In a `.rubric`, store

````json
{"kind":"percentage"}
````

````json
{"kind":"breakdown"
,"parts":[{"name":"function header","ratio":0.3,"rubric":...}
         ,{"name":"computation","ratio":0.5,"rubric":...}
         ,{"name":"style","ratio":0.2,"rubric":...}
         ]
}
````

````json
{"kind":"buckets"
,"buckets":[{"name":"notes","score":1.0,"spillover":0}
           ,{"name":"minor errors","score":0.9,"spillover":3}
           ,{"name":"major errors","score":0.7,"spillover":2}
           ,{"name":"serious errors","score":0.5,"spillover":-3}
           ,{"name":"score-zeroing errors","score":0.0,"spillover":0}
           ]
}
````

A default buckets list for the entire course may be provided in `meta/buckets.json`.
If so provided, the "buckets" entry is optional.

For a given assignment, the following are checked to determine the rubric, stopping on the first successful check:

-   `uploads/`assignment`/.rubric`
-   `uploads/.rubric`
-   if `meta/buckets.json` exists, `{"kind":"buckets"}`{.json}
-   `{"kind":"percentage"}`{.json}

To assist in consistent grading, a pool of comments are collected and shared with all graders.
These are stored on a per-assignment basis with an associated path.
Paths are stored as a newline-separated string of breakdown names, possibly terminated by a bucket index.
For example,

```json
   {"":{"ratio":0.79,"comment":"per the syllabus, code that doesn't work earns at most a C+"}

   {"functionality\n0":"good job!"}
   {"functionality\n1":"fails for n=0"}
```
For example, a simple percentage might be `{"path":[],"comment":{"ratio":0.79,"comment":"per the syllabus, code that doesn't work earns at most a C+"}`{.json};
a minor-error comment in the "style" part of "part 2" might be `{"path":["part 2","style",1],"comment":"supposed to use lower_case, not camelCase"}`{.json}.
All of these are appended on lines of a file `/meta/commentpool/`assignment`.json`.

To avoid comment pool proliferation but still allow detailed feedback, the grader interface separates comments into two parts: a required generic comment, which is pooled, and an optional detailed part, which is not.




````
grader view
    


Autofeedback
Previous grade
display view of files
    highlight code
    size-limit image
download link for files
download .zip for all files, support, and testers

Task:
    notes:
        [ ] good job!
        [ ] should pick better variable names
        [_______________________] [add]
    minor errors:
    major errors:
    serious errors:
    score-zeroing errors:

{"task":"...", "category":"...", "comment":"..."}

tasks:
    task1:
        mode: breakdown
        parts:
            function header: 3
            syntax: 2
            functionality: 5
    task2:
        total: 10
        mode: severity
severities:
    notes: 0
    minor: 0.1
        overflow:
            add: 0.1
            beyond: 1
            max: 0.3
    major: 0.3
        minor: 3
    serious: 0.5
        major: 2
        overflow:
            add: 0.1
            beyond: 2
            max: 0.8
    score-zeroing: 1.0
````
