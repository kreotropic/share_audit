<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\Ack;
use OCA\ShareAuditDashboard\Db\AckMapper;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\IUserSession;

/**
 * Records and removes admin-accepted exceptions on security alerts — see
 * ROADMAP.md's G2 ("acknowledge/exception on alerts") and Migration\
 * Version0006Date... . Authorization is the caller's responsibility
 * (AckController guards admin-only, same as ShareActionController).
 */
class AckService {

    public function __construct(
        private AckMapper $mapper,
        private SecurityAnalyzerService $analyzer,
        private IManager $shareManager,
        private IUserSession $userSession,
        private ShareAuditLogger $auditLogger,
    ) {
    }

    /**
     * Record (or refresh) an exception for every code in $ruleCodes on
     * share $shareId — idempotent: acknowledging an already-acknowledged
     * pair just updates its note/actor/timestamp rather than erroring on
     * the unique (share_id, rule_code) index.
     *
     * $ruleCodes is normally every issue code currently shown on the
     * alert's row (AlertCard sends its whole `issues` list), so clicking
     * "Acknowledge" accepts the alert as it stands right now — a *new*
     * issue appearing on the same share later (e.g. it also starts
     * expiring soon) is unaffected and will still surface.
     *
     * @param string[] $ruleCodes
     * @throws \InvalidArgumentException on an unknown rule code or an empty list
     * @throws \OCP\Share\Exceptions\ShareNotFound if $shareId no longer exists
     */
    public function acknowledge(int $shareId, array $ruleCodes, ?string $note = null): void {
        $ruleCodes = $this->validated($ruleCodes);
        $share = $this->loadShare($shareId);
        $actor = $this->userSession->getUser()?->getUID() ?? '';
        $now = time();

        foreach ($ruleCodes as $ruleCode) {
            $existing = $this->mapper->findOneByShareAndRule($shareId, $ruleCode);
            $ack = $existing ?? new Ack();
            $ack->setShareId($shareId);
            $ack->setRuleCode($ruleCode);
            $ack->setAcknowledgedBy($actor);
            $ack->setAcknowledgedAt($now);
            $ack->setNote($note);
            $existing !== null ? $this->mapper->update($ack) : $this->mapper->insert($ack);
        }

        $this->auditLogger->logAcknowledge($shareId, $ruleCodes, false, $note);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
    }

    /**
     * Remove a previously recorded exception, restoring $ruleCodes to the
     * active alert list for $shareId.
     *
     * @param string[] $ruleCodes
     * @throws \InvalidArgumentException on an unknown rule code or an empty list
     * @throws \OCP\Share\Exceptions\ShareNotFound if $shareId no longer exists
     */
    public function unacknowledge(int $shareId, array $ruleCodes): void {
        $ruleCodes = $this->validated($ruleCodes);
        $share = $this->loadShare($shareId);

        foreach ($ruleCodes as $ruleCode) {
            $this->mapper->deleteByShareAndRule($shareId, $ruleCode);
        }

        $this->auditLogger->logAcknowledge($shareId, $ruleCodes, true);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
    }

    /**
     * @param string[] $ruleCodes
     * @return string[]
     */
    private function validated(array $ruleCodes): array {
        if ($ruleCodes === []) {
            throw new \InvalidArgumentException('ruleCodes must not be empty.');
        }
        foreach ($ruleCodes as $ruleCode) {
            if (!in_array($ruleCode, SecurityAnalyzerService::ISSUE_CODES, true)) {
                throw new \InvalidArgumentException('Unknown rule code: ' . $ruleCode);
            }
        }
        return $ruleCodes;
    }

    /**
     * @see ShareRemediationService::loadShare() for the convention (alerts
     * only cover shares served by the default provider; see
     * ShareProviderResolver::OCINTERNAL) and for why the validity check is off:
     * accepting the alert of an expired link must not delete the link.
     */
    private function loadShare(int $shareId): IShare {
        return $this->shareManager->getShareById(ShareProviderResolver::OCINTERNAL . ':' . $shareId, null, false);
    }
}
