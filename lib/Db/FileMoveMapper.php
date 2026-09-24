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
 * CRUD for the shareaudit_filemove queue — see FileMove.
 */
class FileMoveMapper extends QBMapper {

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'shareaudit_filemove', FileMove::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): FileMove {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * The newest $limit moves, for the dashboard's list.
     *
     * @return FileMove[]
     */
    public function findRecent(int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'DESC')
            ->setMaxResults(max(1, $limit));
        return $this->findEntities($qb);
    }

    /**
     * Moves that are still queued or running for any of $uids, which is what
     * decides whether a new request for the same account or path has to wait.
     *
     * @param string[] $uids
     * @return FileMove[]
     */
    public function findActiveForSources(array $uids): array {
        if ($uids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('source_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                [self::STATUS_QUEUED, self::STATUS_RUNNING],
                IQueryBuilder::PARAM_STR_ARRAY,
            )));
        return $this->findEntities($qb);
    }

    /**
     * Take a queued move for a job to run, saying whether this call is the
     * one that did: of any number of workers that pick the same row up, only
     * one changes it from queued (the UPDATE takes the row's lock and the
     * others then find the status already changed), so a move can never run
     * twice — and a second run of a move that has already happened would
     * fail on a path that is no longer there.
     */
    public function claim(int $id, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(self::STATUS_RUNNING))
            ->set('started_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)));
        return $qb->executeStatement() === 1;
    }

    /**
     * Record how a move ended.
     */
    public function finish(int $id, string $status, ?string $error, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter($status))
            ->set('error', $error === null
                ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                : $qb->createNamedParameter($error))
            ->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Give up on moves that have been "running" since before $startedBefore:
     * the worker that took them died (a fatal error, a killed cron run) and
     * nothing else will ever change their status, which would otherwise block
     * any new request for the same account forever.
     *
     * @return int how many were marked failed
     */
    public function failStale(int $startedBefore, string $error, int $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(self::STATUS_FAILED))
            ->set('error', $qb->createNamedParameter($error))
            ->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING)))
            ->andWhere($qb->expr()->lt('started_at', $qb->createNamedParameter($startedBefore, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }
}
