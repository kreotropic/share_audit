<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * CRUD for the shareaudit_ack exceptions table — see Ack.
 *
 * A share that's later revoked (through this app, natively, or via occ)
 * leaves its ack rows behind unreferenced: they're harmless (they can never
 * match a future alert, since oc_share.id is never reused) and, in practice,
 * small in number — an admin acknowledges specific known-accepted links, not
 * the whole instance. Cleaning them up on delete was deliberately deferred,
 * the same call already made for the missing share_with/path indexes (see
 * ROADMAP.md's "Minor backlog") — revisit if evidence of real bloat shows up.
 */
class AckMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'shareaudit_ack', Ack::class);
    }

    /**
     * Every recorded exception — computeAlerts() loads this once per cache
     * miss (see SecurityAnalyzerService::CACHE_TTL) and indexes it in memory
     * rather than querying per alert.
     *
     * @return Ack[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        return $this->findEntities($qb);
    }

    /**
     * @throws MultipleObjectsReturnedException
     */
    public function findOneByShareAndRule(int $shareId, string $ruleCode): ?Ack {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('rule_code', $qb->createNamedParameter($ruleCode)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteByShareAndRule(int $shareId, string $ruleCode): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('rule_code', $qb->createNamedParameter($ruleCode)));
        $qb->executeStatement();
    }
}
