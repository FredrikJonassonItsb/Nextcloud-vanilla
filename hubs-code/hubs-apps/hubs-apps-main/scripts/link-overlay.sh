#!/usr/bin/env bash
set -euo pipefail
# scripts/link-overlay.sh — overlay-symlink lifecycle for .build/.
# Owns both halves so callers don't reimplement (and drift) them.
#
#   strip_overlay_symlinks BUILD_DIR
#       Remove .build/ symlinks resolving into overlay/. Run before any
#       quilt pop/push: quilt reverse-applies by writing through the
#       file, so an overlay-shadowed patched file lands its reverse-apply
#       in overlay/<file>, corrupting the source-of-truth.
#
#   link_overlay OVERLAY_DIR BUILD_DIR
#       Apply overlay/ into .build/: recurse upstream dirs, dir-symlink
#       new ITSL dirs, copy files listed in overlay-copyfiles.d/, symlink
#       the rest. Symlinks are RELATIVE (ln -srf) so containers need no
#       duplicate bind mount of overlay/ at the host-absolute path.
#       PRECONDITION: .build/ holds no overlay symlinks (caller ran strip
#       or wiped .build/). Apply-only — does not remove a .build/ symlink
#       whose overlay/ source was deleted (strip's job, run first).
#
# Reads OVERLAY (both ops) and COPYFILES_D (link only) from env.
#
# Usage: source from assemble.sh, or invoke with strip|link subcommand.

: "${OVERLAY:?OVERLAY must be set}"

# --- strip: remove .build/ overlay symlinks (quilt pop/push safety) ---
strip_overlay_symlinks() {
    local build_dir="$1"
    # Prune big dep dirs — none hold overlay symlinks; walking is waste.
    find "$build_dir" -path '*/node_modules' -prune -o \
                      -path '*/vendor' -prune -o \
                      -path '*/vendor-bin' -prune -o \
                      -path '*/.git' -prune -o \
                      -path '*/.pc' -prune -o \
                      -type l -print0 \
        | while IFS= read -r -d '' link; do
            # readlink -m not -f: -m canonicalizes without requiring path
            # components to exist. Cross-branch reconcile legitimately has
            # overlay symlinks pointing at paths whose parent dirs don't
            # exist on this branch; -f exits rc=1 empty there, crashing via
            # set -e through `var=$(cmd)`. We only test membership under
            # $OVERLAY/, not existence — -m matches the question.
            local resolved
            resolved="$(readlink -m "$link")"
            case "$resolved" in
                "$OVERLAY"/*) rm "$link" ;;
            esac
          done
}

# --- link: apply overlay/ into .build/ ---
link_overlay() {
    : "${COPYFILES_D:?COPYFILES_D must be set (the app overlay-copyfiles.d/ dir, even if absent)}"
    local overlay_dir="$1" build_dir="$2" f line

    # Copyfiles set: paths in overlay-copyfiles.d/ get COPIED not
    # symlinked — e.g. composer rewrites composer.json in place. Read
    # once into an assoc array so the membership test is a subprocess-free
    # shell lookup. COPYFILES_D may legitimately not exist (app with no
    # copyfiles); [ -d ] then leaves the set empty and all is symlinked.
    local -A copyfiles=()
    if [ -d "$COPYFILES_D" ]; then
        for f in "$COPYFILES_D"/*; do
            [ -f "$f" ] || continue
            # `|| [ -n "$line" ]`: read returns rc=1 on a final line with
            # no trailing newline but still sets $line. Explicit `if` (not
            # `[ … ] && …`) keeps the loop body exit status 0 on a blank
            # line, so a trailing blank line can't trip set -e.
            while IFS= read -r line || [ -n "$line" ]; do
                if [ -n "$line" ]; then
                    copyfiles["$line"]=1
                fi
            done < "$f"
        done
    fi

    _link_overlay_recurse "$overlay_dir" "$build_dir"
}

# Recursive worker for link_overlay. Reads `copyfiles` and `$OVERLAY`
# from the enclosing scope via bash dynamic scope — call only via
# link_overlay.
_link_overlay_recurse() {
    local ovl_dir="$1" bld_dir="$2"
    local entry name rel
    for entry in "$ovl_dir"/* "$ovl_dir"/.*; do
        [ -e "$entry" ] || continue
        name="${entry##*/}"
        case "$name" in .|..|.gitkeep) continue ;; esac

        # $OVERLAY quoted so any glob metacharacter in the path is a
        # literal prefix, not a pattern.
        rel="${entry#"$OVERLAY"/}"

        if [ -d "$entry" ]; then
            if [ -d "$bld_dir/$name" ]; then
                # Real upstream directory — recurse into it.
                _link_overlay_recurse "$entry" "$bld_dir/$name"
            else
                # New ITSL directory — dir symlink. ln -srf's -f clears a
                # colliding upstream regular file; per precondition no
                # overlay symlink-to-directory exists for ln to deref into.
                ln -srf "$entry" "$bld_dir/$name"
            fi
        else
            # rm -f first: .build/<name> may be an upstream file OR an
            # upstream symlink. cp follows a symlink and writes through it,
            # corrupting its target; removing first lands cp/ln on a clean
            # path. (Clears upstream's own symlinks — overlay ones are
            # already gone per the precondition.)
            rm -f "$bld_dir/$name"
            if [ -n "${copyfiles["$rel"]+x}" ]; then
                cp "$entry" "$bld_dir/$name"
            else
                ln -srf "$entry" "$bld_dir/$name"
            fi
        fi
    done
}

# Direct invocation (not sourced): dispatch on subcommand.
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    case "${1:-}" in
        strip) strip_overlay_symlinks "${2:?usage: link-overlay.sh strip BUILD_DIR}" ;;
        link)  link_overlay "$OVERLAY" "${2:?usage: link-overlay.sh link BUILD_DIR}" ;;
        *)     echo "usage: link-overlay.sh {strip|link} BUILD_DIR" >&2; exit 2 ;;
    esac
fi
