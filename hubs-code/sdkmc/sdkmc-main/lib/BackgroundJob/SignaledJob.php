<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig as IGlobalAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Abstract base class for all sdkmc background jobs.
 *
 * Provides flock-based mutual exclusion and IAppConfig-backed signaling
 * for drain-loop re-run semantics. All jobs always run doWork() when
 * triggered (by cron or executeNow). If a signal arrives during execution,
 * the drain loop re-runs doWork() to pick up the new work.
 *
 * Subclasses implement doWork() instead of run(). Call setDeleteAfterRun()
 * in the constructor for run-once (queued-like) semantics. Subclasses must
 * call setInterval() in their constructor.
 */
abstract class SignaledJob extends TimedJob {
    private bool $deleteAfterRun = false;
    private ?string $cachedSignalKey = null;

    public function __construct(
        ITimeFactory $time,
        protected IAppConfig $appConfig,
    ) {
        parent::__construct($time);
        $this->setAllowParallelRuns(false);
    }

    abstract protected function doWork(mixed $argument): void;

    /**
     * Call in subclass constructor for queued-like (run-once) semantics.
     */
    protected function setDeleteAfterRun(): void {
        $this->deleteAfterRun = true;
    }

    /**
     * Override in subclass to set max execution time in seconds.
     * Uses set_time_limit() which measures wall-clock time on most platforms.
     * PHP terminates the process if exceeded, releasing flock automatically.
     * Timer is reset at the start of each drain loop iteration.
     */
    protected function maxExecutionTime(): int {
        return 3600;
    }

    /** @param mixed $argument */
    final protected function run($argument): void {
        // Cache ID early: the DI container may reuse this instance as a
        // singleton, so Server::get() calls in getJobsIterator can mutate
        // $this->id via setId() when hydrating sibling job rows.
        $ownId = $this->getId();

        $lockFile = self::lockFilePath(static::class);
        $fp = fopen($lockFile, 'c');
        if (!is_resource($fp)) {
            return;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $this->writeSignal();
            fclose($fp);
            return;
        }

        try {
            /** @var LoggerInterface $logger */
            $logger = Server::get(LoggerInterface::class);
        } catch (\Throwable) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        try {
            $i = 0;
            do {
                set_time_limit($this->maxExecutionTime());
                $this->refreshReservation($ownId);
                $this->clearSignal();
                try {
                    $this->doWork($argument);
                } catch (\Throwable $e) {
                    $logger->error(static::class . ' failed: ' . $e->getMessage(), [
                        'app' => 'sdkmc', 'exception' => $e,
                    ]);
                }
            } while ($this->hasSignalFresh() && ++$i < 10);
        } finally {
            if ($this->deleteAfterRun) {
                try {
                    /** @var IJobList $jobList */
                    $jobList = Server::get(IJobList::class);
                    $jobList->removeById($ownId);
                } catch (\Throwable $e) {
                    $logger->error('Failed to remove job row: ' . $e->getMessage(), [
                        'app' => 'sdkmc', 'exception' => $e,
                    ]);
                }
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            $this->spawnPendingSiblings($logger, $ownId);
        }
    }

    /**
     * After releasing flock, check if there are pending sibling jobs of the
     * same class (e.g., different arguments queued while this job was running)
     * and spawn them immediately rather than waiting for cron.
     */
    private function spawnPendingSiblings(LoggerInterface $logger, int $ownId): void {
        try {
            /** @var IJobList $jobList */
            $jobList = Server::get(IJobList::class);
            foreach ($jobList->getJobsIterator(static::class, 2, 0) as $sibling) {
                $siblingId = $sibling->getId();
                if ($siblingId === $ownId) {
                    continue;
                }
                $cmd = sprintf(
                    'nohup php %s/occ background-job:execute %d > /dev/null 2>&1 &',
                    escapeshellarg(\OC::$SERVERROOT),
                    $siblingId
                );
                exec($cmd);
                $logger->info(static::class . " spawned pending sibling job (id: {$siblingId})");
                break; // Only spawn one; it will chain-spawn the next via its own shutdown
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to spawn pending sibling: ' . $e->getMessage(), [
                'app' => 'sdkmc', 'exception' => $e,
            ]);
        }
    }

    /**
     * Keep reserved_at fresh so isJobRunning() doesn't consider it stale
     * during long-running drain loops. Without this, a job running >6 hours
     * would have its reserved_at expire, causing redundant process spawns.
     */
    private function refreshReservation(int $jobId): void {
        /** @var IDBConnection $db */
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->update('jobs')
            ->set('reserved_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Get the lock file path for a job class.
     * Shared with BackgroundJobService for flock-probe detection.
     */
    public static function lockFilePath(string $jobClass): string {
        return sys_get_temp_dir() . '/sdkmc_bgj_' . md5($jobClass);
    }

    /**
     * Check signal using potentially cached appconfig.
     */
    private function hasSignal(): bool {
        return $this->appConfig->getAppValueString($this->signalKey(), '') !== '';
    }

    /**
     * Check signal after clearing the global appconfig cache (for drain loop).
     * Required because another process may have written the signal since our
     * last read, and the in-memory cache would miss it.
     */
    private function hasSignalFresh(): bool {
        /** @var IGlobalAppConfig $globalAppConfig */
        $globalAppConfig = Server::get(IGlobalAppConfig::class);
        $globalAppConfig->clearCache();
        return $this->hasSignal();
    }

    private function clearSignal(): void {
        $this->appConfig->deleteAppValue($this->signalKey());
    }

    /**
     * Write a signal to appconfig. Handles the Nextcloud AppConfig race
     * condition where concurrent writes for a new key throw
     * AppConfigUnknownKeyException from the UPDATE path's isLazy() check
     * when the key isn't in the in-memory cache. The INSERT from the
     * concurrent process already wrote the value, so the signal is set.
     */
    private function writeSignal(): void {
        self::writeSignalKey($this->appConfig, $this->signalKey());
    }

    /**
     * Signal key includes argument hash so signals are per-class-per-argument.
     * This prevents a signal for groupB from being consumed by groupA's drain loop.
     */
    private function signalKey(): string {
        return $this->cachedSignalKey ??= self::buildSignalKey(static::class, $this->argument);
    }

    /**
     * Build signal key from class name and argument.
     *
     * @param class-string $jobClass
     * @param mixed $argument
     */
    private static function buildSignalKey(string $jobClass, mixed $argument): string {
        $pos = strrpos($jobClass, '\\');
        $short = $pos !== false ? substr($jobClass, $pos + 1) : $jobClass;
        $encoded = json_encode($argument);
        $argHash = md5($encoded !== false ? $encoded : 'null');
        return 'bgj_rerun_' . $short . '_' . substr($argHash, 0, 8);
    }

    /**
     * Write a signal key to appconfig, handling the concurrent-write race.
     */
    private static function writeSignalKey(IAppConfig $appConfig, string $key): void {
        try {
            $appConfig->setAppValueString($key, '1');
        } catch (AppConfigUnknownKeyException) {
            // Concurrent INSERT race in AppConfig — signal already written.
        }
    }

    /**
     * Write a re-run signal for a specific job class + argument combination.
     * Callable from any service.
     *
     * @param class-string $jobClass
     * @param mixed $argument
     */
    public static function signalRerun(IAppConfig $appConfig, string $jobClass, mixed $argument = null): void {
        self::writeSignalKey($appConfig, self::buildSignalKey($jobClass, $argument));
    }
}
