<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A share captured just before it was actually deleted from oc_share — see
 * SoftDeleteService. Property names are camelCase; QBMapper/Entity map them
 * to the snake_case columns created by Migration\Version0004Date... .
 */
class DeletedShare extends Entity {
    protected int $originalShareId = 0;
    protected int $shareType = 0;
    protected ?string $shareWith = null;
    protected string $uidOwner = '';
    protected ?string $uidInitiator = null;
    protected string $itemType = '';
    protected ?int $fileSource = null;
    protected ?string $fileTarget = null;
    protected int $permissions = 0;
    protected ?string $token = null;
    protected ?string $password = null;
    protected ?string $shareName = null;
    protected ?string $expiration = null;
    protected ?int $stime = null;
    protected int $deletedAt = 0;
    protected ?string $deletedBy = null;
    protected int $purgeAfter = 0;

    public function __construct() {
        $this->addType('originalShareId', 'integer');
        $this->addType('shareType', 'integer');
        $this->addType('shareWith', 'string');
        $this->addType('uidOwner', 'string');
        $this->addType('uidInitiator', 'string');
        $this->addType('itemType', 'string');
        $this->addType('fileSource', 'integer');
        $this->addType('fileTarget', 'string');
        $this->addType('permissions', 'integer');
        $this->addType('token', 'string');
        $this->addType('password', 'string');
        $this->addType('shareName', 'string');
        $this->addType('expiration', 'string');
        $this->addType('stime', 'integer');
        $this->addType('deletedAt', 'integer');
        $this->addType('deletedBy', 'string');
        $this->addType('purgeAfter', 'integer');
    }
}
