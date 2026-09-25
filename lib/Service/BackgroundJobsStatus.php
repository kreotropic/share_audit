<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Whether Nextcloud's background jobs are running at all.
 *
 * A file move is only queued by the dashboard; a background job does the work,
 * and only when Nextcloud's job runner (cron, or the AJAX/webcron fallbacks)
 * reaches it. Where that is not set up — a development container without a cron
 * daemon, a server whose cron entry broke — a queued move waits for ever and
 * looks exactly like one that was queued a minute ago. This gives the dashboard
 * what it needs to say so.
 *
 * "Not running" means the same as in Nextcloud's own admin overview (the setup
 * check for background jobs): the last run is more than an hour old.
 */
class BackgroundJobsStatus {

    /** Seconds since the last run after which the jobs are taken to have stopped. */
    public const STALL_AFTER = 3600;

    public function __construct(
        private IAppConfig $config,
        private ITimeFactory $time,
    ) {
    }

    /**
     * @param bool $somethingWaiting there are moves queued or running: a runner
     *        that has stopped is only worth mentioning when something needs it
     * @return array{mode: string, lastRun: ?int, stalled: bool}
     *         lastRun is a unix time, or null when the jobs have never run
     */
    public function describe(bool $somethingWaiting): array {
        try {
            $lastRun = $this->config->getValueInt('core', 'lastcron', 0);
            $mode = $this->config->getValueString('core', 'backgroundjobs_mode', 'ajax');
        } catch (\Throwable) {
            // A value stored as another type: better to say nothing than to
            // raise an alarm on a guess.
            return ['mode' => 'ajax', 'lastRun' => null, 'stalled' => false];
        }
        $overdue = $lastRun <= 0 || $this->time->getTime() - $lastRun > self::STALL_AFTER;

        return [
            'mode' => $mode,
            'lastRun' => $lastRun > 0 ? $lastRun : null,
            'stalled' => $somethingWaiting && $overdue,
        ];
    }
}
