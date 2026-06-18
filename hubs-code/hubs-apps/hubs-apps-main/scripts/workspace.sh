#!/usr/bin/env bash
#
# workspace.sh — emit hubs-apps.code-workspace JSON to stdout.
#
# Settings + extensions inline in the JSON rather than a separate
# .vscode/extensions.json: workspace root /platform/apps is in no git
# repo (apps/ is platform-ignored; each apps/<X> is upstream-managed).
#
# Runs in-container via dc-run.sh: the host floor lacks jq/python.
# /platform is ro inside the dev-builder (supply-chain isolation), so
# stdout + host redirect is the only write path; the Makefile captures
# stdout to a temp file and renames atomically.

set -euo pipefail

# Pre-validate: app dir names must not contain JSON-meta chars
# (quote/backslash) — would emit malformed JSON and break the
# workspace silently. Crash loud instead.
shopt -s nullglob
for app_dir in /platform/apps/*/; do
    app="$(basename "$app_dir")"
    case "$app" in
        *['"\\']*)
            echo "ERROR: app name '$app' contains illegal chars (quote/backslash); workspace JSON would be malformed." >&2
            exit 1
            ;;
    esac
done

# JSON folder helper — no trailing comma: prepend the separator on
# subsequent entries rather than append. Same idiom as compose.sh.
first=true
add_folder() {
    if $first; then
        first=false
    else
        printf ',\n'
    fi
    printf '        { "name": "%s", "path": "%s" }' "$1" "$2"
}

cat <<'HEADER'
{
    "folders": [
HEADER

found_any=0
for app_dir in /platform/apps/*/; do
    found_any=1
    app="$(basename "$app_dir")"
    if [ -d "$app_dir/upstream" ]; then
        # Quilt app — two roots. assembled = .build/ (edit target);
        # repo = per-app git repo (patches/, overlay/, upstream/) where
        # `make quilt` routes .build/ edits back as patches. Dot
        # separator groups both views adjacent in the Explorer.
        add_folder "$app.assembled" "apps/$app/.build"
        add_folder "$app.repo"      "apps/$app"
    else
        # Standalone app, or apps/server (NC core, bind-mounted live) —
        # single root at the app dir, edited + committed directly.
        add_folder "$app" "apps/$app"
    fi
done

if [ "$found_any" = "0" ]; then
    echo "ERROR: no apps found under /platform/apps/ — did 'make setup' run?" >&2
    exit 1
fi

# chat.disableAIFeatures: hides bundled Copilot Chat (we use Claude
# Code). Safe to set: Claude Code has its own activity-bar entry, not
# the core chat panel, so the kill switch doesn't disable it.
# secondarySideBar.defaultVisibility hidden: Copilot Chat was its only
# inhabitant, so it would otherwise sit empty. Toggle back: Ctrl+Alt+B.
cat <<'FOOTER'

    ],
    "settings": {
        "chat.disableAIFeatures": true,
        "workbench.secondarySideBar.defaultVisibility": "hidden"
    },
    "extensions": {
        "recommendations": [
            "ms-vscode-remote.remote-containers",
            "anthropic.claude-code",
            "bmewburn.vscode-intelephense-client",
            "Vue.volar",
            "dbaeumer.vscode-eslint",
            "esbenp.prettier-vscode",
            "EditorConfig.EditorConfig"
        ]
    }
}
FOOTER
