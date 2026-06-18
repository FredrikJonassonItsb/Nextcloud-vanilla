#!/usr/bin/env bash
set -euo pipefail

# seed.sh — create local test users, point sdkmc at the tunnel, then mirror a
# remote dev1 server's SDK addresses + mailbox data into the local NC.
#
# Host-side contract: bash + ssh + docker compose v2 only. No python3/sed/cut/
# tr/mktemp on host — every data-wrangling step runs at a pipe end (on dev1 over
# ssh, or in the local nextcloud container via dc exec); host is the pipe
# carrier. Bash 3.2 compatible (macOS default).
#
# Runs from host or from inside the IDE-attach container: the docker socket and
# host SSH agent (/ssh-agent) are bound via compose.dev.yml, so `ssh "$SERVER"` and
# the compose-exec calls resolve identically either way.
#
# Prerequisites:
#   - make nc-up                (Nextcloud + Postgres must both be running)
#   - host shell can ssh to SERVER (uses host's own ~/.ssh/config / key, NOT the
#                                tunnel compose service — that's for sdkmc to
#                                reach remote IMAP/SMTP at runtime, not seed)
#
# Usage: make seed SERVER=<server>
#        SERVER can be an IP, hostname, or SSH config name (e.g. dev1.hubs.se)

PLATFORM_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# cd to platform root before any compose call. Without COMPOSE_PROJECT_NAME set,
# compose derives the project name from cwd basename, so a sub-dir invocation
# would target the wrong project. The IDE container exports the name
# (compose.dev.yml) and wins regardless, but host-side still depends on cwd.
cd "$PLATFORM_ROOT"

# HOST_PROJECT_DIR — required by docker/compose.yml's apps bind source. Set by
# make; fallback covers direct invocation. Full rationale in the Makefile.
export HOST_PROJECT_DIR="${HOST_PROJECT_DIR:-$PLATFORM_ROOT}"

SERVER="${SERVER:-}"

if [ -z "$SERVER" ]; then
    echo "ERROR: No server specified." >&2
    echo "Usage: make seed SERVER=<server>" >&2
    exit 1
fi

# NEXTCLOUD_IMAGE_VERSION fallback — compose.yml's nextcloud service pins
# `image: ...:${NEXTCLOUD_IMAGE_VERSION:?...}`, which compose interpolates at
# YAML-parse time on every invocation, including the `exec` calls below. Set by
# make; fallback covers direct invocation. Same pattern as HOST_PROJECT_DIR.
export NEXTCLOUD_IMAGE_VERSION="${NEXTCLOUD_IMAGE_VERSION:-$(cat "$PLATFORM_ROOT/docker/nextcloud/VERSION")}"

# Array, not string — quoted expansion below preserves PLATFORM_ROOT with
# spaces (Mac home dirs can have them). String form word-splits at use site.
DC=(docker compose -f "$PLATFORM_ROOT/docker/compose.yml")

# seed mirrors remote mailbox data INTO the local nextcloud (the `dc exec`
# calls below), so it must be running. Check up front, not at the first exec:
# otherwise seed does the remote SSH fetch first and only dies on a raw compose
# error after wasted work. A real check beats the old static usage blurb that
# said "must be running" even when it was.
if [ -z "$("${DC[@]}" ps -q nextcloud)" ]; then
    echo "ERROR: Nextcloud isn't running locally — seed needs it. Run: make nc-up" >&2
    exit 1
fi

# ssh array (same idiom as DC). Two -o flags so from-IDE invocation behaves like
# from-host, where the IDE container has no ~/.ssh:
#   - StrictHostKeyChecking=accept-new: container has no known_hosts; trust the
#     host key on first connect. No-op from host where the key is already known.
#   - User=ubuntu: container has no ~/.ssh/config, so the operator's `User ubuntu`
#     host mapping doesn't apply. Hardcoded here to match compose.tunnel.yml's
#     `ssh -L` and to keep operator config out of the script's behavior.
SSH=(ssh -o StrictHostKeyChecking=accept-new -o User=ubuntu)

REMOTE_NC_URL=$("${SSH[@]}" "$SERVER" 'sudo docker exec hubs-php php occ config:system:get overwrite.cli.url | tr -d "[:space:]"')
REMOTE_ORG_EXT=$("${SSH[@]}" "$SERVER" 'sudo docker exec hubs-php php occ config:app:get sdkmc organizationExtension')
# Explicit empty-check: the remote `... | tr` runs under no guaranteed pipefail
# (ssh's shell may not be bash), so a failed `php occ` yields empty output. Catch
# it here rather than proceeding with empty URL/org and failing mid-Step 3.
if [ -z "$REMOTE_NC_URL" ] || [ -z "$REMOTE_ORG_EXT" ]; then
    echo "ERROR: Failed to fetch NC URL or org extension from $SERVER." >&2
    echo "       Check ssh access and that hubs-php container is running." >&2
    exit 1
fi
echo "==> Remote server: $SERVER ($REMOTE_NC_URL)"
echo "    Organization: $REMOTE_ORG_EXT"

# --- Step 1: Create local test users ---

echo "==> Creating local test users"
# `user:add` failure is tolerated (most likely already-exists on re-run); the
# following `user:info` is the real existence proof and crashes the script if the
# user is genuinely absent — keeps real-failure-vs-idempotent-re-run visible.
"${DC[@]}" exec -T nextcloud bash -c '
    set -euo pipefail
    add_or_verify() {
        local user="$1" display="$2"
        OC_PASS="$user" php occ user:add "$user" --display-name="$display" --password-from-env \
            || echo "    user:add $user failed (verifying existence…)"
        php occ user:info "$user" >/dev/null \
            || { echo "ERROR: $user does not exist after user:add" >&2; exit 1; }
    }
    add_or_verify autohandlaggare1 "Autohandläggare 1"
    add_or_verify autohandlaggare2 "Autohandläggare 2"
'

# --- Step 2: Configure local sdkmc for tunnel ---
#
# imapHost / smtpHost / smtpInboundHost = literal "tunnel": compose service-name
# DNS resolves it to the tunnel container from inside NC. Use the name, not an IP
# — the container comes and goes with `make tunnel`/`make down`, but the name is
# stable across network recreation (an IP would change and force a re-seed).

echo "==> Configuring local sdkmc for tunnel (service-name DNS: tunnel:10143/10025/10026)"
"${DC[@]}" exec -T -e REMOTE_ORG_EXT="$REMOTE_ORG_EXT" nextcloud bash -c '
    set -euo pipefail
    php occ config:app:set sdkmc imapHost --value="tunnel"
    php occ config:app:set sdkmc imapPort --value="10143"
    php occ config:app:set sdkmc smtpHost --value="tunnel"
    php occ config:app:set sdkmc smtpPort --value="10025"
    php occ config:app:set sdkmc smtpInboundHost --value="tunnel"
    php occ config:app:set sdkmc smtpInboundPort --value="10026"
    php occ config:app:set sdkmc organizationExtension --value="$REMOTE_ORG_EXT"
'

# --- Step 3: Discover SDK addresses, provision missing mailboxes on remote ---
#
# One remote python: fetch address-book JSON, run discovery, then create missing
# accounts via NC's API over remote loopback. The admin password never leaves
# dev1 — host sees only ssh stdin (the script) and stderr (progress).

echo "==> Discovering SDK addresses + provisioning missing mailboxes on $SERVER"
"${SSH[@]}" "$SERVER" "REMOTE_NC_URL='$REMOTE_NC_URL' REMOTE_ORG_EXT='$REMOTE_ORG_EXT' python3 -" <<'PYEOF'
import base64, json, os, subprocess, sys
import urllib.request

def docker_exec_out(args):
    return subprocess.check_output(
        ['sudo', 'docker', 'exec', 'hubs-php'] + args, text=True)

org_ext = os.environ['REMOTE_ORG_EXT']
url = os.environ['REMOTE_NC_URL']
subdomain = url.replace('https://', '').replace('http://', '').split('.')[0]
sys.stderr.write(f'    Subdomain: {subdomain}, Organization: {org_ext}\n')

orgs = json.loads(docker_exec_out(['php', 'occ', 'config:app:get', 'sdkmc', 'addressBookOrganizations']))
addrs = json.loads(docker_exec_out(['php', 'occ', 'config:app:get', 'sdkmc', 'addressBookAddresses']))

# Find root O whose participantIdentifier matches this server's org.
root_org = None
for o in orgs['data']:
    a = o['attributes']
    if a.get('type') == 'O' and a['participantIdentifier'] == org_ext:
        root_org = o
        break
if not root_org:
    sys.exit(f'ERROR: No root org found for {org_ext}')

# Index addresses by parent org id.
by_parent = {}
for a in addrs['data']:
    pid = a.get('relationships', {}).get('parent', {}).get('data', {}).get('id')
    if pid:
        by_parent.setdefault(pid, []).append(a)

# Find the UO under root_org whose first address ends with .<subdomain>:<org_ext>.
pattern = f'.{subdomain}:{org_ext}'
matching_uo = None
for o in orgs['data']:
    a = o['attributes']
    if a.get('type') != 'UO':
        continue
    parent_id = o.get('relationships', {}).get('parent', {}).get('data', {}).get('id')
    if parent_id != root_org['id']:
        continue
    uo_addrs = by_parent.get(o['id'], [])
    if uo_addrs and uo_addrs[0]['attributes']['identifier'].endswith(pattern):
        matching_uo = o
        break
if not matching_uo:
    sys.exit(f'ERROR: No sub-org found for subdomain {subdomain}')

sdk_addresses = [(x['attributes']['identifier'], x['attributes']['name'])
                 for x in by_parent.get(matching_uo['id'], [])]
sys.stderr.write(f'    Found {len(sdk_addresses)} SDK address(es)\n')

# Admin password stays remote — read from hubs-php env, never crosses ssh.
# `printenv` (not a shell ${VAR}) exits 1 if the var is unset, so check_output
# raises instead of silently yielding an empty password that 401s downstream.
admin_pass = docker_exec_out(['printenv', 'ADMIN_PASSWORD']).strip()
auth = base64.b64encode(f'admin:{admin_pass}'.encode()).decode()
hdrs = {'Authorization': f'Basic {auth}', 'OCS-APIRequest': 'true'}

def nc_get(path):
    req = urllib.request.Request(url + path.lstrip('/'), headers=hdrs)
    return json.loads(urllib.request.urlopen(req).read())

def nc_post(path, data):
    h = dict(hdrs)
    h['Content-Type'] = 'application/json'
    req = urllib.request.Request(url + path.lstrip('/'),
                                 data=json.dumps(data).encode(),
                                 headers=h, method='POST')
    urllib.request.urlopen(req).read()

current = nc_get('apps/sdkmc/api/v2/sdkmc/allMailboxes')
# 'sdk' is {} (dict) with entries, [] when empty (PHP assoc→JSON); guard .keys().
_sdk = current.get('accounts', {}).get('sdk', {})
existing_sdk = set(_sdk.keys() if isinstance(_sdk, dict) else _sdk)

created = 0
for addr_id, addr_name in sdk_addresses:
    local_part = addr_id[len('sdk:'):] if addr_id.startswith('sdk:') else addr_id
    if local_part.endswith(pattern):
        local_part = local_part[:-len(pattern)]
    alias = ''.join(c for c in local_part if c.isalnum())
    email = f'{alias}@sdk'
    if email in existing_sdk:
        sys.stderr.write(f'    {email} already exists on remote\n')
        continue
    sys.stderr.write(f'    Creating {email} on remote (SDK address: {addr_id})\n')
    nc_post('apps/sdkmc/api/v2/admin/addAccount', {
        'messageType': 'sdk',
        'alias': alias,
        'sdkaddress': addr_id,
        'name': addr_name,
        'description': addr_name,
        'number': '',
        'canBeRepliedTo': True,
        'canMessageBeSentTo': True,
    })
    created += 1
sys.stderr.write(f'    {created} new mailbox(es) created\n')
PYEOF

# --- Step 4: Copy mailbox data from remote to local ---

echo "==> Copying mailbox data from remote to local"

# Wipe local mailbox tables. sdkmc's migrations create them on app:enable, which
# the nextcloud entrypoint runs on every container start, so the `make nc-up`
# prereq guarantees they exist here. If they don't (sdkmc disabled, migrations
# not run), ON_ERROR_STOP=1 surfaces the missing table rather than swallowing it.
"${DC[@]}" exec -T postgres psql -U nextcloud -d nextcloud -q -v ON_ERROR_STOP=1 -c \
    "DELETE FROM oc_sdkmc_account_itsl_mailbox; DELETE FROM oc_sdkmc_itsl_mailbox;"

# Remote python emits INSERTs from a psql SELECT; host pipes; local psql applies.
"${SSH[@]}" "$SERVER" 'python3 -' <<'PYEOF' | "${DC[@]}" exec -T postgres psql -U nextcloud -d nextcloud -q -v ON_ERROR_STOP=1
import json, subprocess, sys

# JSON, not `-A -F|` text: a mailbox name/description/password can hold a `|`
# (over-splits -> wrong columns) or a newline (splits the row -> silently
# dropped). row_to_json round-trips arbitrary text; COALESCE(...,'[]') handles
# the empty table.
raw = subprocess.check_output([
    'sudo', 'docker', 'exec', 'hubs-postgres',
    'psql', '-U', 'hubs', '-d', 'hubs', '-t', '-A', '-c',
    "SELECT COALESCE(json_agg(row_to_json(t)), '[]') FROM ("
    "SELECT id, name, description, alias, email, password, message_type, "
    "can_be_replied_to, can_message_be_sent_to, sdk_address, number, "
    "notification_email FROM oc_sdkmc_itsl_mailbox ORDER BY id) t;"
], text=True)
rows = json.loads(raw)

def q(s):
    # Always-quoted string literal; None/empty -> ''.
    return "'" + (s or "").replace("'", "''") + "'"

def nullable(s):
    # NULL for None or empty, else a quoted literal.
    return "'" + s.replace("'", "''") + "'" if s else "NULL"

count = 0
print("BEGIN;")
for r in rows:
    print(
        "INSERT INTO oc_sdkmc_itsl_mailbox "
        "(id, name, description, alias, email, password, message_type, "
        "can_be_replied_to, can_message_be_sent_to, sdk_address, number, notification_email) "
        "VALUES ("
        f"{int(r['id'])}, "
        f"{q(r['name'])}, {q(r['description'])}, {q(r['alias'])}, "
        f"{q(r['email'])}, {q(r['password'])}, {q(r['message_type'])}, "
        # can_be_replied_to / can_message_be_sent_to are smallint (0/1), NOT
        # boolean — emit the integer; the column rejects a true/false literal.
        f"{int(r['can_be_replied_to'])}, "
        f"{int(r['can_message_be_sent_to'])}, "
        f"{nullable(r['sdk_address'])}, {nullable(r['number'])}, {nullable(r['notification_email'])}"
        ") ON CONFLICT DO NOTHING;"
    )
    count += 1
print("SELECT setval('oc_sdkmc_itsl_mailbox_id_seq', "
      "GREATEST((SELECT MAX(id) FROM oc_sdkmc_itsl_mailbox), 1));")
print("COMMIT;")
sys.stderr.write(f'    {count} mailbox row(s) emitted\n')
PYEOF

# --- Step 5: Assign mailboxes to test users ---

echo "==> Assigning mailboxes to test users"
"${DC[@]}" exec -T nextcloud python3 - <<'PYEOF'
import base64, json, sys
import urllib.request

# admin/admin on local — Step 1 ensures both auto-handläggare exist.
auth = base64.b64encode(b'admin:admin').decode()
hdrs = {'Authorization': f'Basic {auth}', 'OCS-APIRequest': 'true'}

req = urllib.request.Request(
    'http://localhost/apps/sdkmc/api/v2/sdkmc/allMailboxes', headers=hdrs)
data = json.loads(urllib.request.urlopen(req).read())
accounts = data.get('accounts', {})

users = ['admin', 'autohandlaggare1', 'autohandlaggare2']

# addUserToMailBox is idempotent server-side — ItslAccountService::addUserToMailBox
# (apps/sdkmc/lib/Service/ItslAccountService.php) early-returns HTTP 200 when the
# link already exists, so re-runs look like first runs. Any non-200 is a real
# failure; let urllib raise.
#
# Round-robin: mailbox i of each type goes to users[i % len(users)]. Hands out
# every mailbox and splits each type roughly evenly across the test users. A type
# with fewer mailboxes than users just leaves the later users without one for that
# type — fine, nothing requires every user to hold a mailbox of every type.
for msg_type, mailboxes in accounts.items():
    # An empty mailbox type serializes as [] not {} (PHP assoc→JSON); guard .keys().
    emails = mailboxes.keys() if isinstance(mailboxes, dict) else mailboxes
    for i, email in enumerate(emails):
        user = users[i % len(users)]
        sys.stderr.write(f'    Assigning {email} to {user}\n')
        h = dict(hdrs)
        h['Content-Type'] = 'application/json'
        req = urllib.request.Request(
            'http://localhost/apps/sdkmc/api/v2/admin/addUserToMailBox',
            data=json.dumps({'userId': user, 'messageType': msg_type, 'email': email}).encode(),
            headers=h, method='POST'
        )
        urllib.request.urlopen(req).read()
PYEOF

echo ""
echo "==> Seed complete."
echo "    Users: admin/admin, autohandlaggare1/autohandlaggare1, autohandlaggare2/autohandlaggare2"
echo "    Mailboxes mirrored from $SERVER"
echo "    Assignments: each type's mailboxes handed out round-robin across admin / autohandlaggare1 / autohandlaggare2"
echo ""
echo "    Mail accounts will be created automatically by background cron (within 1 minute)."
echo "    To check: docker compose exec nextcloud php occ background-job:list | grep Consolidate"
