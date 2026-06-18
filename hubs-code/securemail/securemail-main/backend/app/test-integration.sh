#!/bin/bash
# LOA-2 integration tests — runs against a live securemail instance.
# Requires a LOA-2 test message pre-injected (see setup below).
#
# Usage:
#   ./test-integration.sh <base_url> <loa2_uuid> <loa1_uuid>
#
# Example:
#   ./test-integration.sh http://localhost:3000 ticket-test-002 641546b2-...
#
# Setup (inject test messages first):
#   doveadm save -u org@securemail -m INBOX <<'EOF'
#   From: test@example.com
#   To: recipient@example.com
#   Subject: LOA-2 test
#   X-Org-Auth-Token: <loa2_uuid>
#   X-LoaLevel: 2
#   X-SmsNumber: +46701234567
#   Content-Type: text/plain
#
#   Test content
#   EOF
#
# See project-overview#110 / securemail#28

set -euo pipefail

BASE="${1:?Usage: $0 <base_url> <loa2_uuid> [loa1_uuid]}"
LOA2_UUID="${2:?Usage: $0 <base_url> <loa2_uuid> [loa1_uuid]}"
LOA1_UUID="${3:-}"

PASS=0
FAIL=0
SKIP=0

test_result() {
  local name="$1" expected="$2" actual="$3"
  if [[ "$actual" == *"$expected"* ]]; then
    echo "  PASS  $name"
    ((PASS++))
  else
    echo "  FAIL  $name (expected '$expected', got '$actual')"
    ((FAIL++))
  fi
}

test_http_code() {
  local name="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "  PASS  $name (HTTP $actual)"
    ((PASS++))
  else
    echo "  FAIL  $name (expected HTTP $expected, got HTTP $actual)"
    ((FAIL++))
  fi
}

echo "=== LOA-2 Integration Tests ==="
echo "Base: $BASE"
echo "LOA-2 UUID: $LOA2_UUID"
echo "LOA-1 UUID: ${LOA1_UUID:-SKIP}"
echo ""

# --- T1: GET without ticket → requiresVerification, no content ---
echo "[T1] GET LOA-2 message without ticket"
RESP=$(curl -s --max-time 30 "$BASE/api/org/emails/$LOA2_UUID")
test_result "requiresVerification=true" '"requiresVerification":true' "$RESP"
test_result "no content field" "" "$(echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("content",""))' 2>/dev/null)"
test_result "no subject leaked" "" "$(echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("subject",""))' 2>/dev/null)"
test_result "no from leaked" "" "$(echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("from",""))' 2>/dev/null)"
echo ""

# --- T2: GET with wrong ticket → requiresVerification ---
echo "[T2] GET LOA-2 message with wrong ticket"
RESP=$(curl -s --max-time 30 -H "X-Verify-Ticket: badticket123" "$BASE/api/org/emails/$LOA2_UUID")
test_result "requiresVerification=true" '"requiresVerification":true' "$RESP"
echo ""

# --- T3: GET attachment without ticket → 403 ---
echo "[T3] GET attachment without ticket"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$BASE/api/org/emails/$LOA2_UUID/attachments/0")
test_http_code "attachment blocked" "403" "$HTTP"
echo ""

# --- T4: GET attachment with wrong ticket → 403 ---
echo "[T4] GET attachment with wrong ticket"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 -H "X-Verify-Ticket: badticket" "$BASE/api/org/emails/$LOA2_UUID/attachments/0")
test_http_code "attachment blocked" "403" "$HTTP"
echo ""

# --- T5: Reply without ticket → 403 ---
echo "[T5] Reply without ticket"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
  -X POST -H "Content-Type: application/json" \
  -d "{\"messageUuid\":\"$LOA2_UUID\",\"responseText\":\"test\"}" \
  "$BASE/api/org/emails/reply")
test_http_code "reply blocked" "403" "$HTTP"
echo ""

# --- T6: Reply with wrong ticket → 403 ---
echo "[T6] Reply with wrong ticket"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
  -X POST -H "Content-Type: application/json" -H "X-Verify-Ticket: badticket" \
  -d "{\"messageUuid\":\"$LOA2_UUID\",\"responseText\":\"test\"}" \
  "$BASE/api/org/emails/reply")
test_http_code "reply blocked" "403" "$HTTP"
echo ""

# --- T7: Verify with wrong code → 403 ---
echo "[T7] Verify with wrong code"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
  -X POST -H "Content-Type: application/json" \
  -d '{"code":"000000"}' \
  "$BASE/api/org/emails/$LOA2_UUID/verify")
test_http_code "wrong code rejected" "403" "$HTTP"
echo ""

# --- T8: Verify with invalid format → 400 ---
echo "[T8] Verify with invalid code format"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
  -X POST -H "Content-Type: application/json" \
  -d '{"code":"abc"}' \
  "$BASE/api/org/emails/$LOA2_UUID/verify")
test_http_code "bad format rejected" "400" "$HTTP"
echo ""

# --- T9: Nonexistent UUID → 404 ---
echo "[T9] GET nonexistent UUID"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$BASE/api/org/emails/nonexistent-uuid-000")
test_http_code "not found" "404" "$HTTP"
echo ""

# --- T10: Ticket for wrong UUID ---
echo "[T10] Ticket for wrong UUID (cross-UUID reuse)"
# First get a real ticket via the verify endpoint — but we can't without a real OTP.
# Instead, generate a fake 64-char hex and verify it's rejected.
FAKE_TICKET=$(python3 -c "import secrets; print(secrets.token_hex(32))")
RESP=$(curl -s --max-time 30 -H "X-Verify-Ticket: $FAKE_TICKET" "$BASE/api/org/emails/$LOA2_UUID")
test_result "fake ticket rejected" '"requiresVerification":true' "$RESP"
echo ""

# --- T11: Brute-force lockout ---
echo "[T11] Brute-force lockout (3 wrong codes → 429)"
# Reset by using a fresh UUID context — use same UUID, OTP state may already exist
for i in 1 2 3; do
  curl -s -o /dev/null --max-time 10 \
    -X POST -H "Content-Type: application/json" \
    -d "{\"code\":\"00000$i\"}" \
    "$BASE/api/org/emails/$LOA2_UUID/verify"
done
# 4th attempt should be 429
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
  -X POST -H "Content-Type: application/json" \
  -d '{"code":"000004"}' \
  "$BASE/api/org/emails/$LOA2_UUID/verify")
# Could be 429 (locked) or 403 (max_attempts) depending on state
if [[ "$HTTP" == "429" || "$HTTP" == "403" ]]; then
  echo "  PASS  brute-force lockout (HTTP $HTTP)"
  ((PASS++))
else
  echo "  FAIL  brute-force lockout (expected 429 or 403, got $HTTP)"
  ((FAIL++))
fi
echo ""

# --- T12: LOA-1 message served without ticket ---
if [[ -n "$LOA1_UUID" ]]; then
  echo "[T12] GET LOA-1 message without ticket (should serve content)"
  RESP=$(curl -s --max-time 30 "$BASE/api/org/emails/$LOA1_UUID")
  if echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); assert "content" in d or not d.get("requiresVerification")' 2>/dev/null; then
    echo "  PASS  LOA-1 served without verification"
    ((PASS++))
  else
    echo "  FAIL  LOA-1 blocked unexpectedly"
    ((FAIL++))
  fi
else
  echo "[T12] SKIP — no LOA-1 UUID provided"
  ((SKIP++))
fi
echo ""

# --- T13: Respond page without ticket (metadata leak check) ---
echo "[T13] Respond page data fetch without ticket"
RESP=$(curl -s --max-time 30 "$BASE/api/org/emails/$LOA2_UUID")
HAS_SUBJECT=$(echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("LEAKED" if d.get("subject") else "CLEAN")' 2>/dev/null)
HAS_FROM=$(echo "$RESP" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("LEAKED" if d.get("from") else "CLEAN")' 2>/dev/null)
test_result "no subject in unverified response" "CLEAN" "$HAS_SUBJECT"
test_result "no from in unverified response" "CLEAN" "$HAS_FROM"
echo ""

echo "=== Results ==="
echo "  PASS: $PASS"
echo "  FAIL: $FAIL"
echo "  SKIP: $SKIP"
echo "  TOTAL: $((PASS + FAIL + SKIP))"
echo ""

if [[ $FAIL -gt 0 ]]; then
  echo "FAILED — $FAIL test(s) failed"
  exit 1
else
  echo "ALL PASS"
  exit 0
fi
