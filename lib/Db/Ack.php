<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An admin-accepted exception for one (share, rule) pair — see
 * Migration\Version0006Date... and AckService. Property names are camelCase;
 * QBMapper/Entity map them to the snake_case columns created by that
 * migration.
 */
class Ack extends Entity {
    protected ?int $shareId = null;
    protected ?string $ruleCode = null;
    protected ?string $acknowledgedBy = null;
    protected ?int $acknowledgedAt = null;
    protected ?string $note = null;

    public function __construct() {
        $this->addType('shareId', 'integer');
        $this->addType('ruleCode', 'string');
        $this->addType('acknowledgedBy', 'string');
        $this->addType('acknowledgedAt', 'integer');
        $this->addType('note', 'string');
    }
}
