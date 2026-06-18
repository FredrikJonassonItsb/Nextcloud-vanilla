<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\ServiceAccountAuth;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the **calendar** / CalDAV layer (SAGA R7 — prepare the case's
 * calendar object so case meetings carry CATEGORIES={hubsCaseId}).
 *
 * The calendar app is a SEPARATE NC app; the durable store is CalDAV. R7 prepares
 * a calendar object keyed by the hubsCaseId and returns its objUri, stored as a
 * pekare (objekt_typ='calendar'); the compensation removes it.
 *
 * SEAM B — concrete write path (chosen):
 * --------------------------------------
 * We write **in-process** through {@see \OCA\DAV\CalDAV\CalDavBackend}
 * (createCalendar / createCalendarObject / deleteCalendarObject). Rationale:
 *
 *   1. NO HTTP, NO AUTH. CalDavBackend talks straight to the DAV tables, so the
 *      saga needs no session, no OCS round-trip and no extra credential — unlike a
 *      CalDAV PUT via {@see ServiceAccountAuth}, which would re-introduce the very
 *      HTTP-auth problem Seam A only just solved for the OCS clients.
 *   2. It is the only path that can CREATE both a calendar and an object. The
 *      public {@see \OCP\Calendar\IManager} is read/search-only (no createCalendar
 *      / createCalendarObject); its only write surface is ICreateFromString on an
 *      already-existing, writable calendar fetched for a principal — heavier and
 *      circular for our "ensure-then-put" need.
 *   3. The objUri is authored deterministically here ('hubs-case-{id}.ics') and
 *      passed straight INTO createCalendarObject, so the value we return is exactly
 *      the pekare's objekt_id — the pointer shape is preserved verbatim.
 *
 * ExApp-cleanliness: CalDavBackend lives in OCA\DAV (not OCP), so we resolve it
 * LAZILY via the DI container inside a try/catch instead of a hard constructor
 * type-hint. The client itself stays autowirable, and if DAV is not resolvable
 * (e.g. running out-of-process as an ExApp later) we simply degrade to null.
 *
 * The per-case objects live in ONE shared service-account calendar
 * ('hubs-arende-arenden' under the service account's principal), ensured on first
 * use — not one calendar per case.
 *
 * GRACEFUL DEGRADATION: calendar absent / DAV unresolvable / no service account /
 * any failure ⇒ NO-OP + null; the saga continues.
 *
 * NO PII: the VEVENT SUMMARY is pseudonymous (the case id only); the real subject
 * data lives in the facksystem, never here. The hubsCaseId is the coordination key,
 * carried as both UID and CATEGORIES.
 */
class CalendarClient {
    private const APP_ID = 'calendar';

    /**
     * The per-OWNER case calendar (same URI under each owning principal). At case
     * birth the owner is the SERVICE ACCOUNT (case is otilldelat); on assignment the
     * object is RE-HOMED into the handläggare's own calendar of this URI (decision:
     * handläggar-ägd kalender). So a given hubsCaseId has exactly one calendar
     * object, living in whichever principal currently owns the case.
     */
    private const CASE_CALENDAR_URI = 'hubs-arende-arenden';
    private const CASE_CALENDAR_DISPLAYNAME = 'Hubs ärenden';

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ServiceAccountAuth $serviceAuth,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private IUserManager $userManager,
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::APP_ID);
    }

    /**
     * R7 — prepare the calendar object for the case (CATEGORIES={hubsCaseId}) in the
     * OWNER's calendar.
     *
     * Ensures the owner's case-calendar exists, then writes one VEVENT
     * (UID/CATEGORIES = $hubsCaseId, pseudonymous SUMMARY) and returns its objUri.
     *
     * @param string      $hubsCaseId Canonical case UUID — becomes UID + CATEGORIES.
     * @param string|null $ownerUid   The owning handläggare's uid; null (or an
     *        unknown uid) ⇒ the SERVICE ACCOUNT holds it (case otilldelat at birth).
     * @return string|null The objUri (= the pekare's objekt_id), or null (NO-OP / failure).
     */
    public function prepareCaseCalendar(string $hubsCaseId, ?string $ownerUid = null): ?string {
        if (!$this->isAvailable()) {
            $this->noop('prepareCaseCalendar', $hubsCaseId);
            return null;
        }

        // Deterministic objUri — authored here and used verbatim as the pekare id.
        $objUri = 'hubs-case-' . $hubsCaseId . '.ics';
        $created = $this->caldavRequest('PUT', $objUri, $hubsCaseId, $ownerUid);
        if (!$created) {
            // Could not write (no DAV / no owner principal / failure) — graceful.
            return null;
        }

        $this->logger->info('hubs_arende: CalendarClient.prepareCaseCalendar', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'objUri' => $objUri,
            'owner' => $ownerUid ?? '(service-account)',
        ]);

        return $objUri;
    }

    /**
     * R7 compensation — PERMANENTLY remove the prepared calendar object (bypassing
     * the calendar trashbin), so rollback/purge leaves no soft-deleted remnant.
     *
     * @param string      $objUri   The object uri to remove.
     * @param string|null $ownerUid Whose calendar currently holds it (the pekare's
     *        riktning); null ⇒ the service account. Must match where it was written.
     * @return bool true if attempted (and not a NO-OP), false otherwise.
     */
    public function removeCalendar(string $objUri, ?string $ownerUid = null): bool {
        if (!$this->isAvailable()) {
            $this->noop('removeCalendar', $objUri);
            return false;
        }

        $attempted = $this->caldavRequest('DELETE', $objUri, $objUri, $ownerUid);

        $this->logger->info('hubs_arende: CalendarClient.removeCalendar', [
            'app' => 'hubs_arende',
            'objUri' => $objUri,
            'owner' => $ownerUid ?? '(service-account)',
            'attempted' => $attempted,
        ]);

        return $attempted;
    }

    /**
     * Re-home the case calendar object from one owner to another (the assignment
     * step: service-account → handläggare). Removes the object from $fromUid's
     * calendar (best-effort) and recreates it in $toUid's own calendar. Both legs
     * are graceful; an unknown $toUid falls back to the service account.
     *
     * @param string      $hubsCaseId Canonical case UUID.
     * @param string|null $fromUid    Current owner (null ⇒ service account).
     * @param string|null $toUid      New owner (null/unknown ⇒ service account).
     * @return string|null The objUri (unchanged value) on success, or null (NO-OP/failure).
     */
    public function moveCaseCalendar(string $hubsCaseId, ?string $fromUid, ?string $toUid): ?string {
        if (!$this->isAvailable()) {
            $this->noop('moveCaseCalendar', $hubsCaseId);
            return null;
        }

        $objUri = 'hubs-case-' . $hubsCaseId . '.ics';
        // Create at the NEW owner FIRST — if this fails the object stays untouched at
        // the old owner (no data loss; the caller keeps the old riktning).
        $created = $this->caldavRequest('PUT', $objUri, $hubsCaseId, $toUid);
        if (!$created) {
            return null;
        }
        // Then remove from the OLD owner — but ONLY when it resolves to a DIFFERENT
        // calendar than the new one, else we'd delete what we just wrote.
        if ($this->principalFor($fromUid) !== $this->principalFor($toUid)) {
            $this->caldavRequest('DELETE', $objUri, $objUri, $fromUid);
        }

        $this->logger->info('hubs_arende: CalendarClient.moveCaseCalendar', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'from' => $fromUid ?? '(service-account)',
            'to' => $toUid ?? '(service-account)',
        ]);

        return $objUri;
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /**
     * The concrete CalDAV write (seam B), in-process via CalDavBackend.
     *
     * PUT    — ensure the shared service-account calendar, then create a VEVENT
     *          whose UID/CATEGORIES is $ref ($hubsCaseId) at the basename $objUri.
     * DELETE — remove the object $objUri from the shared calendar.
     *
     * No HTTP, no session, no credential: we talk straight to the DAV tables. All
     * failures are swallowed + logged so the SAGA continues.
     *
     * @param string|null $ownerUid Whose calendar to write to/delete from; null (or
     *        an unknown uid) ⇒ the service account.
     * @return bool true if the write/delete was performed, false on any NO-OP/failure.
     */
    private function caldavRequest(string $method, string $objUri, string $ref, ?string $ownerUid = null): bool {
        try {
            $backend = $this->calDavBackend();
            if ($backend === null) {
                $this->logger->debug('hubs_arende: CalendarClient CalDavBackend ej tillgänglig (graceful)', [
                    'app' => 'hubs_arende',
                    'method' => $method,
                ]);
                return false;
            }

            $principalUri = $this->principalFor($ownerUid);
            if ($principalUri === null) {
                $this->logger->debug('hubs_arende: CalendarClient saknar ägarprincipal (graceful)', [
                    'app' => 'hubs_arende',
                    'method' => $method,
                ]);
                return false;
            }

            $calendarId = $this->ensureCaseCalendar($backend, $principalUri);
            if ($calendarId === null) {
                return false;
            }

            if ($method === 'DELETE') {
                // Force PERMANENT delete (no calendar-trashbin), so a rolled-back or
                // purged case leaves NO lingering soft-deleted object that would
                // re-accumulate across reseeds. Args: ($calendarId, $objUri,
                // $calendarType=0=CALENDAR_TYPE_CALENDAR, $forceDeletePermanently=true).
                // The 0 is passed as a literal to keep this file free of any compile-
                // time OCA\DAV symbol reference (ExApp-cleanliness); CalDavBackend's
                // signature is deleteCalendarObject($calendarId,$objectUri,$calendarType,$force).
                $backend->deleteCalendarObject($calendarId, $objUri, 0, true);
                return true;
            }

            // PUT — author a pseudonymous VEVENT and create the object. $ref is the
            // hubsCaseId (UID + CATEGORIES); never any PII.
            $ics = $this->buildCaseVEvent($ref, $objUri);
            $backend->createCalendarObject($calendarId, $objUri, $ics);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: CalendarClient CalDAV-anrop misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'method' => $method,
                // $objUri embeds the hubsCaseId (a coordination uuid, not PII) but we
                // still digest the free-text ref to stay uniformly PII-safe.
                'ref' => $this->safeRef($ref),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Resolve the in-process CalDavBackend from the DI container, or null when it
     * cannot be resolved (DAV app absent / running out-of-process as an ExApp).
     *
     * Resolved lazily + by FQCN string so this file carries no hard compile-time
     * dependency on OCA\DAV — the app stays ExApp-clean and autowirable.
     */
    private function calDavBackend(): ?object {
        $class = 'OCA\\DAV\\CalDAV\\CalDavBackend';
        if (!class_exists($class)) {
            return null;
        }
        try {
            $backend = $this->container->get($class);
            return is_object($backend) ? $backend : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the principal that should own the case calendar.
     *
     * A real $ownerUid (an EXISTING NC user) ⇒ that handläggare's own principal
     * ('principals/users/{uid}') — decision: handläggar-ägd kalender. A null uid, or
     * a uid that is NOT a real user (e.g. a demo handläggare like 'demo-hl-3'), falls
     * back to the SERVICE ACCOUNT principal so we never create a calendar under a
     * non-existent principal. Returns null only when neither is resolvable.
     */
    private function principalFor(?string $ownerUid): ?string {
        if ($ownerUid !== null && $ownerUid !== '' && $this->userManager->userExists($ownerUid)) {
            return 'principals/users/' . $ownerUid;
        }
        if ($ownerUid !== null && $ownerUid !== '') {
            $this->logger->debug('hubs_arende: CalendarClient okänd ägar-uid → service-konto (graceful)', [
                'app' => 'hubs_arende',
                'owner' => $ownerUid,
            ]);
        }
        return $this->serviceAccountPrincipal();
    }

    /**
     * The service-account calendar principal ('principals/users/{sa_user}'), or
     * null when no service account is configured.
     *
     * The uid is the same value Seam A's {@see ServiceAccountAuth} authenticates
     * with; here we only need its principal to own the shared calendar in-process,
     * so we read the configured uid directly (no credential / no HTTP involved).
     */
    private function serviceAccountPrincipal(): ?string {
        if (!$this->serviceAuth->isConfigured()) {
            return null;
        }
        $uid = trim($this->appConfig->getAppValueString(ServiceAccountAuth::CONFIG_KEY_USER, ''));
        if ($uid === '') {
            return null;
        }
        return 'principals/users/' . $uid;
    }

    /**
     * Ensure the single shared service-account calendar exists; return its id.
     *
     * Idempotent: returns the existing calendar's id if present, otherwise creates
     * it. Returns null on failure (graceful).
     *
     * @param object $backend An OCA\DAV\CalDAV\CalDavBackend instance.
     */
    private function ensureCaseCalendar(object $backend, string $principalUri): ?int {
        $existing = $backend->getCalendarByUri($principalUri, self::CASE_CALENDAR_URI);
        if (is_array($existing) && isset($existing['id']) && is_numeric($existing['id'])) {
            return (int)$existing['id'];
        }

        $calendarId = $backend->createCalendar($principalUri, self::CASE_CALENDAR_URI, [
            '{DAV:}displayname' => self::CASE_CALENDAR_DISPLAYNAME,
            'components' => 'VEVENT,VTODO',
        ]);

        return is_numeric($calendarId) ? (int)$calendarId : null;
    }

    /**
     * Build a minimal, PII-FREE VEVENT for a case.
     *
     * Carries the hubsCaseId as UID + CATEGORIES (the coordination key) and a
     * pseudonymous SUMMARY. No subject/person data ever lands here — that lives in
     * the facksystem. DTSTART is an all-day placeholder anchored to "today" so the
     * object is a valid, dateable VEVENT; meetings get their real times later.
     */
    private function buildCaseVEvent(string $hubsCaseId, string $objUri): string {
        $uid = 'hubs-case-' . $hubsCaseId;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stamp = $now->format('Ymd\THis\Z');
        $day = $now->format('Ymd');

        // Pseudonymous label — id only, no PII.
        $summary = 'Hubs ärende ' . $hubsCaseId;

        // RFC 5545. CRLF line endings as the spec requires.
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ITSL//Hubs Ärende//SV',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $this->escapeText($uid),
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . $day,
            'SUMMARY:' . $this->escapeText($summary),
            'CATEGORIES:' . $this->escapeText($hubsCaseId),
            'TRANSP:TRANSPARENT',
            'X-HUBS-OBJURI:' . $this->escapeText($objUri),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    /** Escape a value for an iCalendar TEXT property (RFC 5545 §3.3.11). */
    private function escapeText(string $value): string {
        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value,
        );
    }

    private function noop(string $method, string $ref): void {
        $this->logger->debug('hubs_arende: CalendarClient.' . $method . ' NO-OP (calendar ej aktiverad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
    }

    /**
     * Build a non-reversible, PII-safe digest of a free-text value for logging.
     *
     * OSL 2009:400 26 kap / GDPR art. 5.1.c: never log a free-text value verbatim.
     * Returns the byte length plus a short SHA-256 prefix so log lines stay
     * correlatable without exposing the raw value. Empty input yields a sentinel.
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
