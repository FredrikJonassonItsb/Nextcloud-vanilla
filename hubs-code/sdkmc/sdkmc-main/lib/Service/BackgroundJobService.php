<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\BackgroundJob\SignaledJob;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\IParallelAwareJob;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for immediate background job execution.
 *
 * Uses nohup to spawn jobs as detached processes, allowing
 * immediate execution without waiting for Nextcloud's scheduler.
 */
class BackgroundJobService {
    private const RESERVATION_STALE_SECONDS = 6 * 3600;

    public function __construct(
        private IJobList $jobList,
        private LoggerInterface $logger,
        private IDBConnection $db,
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * Schedule and immediately execute a background job.
     *
     * @param class-string<IJob> $jobClass The job class to execute
     * @param mixed $argument Optional argument to pass to the job
     * @param bool $skipIfExists If true, skip scheduling if job already exists
     * @return bool True if job was spawned or signal was written, false if skipped
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function executeNow(
        string $jobClass,
        mixed $argument = null,
        bool $skipIfExists = true,
    ): bool {
        // Check if a row with same class + argument already exists
        $jobExists = $this->jobList->has($jobClass, $argument);

        // For SignaledJob: only write a signal when the same job (same arguments)
        // already exists. This tells the drain loop to re-run with the same args.
        // Different arguments create a separate row — no signal needed, the new
        // row is spawned by SignaledJob's shutdown spawn after the current run.
        if ($jobExists && is_subclass_of($jobClass, SignaledJob::class)) {
            SignaledJob::signalRerun($this->appConfig, $jobClass, $argument);
        }

        if ($jobExists) {
            $this->logger->debug("Job {$jobClass} already scheduled, executing existing job");
        }

        if (!$jobExists || !$skipIfExists) {
            $this->jobList->add($jobClass, $argument);
        }

        // Find the specific job row matching our class + argument.
        // getJobsIterator only filters by class, so we match the argument ourselves.
        // This avoids accidentally picking up a sibling row with different arguments
        // (which can happen when add() changes the sort order of the target row).
        $job = null;
        $encodedArg = json_encode($argument);
        foreach ($this->jobList->getJobsIterator($jobClass, null, 0) as $foundJob) {
            if (json_encode($foundJob->getArgument()) === $encodedArg) {
                $job = $foundJob;
                break;
            }
        }

        if ($job === null) {
            $this->logger->error("Failed to retrieve {$jobClass} for execution");
            return false;
        }

        $jobId = $job->getId();

        // For jobs that disallow parallel runs, use safe reservation instead of --force-execute
        if ($job instanceof IParallelAwareJob && !$job->getAllowParallelRuns()) {
            if ($this->isJobRunning($jobId, $jobClass)) {
                $this->logger->info("Job {$jobClass} (id: {$jobId}) already running, pending work will be picked up");
                return true;
            }

            if (!$this->tryReserveJob($jobId)) {
                $this->logger->info("Job {$jobClass} (id: {$jobId}) skipped: already reserved or lost reservation race");
                return false;
            }

            if ($job instanceof TimedJob) {
                $this->resetLastRun($jobId);
            }

            $cmd = sprintf(
                'nohup php %s/occ background-job:execute %d > /dev/null 2>&1 &',
                escapeshellarg(\OC::$SERVERROOT),
                $jobId
            );
            exec($cmd);

            $this->logger->info("Job {$jobClass} (id: {$jobId}) spawned for background execution (parallel-protected)");
            return true;
        }

        // Jobs that allow parallel runs: keep existing behavior
        $cmd = sprintf(
            'nohup php %s/occ background-job:execute --force-execute %d > /dev/null 2>&1 &',
            escapeshellarg(\OC::$SERVERROOT),
            $jobId
        );
        exec($cmd);

        $this->logger->info("Job {$jobClass} (id: {$jobId}) spawned for background execution");
        return true;
    }

    /**
     * Check if a job is currently running using reserved_at and flock-probe.
     *
     * For SignaledJob subclasses, uses flock to detect stale reserved_at
     * (process died but reserved_at not cleared). If flock succeeds, the
     * process is dead and reserved_at is cleared.
     */
    private function isJobRunning(int $jobId, string $jobClass): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('reserved_at')
            ->from('jobs')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return false;
        }

        /** @var array{reserved_at: int|string} $row */
        $reservedAt = (int)$row['reserved_at'];
        $now = time();
        if ($reservedAt <= ($now - self::RESERVATION_STALE_SECONDS)) {
            return false;
        }

        // reserved_at is recent. Flock-probe: is the process actually alive?
        if (!is_subclass_of($jobClass, SignaledJob::class)) {
            return true;
        }

        $lockFile = SignaledJob::lockFilePath($jobClass);
        $fp = fopen($lockFile, 'c');
        if (!is_resource($fp)) {
            $this->logger->warning("Cannot open lock file {$lockFile} for flock-probe");
            return true;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return true;
        }

        // Lock acquired: process is dead, reserved_at is stale
        flock($fp, LOCK_UN);
        fclose($fp);
        $this->clearReservedAt($jobId);
        return false;
    }

    /**
     * Clear stale reserved_at for a job whose process is confirmed dead.
     */
    private function clearReservedAt(int $jobId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('jobs')
            ->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Atomically reserve a job by setting reserved_at, only if not already
     * reserved (reserved_at expired beyond 6 hours). Returns true if this
     * caller won the reservation.
     */
    private function tryReserveJob(int $jobId): bool {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->update('jobs')
            ->set('reserved_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('reserved_at', $qb->createNamedParameter($now - self::RESERVATION_STALE_SECONDS, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() > 0;
    }

    /**
     * Reset last_run to allow TimedJob interval check to pass.
     * Replaces the useful part of --force-execute without resetting reserved_at.
     */
    private function resetLastRun(int $jobId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('jobs')
            ->set('last_run', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
