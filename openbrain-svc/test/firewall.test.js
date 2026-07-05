/**
 * Write-firewall unit tests against the REAL shared pattern file
 * (../stack/shared/pii-patterns.json) — the same file that ships in the
 * Docker image. If a pattern regresses, these tests are the tripwire.
 */

import { test } from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { loadFirewall, FirewallError } from "../src/firewall.js";

const PATTERNS_PATH = fileURLToPath(
  new URL("../../stack/shared/pii-patterns.json", import.meta.url)
);
const fw = loadFirewall(PATTERNS_PATH);

const blocked = (text, expectedId) => {
  const hit = fw.check(text);
  assert.ok(hit, `expected block for: ${expectedId}`);
  if (expectedId) assert.equal(hit.patternId, expectedId);
  assert.ok(hit.reasonSv && hit.reasonSv.length > 5, "Swedish reason present");
};

const passes = (text) => {
  assert.equal(fw.check(text), null);
};

test("swedish personnummer (10 digits, dash) is blocked", () => {
  blocked("Klienten heter Kim, pnr 850712-1234, ring imorgon", "swedish_personnummer");
});

test("swedish personnummer (12 digits, no separator) is blocked", () => {
  blocked("pnr 198507121234 antecknat", "swedish_personnummer");
});

test("anthropic api key prefix is blocked", () => {
  blocked("min nyckel: sk-ant-api03-AAAA", "anthropic_api_key");
});

test("openrouter api key prefix is blocked (smoke-02 fixture)", () => {
  blocked("test sk-or-v1-deadbeefdeadbeef", "openrouter_api_key");
});

test("aws access key is blocked", () => {
  blocked("credentials AKIAIOSFODNN7EXAMPLE here", "aws_access_key");
});

test("hubs case UUID in case context is blocked", () => {
  blocked(
    "ärende 3f2b8c1d-9a4e-4b7f-8c2d-1e5f6a7b8c9d behöver följas upp",
    "hubs_case_uuid_in_case_context"
  );
  blocked(
    "hubsCaseId: 3f2b8c1d-9a4e-4b7f-8c2d-1e5f6a7b8c9d",
    "hubs_case_uuid_in_case_context"
  );
});

test("a bare UUID without case context passes", () => {
  passes("deploy-id 3f2b8c1d-9a4e-4b7f-8c2d-1e5f6a7b8c9d gick igenom");
});

test("bankid reference is blocked", () => {
  blocked("BankID ordernummer 3f2b8c1d-9a4e-4b7f-8c2d-1e5f6a7b8c9d", "bankid_number");
});

test("private key PEM block is blocked", () => {
  blocked("-----BEGIN RSA PRIVATE KEY-----\nMIIC...", "private_key_block");
});

test("credential assignment is blocked", () => {
  blocked("glöm inte password=hunter22again", "credential_assignment");
  blocked("api_key: 'abcdefgh12345678'", "credential_assignment");
});

test("oversized content (>15000 chars) is blocked", () => {
  const big = "arbetsanteckning utan siffror ".repeat(600); // ~18k chars, no digits
  blocked(big, "max_chars");
});

test("transcript dump (>8 role-prefixed lines) is blocked", () => {
  const lines = [];
  for (let i = 0; i < 9; i++) {
    lines.push(i % 2 === 0 ? "user: hej hej" : "assistant: hej själv");
  }
  blocked(lines.join("\n"), "role_prefixed_lines");
});

test("a short transcript-like snippet passes", () => {
  passes("user: hej\nassistant: hej själv — det där löste vi med en flock-fil");
});

test("clean Swedish work note passes", () => {
  passes(
    "Sarah funderar på att lämna sitt jobb för att starta konsultbolag — kolla upp läget med henne nästa vecka."
  );
});

test("assert() throws FirewallError with Swedish refusal message", () => {
  try {
    fw.assert("pnr 850712-1234");
    assert.fail("expected FirewallError");
  } catch (err) {
    assert.ok(err instanceof FirewallError);
    assert.equal(err.httpStatus, 422);
    assert.match(err.message, /^Blockerat:/);
    assert.match(err.message, /personnummer/);
    // Never leak the matched content:
    assert.ok(!err.message.includes("850712"));
  }
});

test("assert() passes clean content through", () => {
  fw.assert("Byggde klart caddy-routingen ikväll, pathprefix per hjärna fungerar.");
});
