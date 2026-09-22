<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Controller;

use OCA\ShareAuditDashboard\Service\AccessService;
use OCA\ShareAuditDashboard\Service\AckService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only endpoints for accepting (or undoing) a security-alert
 * exception — see AckService and ROADMAP.md's G2.
 */
class AckController extends AdminController {

    /** @see ShareActionController::GENERIC_ERROR */
    private const GENERIC_ERROR = 'The action could not be completed.';

    /** @see ShareActionController::BULK_MAX_IDS */
    private const BULK_MAX_ITEMS = 500;

    public function __construct(
        string $appName,
        IRequest $request,
        private AckService $ackService,
        AccessService $access,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request, $access);
    }

    /**
     * POST /api/alerts/{id}/ack — accept $ruleCodes (every issue code
     * currently shown on that alert row) as an exception, with an optional
     * free-text $note.
     *
     * @param string[] $ruleCodes
     */
    public function acknowledge(int $id, array $ruleCodes = [], string $note = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        try {
            $this->ackService->acknowledge($id, $ruleCodes, $note !== '' ? $note : null);
            return new JSONResponse(['id' => $id, 'success' => true, 'acknowledged' => $ruleCodes]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->fail($id, $e);
        }
    }

    /**
     * DELETE /api/alerts/{id}/ack — undo a previously accepted exception for
     * $ruleCodes, restoring them to the active alert list.
     *
     * @param string[] $ruleCodes
     */
    public function unacknowledge(int $id, array $ruleCodes = []): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        try {
            $this->ackService->unacknowledge($id, $ruleCodes);
            return new JSONResponse(['id' => $id, 'success' => true, 'unacknowledged' => $ruleCodes]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->fail($id, $e);
        }
    }

    /**
     * POST /api/alerts/bulk-ack — acknowledge many alerts at once.
     *
     * Unlike ShareActionController::bulk(), one shared action can't apply
     * uniformly here: each alert in a selection can carry a different set
     * of issues, so each $items entry names its own `ruleCodes` — normally
     * every issue code that alert currently shows (SecurityAlerts.vue sends
     * each selected row's own `issues`), the same "accept it as it stands"
     * semantics as the single-alert action. $note, if given, applies to
     * every item in the batch.
     *
     * @param array<int, array{id: int, ruleCodes: string[]}> $items
     */
    public function bulkAcknowledge(array $items = [], string $note = ''): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if (count($items) > self::BULK_MAX_ITEMS) {
            return new JSONResponse(
                ['message' => 'Too many items in one request (max ' . self::BULK_MAX_ITEMS . ').'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $results = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $ruleCodes = array_values(array_filter((array)($item['ruleCodes'] ?? []), 'is_string'));
            try {
                $this->ackService->acknowledge($id, $ruleCodes, $note !== '' ? $note : null);
                $results[] = ['id' => $id, 'success' => true];
            } catch (\Throwable $e) {
                $this->logger->warning('Bulk acknowledge failed', ['id' => $id, 'exception' => $e]);
                $results[] = ['id' => $id, 'success' => false, 'error' => self::GENERIC_ERROR];
            }
        }

        $ok = count(array_filter($results, static fn ($r) => $r['success']));
        return new JSONResponse([
            'action' => 'acknowledge',
            'total' => count($results),
            'succeeded' => $ok,
            'failed' => count($results) - $ok,
            'results' => $results,
        ]);
    }

    private function fail(int $id, \Throwable $e): JSONResponse {
        $this->logger->warning('Acknowledge action failed', ['id' => $id, 'exception' => $e]);
        return new JSONResponse(
            ['id' => $id, 'success' => false, 'error' => self::GENERIC_ERROR],
            Http::STATUS_BAD_REQUEST,
        );
    }
}
