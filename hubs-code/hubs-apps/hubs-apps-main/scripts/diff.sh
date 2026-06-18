#!/usr/bin/env bash
set -euo pipefail

# diff.sh — show unsaved changes in .build/
#
# The baseline tag (created by assemble.sh) is upstream + patches + overlay;
# any diff against it is a change not yet saved to a patch. Runs in-container
# via dc-run.sh, which sets APP_ROOT to the ephemeral /app or pool
# /platform/apps/<APP_NAME>; this script works either way. cwd fallback
# covers direct invocation.

APP_ROOT="${APP_ROOT:-$(pwd)}"
BUILD_DIR="$APP_ROOT/.build"
PATCHES="$APP_ROOT/patches"

if [ ! -d "$BUILD_DIR" ]; then
    echo "No .build/ directory. Nothing to diff." >&2
    exit 0
fi

if [ ! -d "$BUILD_DIR/.git" ]; then
    echo ".build/ has no git baseline. Run 'make assemble' first." >&2
    exit 1
fi

# Acquire .build/.lock: diff reads .build/'s git refs and working tree;
# mutation mid-scan corrupts output. Release is implicit at process exit.
BUILD_LOCK="$BUILD_DIR/.lock"
exec 200>"$BUILD_LOCK"
if ! flock -n 200; then
    echo "ERROR: another .build/ operation is already running" >&2
    echo "       (lock held on .build/.lock; make status shows what's holding it)" >&2
    exit 1
fi

export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="*"

# Baseline tag must exist (write-baseline.sh creates it on every assemble).
# Check up front: without it `git diff baseline` below crashes on an unknown
# ref instead of giving a clear diagnostic.
if ! git -C "$BUILD_DIR" rev-parse --verify --quiet refs/tags/baseline >/dev/null; then
    echo ".build/ has no baseline tag. Run 'make assemble' first." >&2
    exit 1
fi

# series <-> patches consistency: every non-comment series entry needs a
# patch file on disk. A missing one means a patch was deleted but its series
# entry stayed — a real inconsistency (post-assemble it can't happen; quilt
# push -a would have crashed). Check here at top level, not in the piped
# annotation subshell below, so the exit reliably aborts the script rather
# than silently dropping the patch from the annotation. make quilt raises on
# the same condition in _get_patch_file_map.
if [ -f "$PATCHES/series" ]; then
    while read -r _series_patch; do
        [ -z "$_series_patch" ] && continue
        case "$_series_patch" in \#*) continue ;; esac
        [ -f "$PATCHES/$_series_patch" ] || {
            echo "ERROR: patches/series references '$_series_patch' but $PATCHES/$_series_patch doesn't exist — series and patches/ are out of sync." >&2
            exit 1
        }
    done < "$PATCHES/series"
fi

if [ -t 1 ]; then
    BOLD="\033[1m"
    CYAN="\033[36m"
    GREEN="\033[1;32m"
    DIM="\033[2m"
    RESET="\033[0m"
    COLOR_FLAG="--color=always"
else
    BOLD="" CYAN="" GREEN="" DIM="" RESET=""
    COLOR_FLAG=""
fi

cd "$BUILD_DIR"

# Prereqs verified above, so set -e propagates any real git failure here.
# The grep -c below keeps `|| true` only because grep exits 1 on no-match
# (a legitimate empty-diff state), not to hide errors.
diff_output=$(git diff $COLOR_FLAG baseline)
diff_names=$(git diff --name-only baseline)
untracked=$(git ls-files --others --exclude-standard)

if [ -z "$diff_output" ] && [ -z "$untracked" ]; then
    echo -e "${GREEN}No unsaved changes.${RESET}"
    exit 0
fi

changed_files=$(echo "$diff_names" | grep -c . || true)
if [ -n "$untracked" ]; then
    untracked_count=$(echo "$untracked" | grep -c . || true)
else
    untracked_count=0
fi
total=$((changed_files + untracked_count))
# No total==0 recheck needed: the early-exit above handled both-empty, and a
# non-empty diff implies a non-empty --name-only list, so total >= 1 here.

echo -e "${BOLD}${total} unsaved change(s)${RESET}"
echo ""

if [ "$changed_files" -gt 0 ]; then
    echo -e "${DIM}── Modified files ──${RESET}"
    echo ""
    # Iterate the names we already captured rather than re-invoking git diff.
    echo "$diff_names" | while read -r f; do
        [ -z "$f" ] && continue
        patches=""
        if [ -f "$PATCHES/series" ]; then
            while read -r patch; do
                [ -z "$patch" ] && continue
                [[ "$patch" = \#* ]] && continue
                # Existence guaranteed by the top-level series<->patches
                # validation above. Does this patch touch exactly "$f"?
                # Parse its +++/--- header lines, strip quilt's a/ b/
                # .build/ .build.orig/ prefix and any trailing tab-timestamp,
                # then compare the path literally — same shape as
                # quilt_extract._get_patch_file_map. The earlier
                # `grep "^+++ .*/${f}\b"` interpolated $f into a regex:
                # metachars in the filename (. + [ *) matched loosely (and a
                # literal [ made grep error), and the unanchored .*/ prefix
                # mis-attributed any path that was a deeper-nested suffix of
                # another (lib/Foo.php picked up a patch touching
                # tests/lib/Foo.php). Scanning --- as well as +++ attributes
                # delete-only patches — their path is only on the --- side
                # (+++ is /dev/null, which fails every prefix and is skipped).
                patch_file="$PATCHES/$patch"
                if awk -v want="$f" '
                    /^(\+\+\+|---) / {
                        path = $0
                        sub(/^(\+\+\+|---) /, "", path)
                        sub(/\t.*$/, "", path)
                        sub(/^[ab]\//, "", path)
                        sub(/^\.build(\.orig)?\//, "", path)
                        if (path == want) { found = 1; exit }
                    }
                    END { exit(found ? 0 : 1) }
                ' "$patch_file"; then
                    pname="${patch%.patch}"
                    patches="${patches:+$patches, }$pname"
                fi
            done < "$PATCHES/series"
        fi
        if [ -n "$patches" ]; then
            echo -e "  ${BOLD}${f}${RESET}  ${DIM}${CYAN}${patches}${RESET}"
        else
            echo -e "  ${BOLD}${f}${RESET}"
        fi
    done
    echo ""
    echo -e "${DIM}── Diff ──${RESET}"
    echo ""
    echo "$diff_output"
    echo ""
fi

if [ -n "$untracked" ]; then
    echo -e "${DIM}── New files ──${RESET}"
    echo ""
    echo "$untracked" | while read -r f; do
        [ -z "$f" ] && continue
        echo -e "  ${BOLD}${f}${RESET}  ${DIM}new${RESET}"
    done
    echo ""
fi
