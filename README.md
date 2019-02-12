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
We don't use that computer anymore, but the name stuck.


# Directory Structure

Use netbadge to protect web access;
use `.htaccess` to restrict access to folder `uploads/`, `meta/`, `users/`;
ensure Apache has write-access to all three restricted folders and any subfolders you add.

-   `uploads/`
    -   slug`/`
        -   `.gradelog` *append-only, one JSON object per line; keys timestamp,grader,user,slug,kind,comments, and several kind-specific entries*
        -   `.rubric` *a JSON object defining the rubric for this assignment (optional)*
        -   userid`/`
            -   `.extension` *only present in unusual circumstances; a JSON object with fields "due" and "late"*
            -   file.py
            -   `.excused` *only present in unusual circumstances; it's contents are never read, only its presence*
            -   `.`20171231`-`235959`/` *upload time*
                -   file.py
                -   `.autograde` *inserted by autograder; key correctness needed for late penalty computation*
            -   `.autograde` *inserted by autograder; keys correctness,feedback,missing,details*
            -   `.grade` *the most recent JSON line for this assignment from .gradelog*
            -   `.view` *the ID of the grader currently viewing this for grading, if any*
-   `meta/`
    -   `assignments.json` *all the data the system knows about each assignment (required)*
    -   `roster.json` *all the data the system knows about each user (generated)*
    -   `coursegrade.json` *rules for combining grades into a course grade (optional)*
    -   `course.json` *names and URL-bases to display*
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

> Note: I have not re-tested exclude and include in several versions

## `course.json`

Three required fields:

-   `title`: a string naming the course, displayed on the web pages

-   `url`: an absolute URL of the course as a whole

-   `writeup_prefix`: an absolute URL to which writeup names can be appended to yield valid links


## Rubrics and `.grade`s

Rubrics are specified in JSON objects.
Two kinds of rubrics are currently available.

### Percentages

The default grading system;
it requires a number and a comment for every grade,
allowing just one number and just one comment.

Rubric specification
:   ````
    {"kind":"percentage"}
    ````

Grade specification
:   ````
    {"kind":"percentage"
    ,"ratio":0.85
    ,"comments":"Works, but not well designed, which seems like a B to me"
    }
    ````

### Hybrid

This is useful when correctness can be evaluated by automated testing
and humans used to provide other forms of feedback.

The human component includes a set of checkboxes,
a free-form comment space,
and the option to add a full-score multiplier to handle prohibited behavior such as hard-coding.

The automated component contains both on-time and late scores,
as well as how much to deduct the late score.

Rubric specification
:   ````
    {"kind":"hybrid"
    ,"auto-weight":0.4
    ,"late-penalty":0.5
    ,"auto-late-days":2
    ,"human":[{"name":"good variable names","weight":2}
             ,"proper indentation"
             ,"docstrings present"
             ,{"name":"well-formatted docstrings (will be worth points in later assignments)", "weight":0}
             ,"effort at reasonable design"
             ,"complicated parts (if any) properly commented"
             ]
    }
    ````

Grade specification
:   ````
    {"kind":"hybrid"
    ,"auto":0.7931034482758621
    ,"auto-late":0.9310344827586207
    ,"late-penalty":0.5
    ,"auto-weight":0.4
    ,"human":[{"weight":2,"ratio":0.5,"name":"good variable names"}
             ,{"weight":1,"ratio":1,"name":"proper indentation"}
             ,{"weight":1,"ratio":1,"name":"docstrings present"}
             ,{"weight":0,"ratio":0.5,"name":"well-formatted docstrings (will be worth points in later assignments)"}
             ,{"weight":1,"ratio":0,"name":"effort at reasonable design"}
             ,{"weight":1,"ratio":1,"name":"complicated parts (if any) properly commented"}
             ]
    ,"comments":"In the future, you might find docs.python.org/3/ useful"
    ,".mult":{"kind":"percentage","ratio":0.8,"comments":"professionalism penalty"}
    }
    ````


## `.autograde`

A single JSON object, containing

-   `correctness`, a number between 0 an 1

-   `feedback`, a `pre`formatted string to show while the program is not yet due

-   `missing`, a list of strings, one per missed test case, to show during the makeup stage

-   `details`, a list of objects, one per test case, with at least two keys each:
    
    -   `correct`, a boolean
    -   `weight`, a number
    
    We will (eventually) add display of incorrect details to graders, with all other keys displayed too


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
    Internally, it assumes a POSIX file system on a local drive;
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

    
    
