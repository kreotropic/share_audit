<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\BackgroundJob;

use OCA\ShareAuditDashboard\Service\OrphanFileMoveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Carries out one queued move of a disabled account's files (see
 * OrphanFileMoveService). Added to the job list by the service, one job per
 * shareaudit_filemove row, so it is not listed in info.xml.
 */
class OrphanFileMoveJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private OrphanFileMoveService $fileMoves,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $this->fileMoves->run((int)($argument['id'] ?? 0));
    }
}
