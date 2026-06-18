# ITSL Mail — Stable-to-Stable Upgrade Guide

Complete reference for upgrading from v4.2.0 to v5.7.4 via stable branch tips.
Every step is one Claude session. Start each session with:

```
Read docs/STABLE-UPGRADE-GUIDE.md and execute Step N (vX.Y.Z → vA.B.C).
```

---

## Overview

```
v4.2.0 → v4.2.7 → v4.3.7 → v5.0.8 → v5.1.11 → v5.2.1 → v5.3.3 → v5.4.1 → v5.5.15 → v5.6.15 → v5.7.4
 START    SMALL    SMALL    MEDIUM    SMALL     MEDIUM   SMALL    SMALL    SMALL     LARGE     MEDIUM
```

10 steps. Each produces a deployable, testable state. Each is one git commit.

**Reference branch:** `upgrade/v8` has the FINAL correct state at v5.7.2.
Use `git show upgrade/v8:<path>` to see what files should look like at the end.
Note: v8 followed main, we follow stable branches. Final states are very close
but not byte-identical. Use v8 as a guide, not a copy source.

---

## Repo Architecture

```
upstream/           READ-ONLY git submodule (Nextcloud Mail)
patches/            22 quilt patches applied on top of upstream
src/itsl/           ITSL Vue components, stores, utils (52 files)
overlay/            Files that REPLACE upstream (webpack, appinfo, package.json)
scripts/assemble.sh Build: rsync upstream + quilt push + copy itsl + overlay
.build/             Ephemeral assembled app (gitignored)
```

**8 webpack aliases** transparently swap upstream components with ITSL versions:

| Upstream | ITSL Replacement |
|----------|-----------------|
| Envelope.vue | EnvelopeItsl.vue |
| ThreadEnvelope.vue | ThreadEnvelopeItsl.vue |
| NewMessageModal.vue | NewMessageModalItsl.vue |
| Composer.vue | ComposerItsl.vue |
| MenuEnvelope.vue | MenuEnvelopeItsl.vue |
| NewMessageButtonHeader.vue | NewMessageButtonHeaderItsl.vue |
| DeleteTagModal.vue | DeleteTagModal.vue |
| TagItem.vue | TagItem.vue |

---

## Universal Workflow (Every Step)

### 0. Pre-flight
```bash
# Tag current state for rollback
git tag pre-step-N

# Verify SdkMc version on target server
ssh dev7.hubs.se "sudo docker exec hubs-php php occ app:list --output=json" | jq -r '.enabled.sdkmc'
# SdkMc must be updated BEFORE mail on any target server

# Check current upstream
git -C upstream describe --tags
```

### 1. Advance upstream
```bash
cd upstream && git checkout <target-tag> && cd ..
```

### 2. Assemble and test patches
```bash
rm -rf .build && bash scripts/assemble.sh
```
If patches fail → see **Patch Refresh Protocol** below.

### 3. Review aliased component changes
```bash
# Check what changed in the UPSTREAM aliased components
git -C upstream diff <from-tag>..<to-tag> -- \
  src/components/Envelope.vue \
  src/components/ThreadEnvelope.vue \
  src/components/NewMessageModal.vue \
  src/components/Composer.vue \
  src/components/MenuEnvelope.vue \
  src/components/NewMessageButtonHeader.vue \
  src/components/DeleteTagModal.vue \
  src/components/TagItem.vue

# ALSO check PARENT components (what props/events they pass to aliased ones)
git -C upstream diff <from-tag>..<to-tag> -- \
  src/components/MailboxThread.vue \
  src/components/Thread.vue \
  src/views/Home.vue
```
If changes found → see **Aliased Component Adaptation Protocol** below.

### 4. Review store API changes
```bash
# Check if upstream stores changed APIs that src/itsl/ calls directly
git -C upstream diff <from-tag>..<to-tag> -- src/store/ | grep -E 'export (function|const|async)'
```

### 5. Update overlay if needed
- `overlay/appinfo/info.xml` — update version and NC/PHP constraints per step
- `overlay/package.json` — update if new deps needed
- `overlay/package-lock.json` — regenerate after any package.json change

### 6. Build
```bash
cd .build && npm install --force && NODE_ENV=production npm run build
```

### 7. Verify
```bash
# CKEditor not duplicated
grep -c 'CKEDITOR_VERSION' js/mail.js  # MUST be exactly 1

# PHP syntax clean
find lib/ -name '*.php' -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Must produce no output

# enhanceMessages still has 4 args (if forward-internal was refreshed)
grep -A2 'enhanceMessages' ../patches/forward-internal.patch
# Must show 4 args: $emailList, $clientFactory, $account, $mailbox

# No stale .body references (after Step 2+)
grep -rn '\.body[^HPs]' ../src/itsl/ --include='*.vue' --include='*.js' | grep -v bodyHtml | grep -v bodyPlain | grep -v bodyStructure
# Must produce no output
```

### 8. Copy lockfile back
```bash
cp package-lock.json ../overlay/package-lock.json
cd ..
```

### 9. Commit
```bash
git add upstream patches/ src/itsl/ overlay/ .gitignore
git commit -m "chore: upgrade upstream <from-tag> → <to-tag>

<summary of ITSL changes>
<patches refreshed>
<aliased components adapted>"

git tag post-step-N
```

### 10. Deploy and Chrome verify
```bash
git push
# Deploy to dev7 (manual method since itsl updateApp doesn't work for daniel/new-mail):
rm -rf .build && bash scripts/assemble.sh
cd .build && npm install --force && NODE_ENV=production npm run build
tar -czf /tmp/mail.tar.gz .
scp /tmp/mail.tar.gz dev7.hubs.se:/tmp/
ssh dev7.hubs.se "sudo docker cp /tmp/mail.tar.gz hubs-php:/tmp/ && sudo docker exec hubs-php bash -c 'cd /var/www/html/apps/mail && tar -xzf /tmp/mail.tar.gz' && sudo docker exec -u www-data hubs-php php occ upgrade"
```

Then run the **Chrome Verification Checklist** below.

---

## Patch Refresh Protocol

### When `assemble.sh` fails:

```bash
cd .build
export QUILT_PATCHES=../patches

# Pop all patches first (assemble.sh already pushed them all)
# --quiltrc=- disables user's quiltrc for deterministic behavior
quilt pop -a --quiltrc=-

# Push one at a time until failure
quilt push --quiltrc=-
# Repeat until a patch fails
```

### Three failure modes:

**1. Context drift** (most common) — the hunk's context lines shifted position.
```
Hunk #1 FAILED at 42.
```
Fix: `quilt push -f --quiltrc=-`, read the `.rej` file, find the same code
at its new location in the target file, apply manually, `quilt refresh --quiltrc=-`.

**2. Semantic conflict** — upstream rewrote the code the patch modifies.
The `.rej` shows both what the patch expected AND what's actually there.
Fix: understand the intent of BOTH the patch (read patch header) and the
upstream change. Combine them. This requires judgment.

**3. File deleted/renamed** — the target file no longer exists.
```
can not find file to patch
```
Fix: you must REWRITE the patch to target the new file. Check the step-specific
notes below for known rewrite events. For unknown ones, use `git -C upstream log
--diff-filter=R -- <old-file>` to find where it was renamed.

### After fixing:
```bash
quilt refresh --quiltrc=-    # Update the patch file
quilt push -a --quiltrc=-    # Apply remaining patches

# VERIFY zero fuzz/offset (CRITICAL — fuzz means silent misapplication)
quilt pop -a --quiltrc=- && quilt push -a --quiltrc=- 2>&1 | grep -iE "fuzz|offset"
# Must produce ZERO output

cd ..
```

### When you're stuck:
Check the v8 reference: `git show upgrade/v8:patches/<name>.patch`
This shows the FINAL state at v5.7.2. It won't apply at intermediate versions,
but it shows what the patch SHOULD look like conceptually.

---

## Aliased Component Adaptation Protocol

When an aliased upstream component changes, the ITSL replacement must be updated.
The build will NOT catch missing adaptations — they cause RUNTIME failures.

### Step 1: Categorize each upstream change

Run: `git -C upstream diff <from>..<to> -- src/components/<Component>.vue`

Classify into:
- **PROP/EVENT** — new props added, props renamed, new emits
- **INTERNAL** — data, methods, computed, watchers
- **TEMPLATE** — new slots, changed child components
- **STYLE** — CSS changes

### Step 2: Determine what MUST be ported

**PROP/EVENT changes: almost always MUST port.** If a parent component now passes
a new prop or listens for a new event, the ITSL component must accept it.
To find what parents pass:
```bash
git -C upstream grep -n '<ComponentName' -- src/ | grep -v 'src/components/ComponentName'
```

**INTERNAL changes: port only if:**
- It fixes a bug that also affects ITSL
- It's required by a template change you're porting
- It changes a lifecycle hook that affects behavior
- Skip: pure refactors, features ITSL doesn't use

**TEMPLATE changes: port if:**
- Child components changed their slot API
- Parent expectations changed (new required slots)
- Skip: if ITSL template is completely different from upstream

**STYLE changes:**
- Port if ITSL uses the same CSS class names
- Port CSS variable adoptions (replace magic numbers)

### Step 3: Cross-reference with v8
```bash
# See what v8 did for this component (approximate, v8 followed main not stable)
git log --oneline upgrade/v8 -- src/itsl/components/<ITSLComponent>.vue
```

### Step 4: Apply and verify
Edit the ITSL file. Build. Check browser console for runtime errors.

---

## Chrome Verification Checklist

Run after EVERY step. PASS = expected behavior AND no red console errors.

### Minimum (every step, ~10 minutes):

1. **App loads:** Navigate to Mail. Sidebar shows accounts. No white screen.
2. **Inbox renders:** Click account. Envelopes populate. No stuck spinners.
3. **Read message:** Click envelope. Body renders. Attachments show.
4. **Compose + send:** New message → type text → Send → verify in Sent folder.
5. **SDK message:** Compose SDK message. Send. Verify delivery + SDK badge.
6. **FAX compose:** Select FAX type. Verify NO subject field. Phone input works.
7. **Draft lifecycle:** Compose → close → Drafts folder → reopen → content preserved.
8. **Browser back:** Open attachment viewer → press Back → viewer closes (not page nav).
9. **Reply in thread:** Open thread → Reply → Send → new message appears in thread.

### Extended (after Steps 2, 4, 7, 8, 10):

10. **Tags:** Create tag, assign to message, change color, delete tag.
11. **Save to Files:** Message with attachment → Save to Files → picker opens.
12. **Print as PDF:** Message → Print → PDF generates.
13. **Snooze:** Snooze message → disappears → Snoozed folder → unsnooze → returns.
14. **Forward:** Forward with attachments → attachments included → recipient receives.
15. **SSN field:** Compose Secure Email → SSN checkbox/field present.

### Severity guide:
- **P1 (rollback):** SDK messages broken, CKEditor broken, app won't load
- **P2 (fix before proceeding):** One message type broken, drafts lost, attachments fail
- **P3 (proceed, fix later):** CSS glitches, icon sizes, tooltip text
- **P4 (proceed):** Import ordering, whitespace

---

## Build System Knowledge

### overlay/ is authoritative
`overlay/package.json` and `overlay/package-lock.json` REPLACE upstream's during
assembly. They control npm dependency resolution. NEVER copy upstream's lockfile.

### npm install --force
Required because overlay lockfile + ITSL extra deps = peer conflicts.
Never use `npm ci`.

### CKEditor verification
`grep -c 'CKEDITOR_VERSION' js/mail.js` must return exactly 1.
If 2+: CKEditor duplication. Regenerate overlay/package-lock.json.

### Mozart vendor wrapping (PHP)
Runs via composer post-install-cmd. Wraps favicon/gravatar into OCA\Mail\Vendor\.
CI needs `composer install` (not update) to trigger it.

### psr/log removal
Patch 0001 removes `"provide": {"psr/log": "..."}` from composer.json.
Need `rm -f composer.lock` after assembly — lock was generated WITH the directive.

### Three patches add npm deps
- 0013-tag-system: `libphonenumber-js`
- 0016-save-print: `html2pdf.js`
- 0018-fax-messages: `vue-tel-input@^5.16.0` (pinned v5 for Vue 2)

### enhanceMessages() requires 4 args
`MessageTypeService::enhanceMessages($emailList, $clientFactory, $account, $mailbox)`
When refreshing 0017-forward-internal, ALWAYS verify all 4 args are present.
Missing args → "Could not open folder" 500 errors. Silent data loss.

### appinfo/info.xml version constraints
| Step | Target | PHP | Nextcloud |
|------|--------|-----|-----------|
| 1 | v4.2.7 | 8.1-8.3 | 30-31 |
| 2 | v4.3.7 | 8.1-8.3 | 30-32 |
| 3 | v5.0.8 | 8.1-8.4 | 30-32 |
| 4-9 | v5.1-v5.6 | 8.1-8.4 | 30-32 |
| 10 | v5.7.4 | 8.1-8.5 | 32-34 |

⚠️ v5.7.x requires NC32+. If dev7 runs NC30/31, don't deploy Step 10 there.
Keep overlay/appinfo/info.xml matching the current step's constraints.

---

## Known Corrections (From v8 Mistakes)

### C1: FAX subject warning guard
When upstream adds "warn on empty subject" (Step 3, v5.0.8):
Guard with `data.itsl?.messageType !== 'fax_message'`.
FAX has no subject field — the warning is always wrong for FAX.

### C2: KeepAlive + ITSL #131 are COMPLEMENTARY
KeepAlive prevents component destruction during error/warning states.
ITSL #131 fixes minimize/restore data loss. BOTH are needed.
Don't dismiss #131 as "orthogonal."

### C3: Tooltip "Collapse/Maximize" NOT "Show/Hide recipient details"
ITSL has RecipientInfo pane disabled. Keep ITSL tooltip.
Apply `showRecipientPane` → `largerModal` rename only.

### C4: Dead onPrint() in MenuEnvelopeItsl
Template uses direct `@click="$emit('print')"`. Method is dead code. Remove it.

### C5: Body format migration (.body → .bodyHtml/.bodyPlain)
Check ALL src/itsl/ files: `grep -rn '\.body[^HPs]' src/itsl/`
Specifically: `pdfExportUtils.js` line ~113 uses `message?.body`.

### C6: Close-on-send dead code
Remove `uploadingAttachments`, `sending` data props AND assignments.
Remove `:deep(.wrapper) { height: 100%; }` CSS (targeted Loading component).

### C7: Threaded view toggle + ITSL move sync
ITSL `initMoveSync()` intercepts `moveThread` but NOT `moveMessage`.
Non-threaded mode bypasses the handler. Document; fix if toggle exposed.

### C8: Own-identities autocomplete + message types
Check if suggestions appear for SDK/FAX/SMS/INTERNAL types.
If different code path: harmless. Document finding.

### C9: @nextcloud/vue import migration
Bump overlay/package.json to `^8.23.1` FIRST. Then migrate imports.
Old: `@nextcloud/vue/dist/Components/NcXxx.js`
New: `@nextcloud/vue/components/NcXxx`

### C10: CSS physical → logical properties (Step 9)
Convert ALL src/itsl/ files, not just aliased ones (~15 files):
margin-left→margin-inline-start, padding-left→padding-inline-start,
text-align:left→text-align:start. Verify with grep — zero matches.

### C11: NcButton type → variant (Step 9)
Search ALL src/itsl/ .vue files (~15 files, ~20 instances).
`grep -rn 'type="tertiary"\|type="primary"' src/itsl/ --include='*.vue'`

### C12: MailTransmission.php $from type change
Between v4.x and v5.x, `$from` changes from `AddressList` to single `Address`.
Context lines `$from->first()->toHorde()` become `$from->toHorde()`.
Patches sdk-messaging and audit-logging WILL need refresh.

### C13: MessagesController.php index() rewrite (Step 5)
Complete `index()` restructure at v5.2.x. The `enhanceMessages()` wrapping
in forward-internal must be manually re-anchored around new code.

### C14: `#[\Override]` attributes (Step 5+)
PHP 8.3 `#[\Override]` added to many methods at v5.2.x.
Shifts line numbers for every PHP patch hunk.

---

## Step 1: v4.2.0 → v4.2.7 (SMALL)

**Tag:** `v4.2.7`

### What changes
- Backported bug fixes from v4.3/v5.0 development
- Minor dependency bumps
- 5 components change: Envelope, MenuEnvelope, NewMessageModal, Thread, ThreadEnvelope
- 1 lib file: MessageMapper.php

### Aliased components changed
- Envelope.vue, MenuEnvelope.vue, NewMessageModal.vue, ThreadEnvelope.vue
- Changes are likely small backports — review each diff

### Expected patch conflicts: Low
- Context shifts from backported fixes in patched files
- package.json dependency bumps may shift save-print/tag-system context

### Overlay
- `overlay/appinfo/info.xml`: update version to 4.2.7

---

## Step 2: v4.2.7 → v4.3.7 (SMALL)

**Tag:** `v4.3.7`

### What changes
- NC32 support
- More dependency bumps
- psr/log provide removal (already handled by patch 0001)
- AI summarization fixes

### Aliased components changed
- Envelope.vue, MenuEnvelope.vue, NewMessageModal.vue, ThreadEnvelope.vue

### Overlay
- `overlay/appinfo/info.xml`: update NC max-version to 32

---

## Step 3: v4.3.7 → v5.0.8 (MEDIUM) ⚠️

**Tag:** `v5.0.8`

### What changes
- @nextcloud/vue import path migration
- Subject empty warning (SubjectMissingError)
- KeepAlive restructuring for Composer
- Body format: `.body` → `.bodyHtml`/`.bodyPlain`
- Composer state per account
- `$from` type change in MailTransmission.php (AddressList→Address)
- 310 files, 4 aliased components change

### ⚠️ CRITICAL src/itsl/ changes
1. **@nextcloud/vue imports** — bump overlay to `^8.23.1`, add `@nextcloud/timezones: ^0.1.1`,
   migrate ALL src/itsl/ imports. See C9.
2. **Subject warning + FAX guard** — see C1.
3. **KeepAlive** — see C2.
4. **Body format** — change `.body` → `.bodyHtml`/`.bodyPlain` everywhere. See C5.
5. **Tooltip** — see C3.
6. **Dead onPrint()** — see C4.

### ⚠️ Patch conflicts expected
- sdk-messaging + audit-logging: MailTransmission.php `$from` type change (see C12)
- draft-fixes: NewMessageModal.vue restructuring
- fax-messages: body format changes

### Overlay
- `overlay/package.json`: @nextcloud/vue ^8.23.1, @nextcloud/timezones ^0.1.1
- Regenerate lockfile
- `overlay/appinfo/info.xml`: version 5.0.8, PHP max 8.4

---

## Step 4: v5.0.8 → v5.1.11 (SMALL)

**Tag:** `v5.1.11`

### What changes
- Thread/envelope printing overhaul (threadIndex prop, loading watcher)
- Scroll to most recent message in thread
- iframe-resizer v4→v5
- 267 files

### src/itsl/ changes
1. **ThreadEnvelopeItsl**: Add `threadIndex` prop, loading watcher for printing
2. **Scroll methods**: Replace scrollToCurrentEnvelope with new methods

### Overlay
- `overlay/package.json`: iframe-resizer → `@iframe-resizer/parent`
- Regenerate lockfile

---

## Step 5: v5.1.11 → v5.2.1 (MEDIUM) ⚠️

**Tag:** `v5.2.1`

### What changes
- Threaded view toggle
- Own-identities autocomplete
- Close modal on send / Loading removal
- S/MIME per-alias sign preference
- Avatar caching (fetch-avatar/avatar props)
- saveToCloud() made async
- `#[\Override]` attributes on PHP methods
- MessagesController.php index() complete rewrite
- 656 files, 292 lib changes ⚠️ HIGHEST FILE COUNT

### ⚠️ ALL 5 main aliased components change

### ⚠️ CRITICAL src/itsl/ changes
1. **Close-on-send / Loading removal** — see C6.
2. **Own-identities autocomplete** — see C8.
3. **Threaded view toggle** — see C7.
4. **Avatar caching**: Add fetch-avatar/avatar props to EnvelopeItsl.vue.
5. **S/MIME per-alias**: Add smimeSignAliases, selectedAlias watcher to ComposerItsl.
6. **CSS variables**: Replace magic pixel values in 3 ITSL components.

### ⚠️ Patch conflicts expected — HEAVY
- forward-internal: MessagesController index() rewrite, must re-anchor enhanceMessages (C13)
- sdk-messaging + audit-logging: `#[\Override]` attributes shift all PHP hunks (C14)
- loa3-tagging: saveToCloud() async change — verify `await` on all call sites
- snooze-tracking: SnoozeService null-check addition

### Healthcare risk
LOA-3 tagging: if saveToCloud() isn't awaited, attachments silently lose
security classification. Verify: `grep -n 'saveToCloud' .build/src/` — all
call sites must use `await`.

---

## Step 6: v5.2.1 → v5.3.3 (SMALL)

**Tag:** `v5.3.3`

### What changes
- Bug fixes, CSS custom properties adoption
- HTML source editing support
- 176 files (smallest jump)

### Aliased: all 5 change but mostly CSS/style

---

## Step 7: v5.3.3 → v5.4.1 (SMALL)

**Tag:** `v5.4.1`

### What changes
- TypeScript enablement (tsconfig, ts-loader)
- eslint-perfectionist import ordering
- cs-fixer PHP formatting
- 217 files

### Aliased: 3 of 5 (Envelope, MenuEnvelope, ThreadEnvelope)

### Overlay
- `overlay/webpack.common.js`: Add ts-loader rule + .ts/.tsx extensions

---

## Step 8: v5.4.1 → v5.5.15 (SMALL)

**Tag:** `v5.5.15`

### What changes
- Outline icons throughout
- Icon sizes 16px→20px
- ImportantIcon.vue DELETED upstream
- Import reordering (eslint-perfectionist) across ALL files
- EnvelopeSkeleton restructuring
- Ctrl+Enter send
- CKEditor v37→v44 (still individual packages, NOT unified yet)
- 292 files

### ⚠️ ImportantIcon
Upstream deleted `src/components/icons/ImportantIcon.vue`.
Create ITSL-owned version at `src/itsl/components/icons/ImportantIcon.vue`.
Patch 0003-important-icon: drop the hunk that patched the upstream file.

### src/itsl/ changes
1. **Icon sizes**: `:size="16"` → `:size="20"` across ALL src/itsl/ files.
   `grep -rn ':size="16"' src/itsl/ --include='*.vue'`
2. **Import reordering**: Run eslint on all src/itsl/ files.
3. **Ctrl+Enter**: Add onEditorSubmit to ComposerItsl.vue.
4. **EnvelopeItsl**: Update for EnvelopeSkeleton changes.

### CKEditor packages
At v5.5.15, upstream still uses individual @ckeditor packages (v44).
Overlay must track these versions. Do NOT migrate to unified yet.

---

## Step 9: v5.5.15 → v5.6.15 (LARGE) ⚠️⚠️

**Tag:** `v5.6.15`

This is the hardest step. Three major migrations happen simultaneously.

### What changes
- **CKEditor unified package** (25 individual → single `ckeditor5@^45.2.2`)
- **ESLint 9 flat config**
- **NcButton type→variant** across entire codebase
- CSS physical→logical properties
- ClassificationSettingsService.php DELETED
- viewer-back target file changed (MessageAttachments.vue → AttachementMixin.js)
- Jest → Vitest migration
- NcColorPicker v-model API change
- 635 files ⚠️

### ⚠️ CKEditor Migration
1. `overlay/webpack.common.js`: Rewrite. Remove CKEditorWebpackPlugin, PostCSS,
   CKEditor CSS loader rules. Keep SVG loader rules for CKEditor icons.
   Start from upstream v5.6.15's webpack, add ITSL aliases.
2. `overlay/package.json`: Replace 25 `@ckeditor/*` with `ckeditor5@^45.2.2`.
   Keep `@ckeditor/ckeditor5-vue2@^3.0.1` (Vue binding, separate package).
3. `overlay/package-lock.json`: Regenerate from scratch:
   ```bash
   cd .build && rm -rf node_modules package-lock.json
   npm install --force
   cp package-lock.json ../overlay/
   ```
4. `src/itsl/ckeditor/PastePreserveNewlinesPlugin.js`:
   Change to `import { Plugin } from 'ckeditor5'`
5. Verify: `grep -c 'CKEDITOR_VERSION' js/mail.js` = 1

### ⚠️ Two Patch REWRITES

**priority-inbox.patch**: ClassificationSettingsService.php deleted upstream.
Rewrite patch to target `MailAccount.php` — change classificationEnabled default.
Cannot use quilt refresh. Use v8 reference:
`git show upgrade/v8:patches/priority-inbox.patch`

**viewer-back.patch**: Viewer logic extracted to AttachementMixin.js.
Rewrite patch to target the mixin instead of MessageAttachments.vue.
Use v8 reference: `git show upgrade/v8:patches/viewer-back.patch`

### ⚠️ build-config.patch REWRITE
Jest→Vitest: replace jest.config.js hunks with vitest.config.js.
Use v8 reference: `git show upgrade/v8:patches/build-config.patch`

### ⚠️ Bulk src/itsl/ changes
1. **NcButton type→variant**: ALL .vue files. See C11.
2. **CSS logical properties**: ALL .vue files. See C10.
3. **NcColorPicker v-model**: TagItem.vue — `:value/@input` → `v-model/@submit`

### Test files
`src/itsl/tests/` may need updating for Vitest (jest.fn → vi.fn, etc.)

---

## Step 10: v5.6.15 → v5.7.4 (MEDIUM)

**Tag:** `v5.7.4`

### What changes
- Thread scroll position fix
- Draft save ordering fix
- Forward attachment handling (now via startComposerSession)
- MailAccount.php gains classificationEnabled (priority-inbox patch applies here)
- 211 files

### src/itsl/ changes
1. **ThreadEnvelopeItsl**: ref="header", scrollToThread block:'start',
   scrollToEnvelope with scrollMarginTop
2. **ComposerItsl**: Remove forward attachment mounting block
3. **NewMessageModalItsl**: Move removeEnvelopeMutation before saveDraft

### ⚠️ NC version jump
v5.7.x requires NC32+. Update `overlay/appinfo/info.xml`:
min-version="32" max-version="34", PHP max="8.5"

### Final verification
This is the last step. Run the FULL Chrome checklist (all 15 items).
Compare key files with v8 reference:
```bash
# These should be very close (not identical — v8 was v5.7.2, we're v5.7.4)
diff <(git show upgrade/v8:patches/series) <(cat patches/series)
diff <(git show upgrade/v8:src/itsl/store/constants.js) src/itsl/store/constants.js
```

---

## 22-Patch Conflict Risk Matrix

| # | Patch | Conflict risk by step |
|---|-------|-----------------------|
| 0001 | build-config | Step 9 (REWRITE jest→vitest) |
| 0002 | hide-settings | Low everywhere |
| 0003 | important-icon | Step 8 (upstream file deleted) |
| 0004 | notification-privacy | Low everywhere |
| 0005 | viewer-back | Step 9 (REWRITE to mixin) |
| 0006 | composer-paste | Step 8-9 (CKEditor changes) |
| 0007 | thread-reply-refresh | Medium (outbox/Thread changes) |
| 0008 | translation-fixes | Low everywhere |
| 0009 | wcag-accessibility | Medium |
| 0010 | responsiveness | Medium |
| 0011 | priority-inbox | Step 9 (REWRITE to MailAccount) |
| 0012 | loa3-tagging | Step 5 (saveToCloud async) |
| 0013 | tag-system | HIGH (changes in most steps) |
| 0014 | ssn-checkbox | Low-Medium |
| 0015 | sdk-messaging | HIGH (MailTransmission changes often) |
| 0016 | save-print | Medium (package.json context) |
| 0017 | forward-internal | HIGH (MessagesController rewrites) |
| 0018 | fax-messages | Medium |
| 0019 | draft-fixes | HIGH (actions.js changes) |
| 0020 | bugfix | Low-Medium |
| 0021 | audit-logging | Medium (MailTransmission) |
| 0022 | snooze-tracking | Medium |

---

## Non-Aliased src/itsl/ Files That Need Bulk Updates

These files are NOT webpack-aliased but need changes during bulk migrations
(icon sizes at Step 8, NcButton at Step 9, CSS logical at Step 9):

- TagPopover.vue, TagPopoverItem.vue, SidebarSection.vue
- NavigationAccountItsl.vue, ConfirmationModalItsl.vue, MailboxCombo.vue
- TagSearchIndicator.vue
- sidebar/ActionButtons.vue, sidebar/SnoozeStatusRow.vue
- sidebar/MailboxInfo.vue, sidebar/MessageTypeBadge.vue
- sidebar/SidebarSection.vue, sidebar/StatusRow.vue
- sidebar/ThreadParticipantList.vue
- message/MessageHeaderItsl.vue, message/MetadataIDChipItsl.vue
- message/MetadataAttachmentsItsl.vue
- ThreadInfoSidebar.vue, TagItem.vue
- ckeditor/PastePreserveNewlinesPlugin.js (CKEditor import at Step 9)
- Tests in src/itsl/tests/ (import migrations, Vitest at Step 9)

---

## SdkMc API Surface (Must Remain Compatible)

The ITSL patches reference these SdkMc classes. If SdkMc changes any of
these APIs, Mail breaks at runtime (no build error, only 500s):

**Events:** SendEmailEvent, DraftSentEvent, FetchEmailEvent, FetchThreadEvent,
SaveOrUpdateDraftEvent, ScheduleEmailSendEvent, DeleteDraftEvent,
SerializeLocalMessageEvent, MessageImportantClassifiedEvent

**Services:**
- `MessageTypeService::enhanceMessages($messages, $clientFactory, $account, $mailbox)` — 4 args!
- `ItslTagService` — 8 methods (syncTag, unsyncTag, getAllTagsKeyedByAccount, etc.)
- `TagSearchHelper::processAndClearTags($qb, $select, $query)`

---

## Rollback

### Before deploy (git level):
```bash
git reset --hard pre-step-N
```

### After deploy:
Redeploy the previous step's build. The `pre-step-N` tag marks the last good state.

### Decision criteria:
- P1 (SDK broken, CKEditor broken, app won't load): Immediate rollback
- P2 (one message type broken, drafts lost): Rollback, fix, retry
- P3 (CSS glitch, icon size): Proceed, fix in follow-up
