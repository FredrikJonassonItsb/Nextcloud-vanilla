#!/bin/bash
# brain-api.sh — recall/writeback against the agent's OWN brain (CONTRACTS §5, §7)
#
# Usage:
#   brain-api.sh search '<query>' [limit]        # recall before work (default limit 5)
#   brain-api.sh create '<content>' [--metadata '<json>'] [--source <src>]
#                                                # writeback after work (source: runner)
#
# search  → MCP tools/call `search_thoughts` on POST ${BRAIN_URL}/mcp
#           (openbrain-svc streamable HTTP; answers plain JSON or SSE — both parsed).
# create  → POST ${BRAIN_URL}/ingest {content, source, author, metadata}
#           The brain-side PII firewall (pii-patterns.json) answers 422 on a hit;
#           that refusal is surfaced verbatim — never retried, never worked around.
#
# Env (exported by run-agent.sh):
#   BRAIN_URL   e.g. http://brain-reb:8000   (compose-internal service)
#   BRAIN_KEY   bearer key for this brain
#   AGENT_CODE  used as author on writebacks

set -euo pipefail

die() { echo "brain-api.sh: $*" >&2; exit 2; }

: "${BRAIN_URL:?brain-api.sh: BRAIN_URL is not set}"
: "${BRAIN_KEY:?brain-api.sh: BRAIN_KEY is not set}"
: "${AGENT_CODE:?brain-api.sh: AGENT_CODE is not set}"

BASE="${BRAIN_URL%/}"

cmd="${1:-}"
shift || true

case "$cmd" in
  search)
    query="${1:?brain-api.sh search: missing '<query>'}"
    limit="${2:-5}"
    [[ "$limit" =~ ^[0-9]+$ ]] || die "search: limit must be numeric"

    payload=$(jq -nc \
      --arg q "$query" \
      --argjson n "$limit" \
      '{jsonrpc: "2.0", id: 1, method: "tools/call",
        params: {name: "search_thoughts", arguments: {query: $q, limit: $n}}}')

    resp=$(curl -sS -X POST "${BASE}/mcp" \
      -H "Authorization: Bearer ${BRAIN_KEY}" \
      -H "Content-Type: application/json" \
      -H "Accept: application/json, text/event-stream" \
      --data "$payload") || die "search: curl failed against ${BASE}/mcp"

    # Streamable HTTP may answer as SSE: take the last parseable `data:` line.
    if grep -q '^data:' <<<"$resp"; then
      json=$(grep '^data:' <<<"$resp" | sed 's/^data:[[:space:]]*//' | grep -v '^\[DONE\]$' | tail -n 1)
    else
      json="$resp"
    fi

    jq -e . >/dev/null 2>&1 <<<"$json" || die "search: brain answered non-JSON: ${resp:0:200}"

    if jq -e '.error' >/dev/null 2>&1 <<<"$json"; then
      jq -c '{error: .error}' <<<"$json"
      exit 1
    fi
    # Human-readable result blocks from the search_thoughts tool.
    text=$(jq -r '[.result.content[]? | select(.type == "text") | .text] | join("\n")' <<<"$json")
    if [[ -z "$text" ]]; then
      echo "No results."
    else
      printf '%s\n' "$text"
    fi
    ;;

  create)
    content="${1:?brain-api.sh create: missing '<content>'}"
    shift
    [[ -n "${content// /}" ]] || die "create: content must not be empty"

    source_val="runner"
    metadata='{}'
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --metadata)
          metadata="${2:?create: --metadata needs a JSON value}"
          jq -e 'type == "object"' >/dev/null 2>&1 <<<"$metadata" \
            || die "create: --metadata must be a JSON object"
          shift 2 ;;
        --source)
          source_val="${2:?create: --source needs a value}"
          shift 2 ;;
        *) die "create: unknown option '$1'" ;;
      esac
    done

    body=$(jq -nc \
      --arg content "$content" \
      --arg source "$source_val" \
      --arg author "$AGENT_CODE" \
      --argjson metadata "$metadata" \
      '{content: $content, source: $source, author: $author,
        metadata: ($metadata + {agent: $author})}')

    resp=$(curl -sS -w $'\n%{http_code}' -X POST "${BASE}/ingest" \
      -H "Authorization: Bearer ${BRAIN_KEY}" \
      -H "Content-Type: application/json" \
      --data "$body") || die "create: curl failed against ${BASE}/ingest"

    code="${resp##*$'\n'}"
    out="${resp%$'\n'*}"
    if jq -e . >/dev/null 2>&1 <<<"$out"; then
      jq -c --argjson s "$code" '{http_status: $s, data: .}' <<<"$out"
    else
      jq -nc --argjson s "$code" --arg raw "$out" '{http_status: $s, data: {raw: $raw}}'
    fi
    [[ "$code" =~ ^2[0-9][0-9]$ ]]
    ;;

  *)
    die "unknown subcommand '${cmd}' — use: search | create"
    ;;
esac
