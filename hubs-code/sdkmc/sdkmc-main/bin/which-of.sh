#!/bin/bash

find_binary() {
    local binary=""
    local path=""
    local result=0
    for binary in "$@"; do
        path="$(which "$binary")"
        if [ -n "$path" ]; then
            break
        fi
    done
    if [ -n "$path" ]; then
        echo "$path"
    else
        result=1
    fi
    return $result
}

find_binary "$@"
exit $?
