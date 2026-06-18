#!/usr/bin/env bash
set -euo pipefail

# new-app.sh — bootstrap a new quilt app under apps/<NAME>, NAME derived
# from upstream's appinfo/info.xml <id>.
#
# Runs inside dev-builder with APPS_RW=1: /apps is the rw bind of the
# platform's apps/ tree, /platform is the ro platform tree.
#
# Output contract: progress to stderr, final stdout line is the derived
# NAME; the Makefile recipe captures it via $(…) to point build.sh +
# discover-apps at the right app dir.
#
# Inputs (from caller env):
#   UPSTREAM  — upstream repo URL
#   VERSION   — upstream version tag to pin

: "${UPSTREAM:?must be set: make new-app UPSTREAM=<git-url> VERSION=<tag>}"
: "${VERSION:?must be set: make new-app UPSTREAM=<git-url> VERSION=<tag>}"

# Derive NAME from upstream's appinfo/info.xml <id>: Nextcloud loads apps
# by <id>, not directory name. Deriving rather than asking the operator
# prevents scaffolding a name NC can't enable — the apps-extra symlink
# lands but `occ app:enable` fails "App not found" / "not compatible".
TMP_PEEK="$(mktemp -d /tmp/new-app-peek.XXXXXX)"
trap 'rm -rf "$TMP_PEEK"' EXIT
echo "==> Peeking at $UPSTREAM @ $VERSION for app id" >&2
git clone --depth=1 --branch "$VERSION" --quiet "$UPSTREAM" "$TMP_PEEK"
INFO_XML="$TMP_PEEK/appinfo/info.xml"
if [ ! -f "$INFO_XML" ]; then
    echo "ERROR: $UPSTREAM @ $VERSION has no appinfo/info.xml — not a Nextcloud app skeleton?" >&2
    exit 1
fi
NAME="$(python3 -c '
import sys, xml.etree.ElementTree as ET
print((ET.parse(sys.argv[1]).getroot().findtext("id") or "").strip())
' "$INFO_XML")"
if [ -z "$NAME" ]; then
    echo "ERROR: $UPSTREAM @ $VERSION has appinfo/info.xml but no <id> element" >&2
    exit 1
fi
echo "==> Upstream declares <id>=$NAME" >&2

APP_DIR="/apps/$NAME"

if [ -d "$APP_DIR" ]; then
    echo "ERROR: apps/$NAME already exists" >&2
    exit 1
fi

echo "==> Creating apps/$NAME (upstream $VERSION)" >&2
mkdir -p "$APP_DIR"
git -C "$APP_DIR" init -q -b main

# Include-shim Makefile — locates PLATFORM_ROOT relative to itself and
# includes the platform Makefile, so platform targets (assemble / quilt
# / build) run from inside the app dir.
cat > "$APP_DIR/Makefile" <<'APP_MAKEFILE'
_FROM_APP := 1
ifndef PLATFORM_ROOT
PLATFORM_ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))/../..
endif
APP_ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
include $(PLATFORM_ROOT)/Makefile
APP_MAKEFILE

# Minimal app .gitlab-ci.yml pinned to a platform ref. Bump the ref on
# platform release; the app inherits the CI templates.
cat > "$APP_DIR/.gitlab-ci.yml" <<'GITLAB_CI'
include:
  - project: 'itsl/hubs-apps'
    ref: 'v1.0.0'
    file:
      - 'ci/base.yml'
      - 'ci/validate.yml'
      - 'ci/build.yml'
      - 'ci/lint.yml'
      - 'ci/security.yml'
      - 'ci/upload.yml'
      - 'ci/notify.yml'

variables:
  PLATFORM_REF: 'v1.0.0'
GITLAB_CI

# Upstream submodule at the pinned version. Second clone by design — the
# peek clone was a throwaway in /tmp; this is what wires upstream/ in.
git -C "$APP_DIR" submodule add "$UPSTREAM" upstream >&2
git -C "$APP_DIR/upstream" checkout "$VERSION" >&2

# Empty quilt series — patches added later via `make quilt`.
mkdir -p "$APP_DIR/patches"
touch "$APP_DIR/patches/series"

# Manifest copyfiles per stack — these manifests must be COPIED, not
# symlinked, into .build/: composer / npm realpath()-resolve their config
# and would follow a symlink back to overlay/ and rewrite pinned
# lockfiles there. Seed only the stack(s) upstream ships (PHP / JS).
mkdir -p "$APP_DIR/overlay-copyfiles.d"
if [ -f "$APP_DIR/upstream/composer.json" ]; then
    printf 'composer.json\ncomposer.lock\n' > "$APP_DIR/overlay-copyfiles.d/composer.txt"
fi
if [ -f "$APP_DIR/upstream/package.json" ]; then
    printf 'package.json\npackage-lock.json\n' > "$APP_DIR/overlay-copyfiles.d/npm.txt"
fi

# App-dir .gitignore for the platform-generated artifacts, so the initial
# commit and later `git status` stay clean: .build/ and .dist/ are
# platform-generated trees, *.tar.gz is `make package` output.
cat > "$APP_DIR/.gitignore" <<'GITIGNORE'
.build/
.dist/
*.tar.gz
GITIGNORE

echo "==> Generating dependencies" >&2
APP_ROOT="$APP_DIR" bash /platform/scripts/deps.sh regen >&2

echo "==> Assembling .build/ (for l10n init)" >&2
# MODE=force is first-time-bootstrap mode: it creates .build/.git, which
# a brand-new app lacks — plain assemble would fail its preflight.
APP_ROOT="$APP_DIR" MODE=force bash /platform/scripts/assemble.sh >&2

echo "==> Bootstrapping translations" >&2
( cd "$APP_DIR" && /platform/l10n-tools/l10n init --import-from "$APP_DIR/.build/l10n" >&2 )

# .build/ is left in place (gitignored, so the commit skips it): the
# recipe's follow-up `make build` compiles JS into it, sparing a re-assemble.

echo "==> Committing" >&2
git -C "$APP_DIR" add -A
# Hard-code committer identity: the dev-builder container has no
# gitconfig, so `git commit` would fail "tell me who you are." Platform
# identity, not the operator's — this is the skeleton bootstrap, not
# their work; later commits pick up the operator's config naturally.
git -C "$APP_DIR" \
	-c user.name="hubs-apps platform" \
	-c user.email="platform@itsl.se" \
	commit -q -m "init: bootstrap $NAME app (upstream $VERSION)"

echo "==> Done. apps/$NAME is ready on branch main." >&2

# Final stdout line: the derived NAME (see output contract above).
echo "$NAME"
