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
 * CRUD for the shareaudit_deleted retention table — see DeletedShare.
 */
class DeletedShareMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'shareaudit_deleted', DeletedShare::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): DeletedShare {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Take an entry out of the bin, saying whether this call is the one that
     * did. Of any number of callers that try at once, exactly one gets true:
     * the DELETE takes the row's lock, the others wait for it, and — once the
     * first has committed — find nothing left to delete. Inside a transaction
     * that rolls back, the entry is simply still there afterwards.
     *
     * This is what makes restore() safe to run twice at the same time; a
     * find() followed by delete() (QBMapper's, which never looks at how many
     * rows it removed) lets both through.
     */
    public function claim(int $id): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /**
     * @return DeletedShare[]
     */
    public function findPage(int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('deleted_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        if ($limit > 0) {
            $qb->setMaxResults($limit)->setFirstResult($offset);
        }
        return $this->findEntities($qb);
    }

    public function count(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->count('*'), 'cnt')->from($this->getTableName());
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * @return DeletedShare[] rows whose retention period has expired, for
     *         the daily purge job.
     */
    public function findExpired(int $now, int $limit = 1000): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('purge_after', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
