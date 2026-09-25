<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

/**
 * The share has expired, so a change to it (a password, a new expiration) is
 * refused. Revoking it is still fine.
 *
 * Refusing is the safe answer: handing an expired share to IShareManager::
 * updateShare() makes Nextcloud delete it on the spot, so the caller would be
 * told "failed" about a share that is in fact gone.
 */
class ShareExpiredException extends \RuntimeException {

    public function __construct() {
        parent::__construct('The share has expired and can only be revoked.');
    }
}
