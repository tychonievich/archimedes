#!/bin/bash

# This script was written to allow re-queuing all submissions of a given slug (as, e.g., after an autograder update)

base="$(dirname "$(dirname "$(readlink -f "$0")")")"

if [ "$#" -eq 0 ]
then
    echo "USAGE: bash $0 PA08"
    echo "Used to manually force autograder to re-run for all of a given assignment"
    exit 1
fi

while [ "$#" -gt 0 ]
do
    if [ -d "$base/uploads/$1" ]
    then
        dir="$base/uploads/$1"
        for f in "$dir"/*/
        do
            final=$(ls -d "${f}".2* | sort -V | tail -1)
            date=$(basename $final)
            user=$(basename $(dirname $final))
            task=$(basename $(dirname $(dirname $final)))
            echo -n $date > $base/meta/queued/$task-$user
            echo queued $task-$user for automated feedback
        done
    else
        echo "'$1' is not a valid assignment slug"
    fi
    shift
done
