<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One queued move of a disabled account's files to another account — see
 * OrphanFileMoveService and Migration\Version0009Date... .
 *
 * Every property defaults to null for the reason spelled out in DeletedShare:
 * the Entity setter only adds a field to the INSERT when the new value differs
 * from the current one, so a domain-valid default (0, '') would silently drop
 * a NOT NULL column from the statement. `path` and the timestamps after
 * `createdAt` are genuinely nullable columns.
 */
class FileMove extends Entity {
    protected ?string $sourceUid = null;
    protected ?string $targetUid = null;
    protected ?string $scope = null;
    protected ?string $path = null;
    protected ?string $status = null;
    protected ?string $error = null;
    protected ?int $shareCount = null;
    protected ?string $requestedBy = null;
    protected ?int $createdAt = null;
    protected ?int $startedAt = null;
    protected ?int $finishedAt = null;

    public function __construct() {
        $this->addType('sourceUid', 'string');
        $this->addType('targetUid', 'string');
        $this->addType('scope', 'string');
        $this->addType('path', 'string');
        $this->addType('status', 'string');
        $this->addType('error', 'string');
        $this->addType('shareCount', 'integer');
        $this->addType('requestedBy', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('finishedAt', 'integer');
    }
}
