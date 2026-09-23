<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
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
    // NOT NULL DB columns (see the migration) whose PHP defaults are
    // deliberately nullable rather than a valid domain value (0, ''): the
    // Entity setter only marks a field "updated" — and so included in the
    // QBMapper::insert() column list — when the new value differs from the
    // current one. A domain-valid default (e.g. 0, which is also the real
    // share_type for a user share) makes setShareType(0) a no-op as far as
    // dirty-tracking is concerned, so the column is silently omitted from
    // the INSERT and the DB rejects it as missing a NOT NULL value with no
    // default. Every one of these fields is unconditionally set in both
    // SoftDeleteService::captureShare()/captureRow() before insert(), so a
    // null default here never reaches the database.
    protected ?int $originalShareId = null;
    protected ?int $shareType = null;
    protected ?string $shareWith = null;
    protected ?string $uidOwner = null;
    protected ?string $uidInitiator = null;
    protected ?string $itemType = null;
    protected ?int $fileSource = null;
    protected ?string $fileTarget = null;
    protected ?int $permissions = null;
    protected ?string $token = null;
    protected ?string $password = null;
    protected ?string $shareName = null;
    protected ?string $expiration = null;
    protected ?int $stime = null;
    protected ?int $deletedAt = null;
    protected ?string $deletedBy = null;
    protected ?int $purgeAfter = null;
    // Whether the shared file/folder still resolved when this row was
    // captured — see issue #21 (the owner's account can be gone AND the
    // file separately deleted; restoring the DB row never brings a file
    // back). Nullable only for the same dirty-tracking reason as every
    // other field above, even though it is a real bool, not "unknown".
    protected ?bool $sourceExistsAtDeletion = null;

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
        $this->addType('sourceExistsAtDeletion', 'boolean');
    }
}
