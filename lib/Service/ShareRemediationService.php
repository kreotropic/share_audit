<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Applies remediation actions to a single share (set/generate a password, set
 * an expiration, revoke). Authorization is the caller's responsibility — the
 * admin controller checks admin rights, the personal controller checks that the
 * share belongs to the current user.
 */
class ShareRemediationService {

    public function __construct(
        private IManager $shareManager,
        private PasswordGeneratorService $passwordGenerator,
        private ShareAuditLogger $auditLogger,
        private SecurityAnalyzerService $analyzer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function applyPassword(int $id, string $password = ''): array {
        $share = $this->loadShare($id);
        $plain = $password !== '' ? $password : $this->passwordGenerator->generate();
        $share->setPassword($plain);
        $this->shareManager->updateShare($share);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
        return ['id' => $id, 'success' => true, 'action' => 'password', 'password' => $plain];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyExpiration(int $id, int $days): array {
        $days = max(1, $days);
        $date = (new \DateTime('today'))->modify('+' . $days . ' days');
        $share = $this->loadShare($id);
        $share->setExpirationDate($date);
        $this->shareManager->updateShare($share);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
        return ['id' => $id, 'success' => true, 'action' => 'expiration', 'expiration' => $date->format('Y-m-d')];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(int $id): array {
        $share = $this->loadShare($id);
        $this->shareManager->deleteShare($share);
        $this->auditLogger->logRevoke([[
            'id' => $id,
            'share_type' => $share->getShareType(),
            'uid_owner' => $share->getShareOwner(),
        ]]);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
        return ['id' => $id, 'success' => true, 'action' => 'revoke'];
    }

    /**
     * Whether $uid is allowed to manage share $id: either the owner of the
     * shared item, or the person who created this particular share (native
     * Nextcloud semantics — a share's owner and its creator can differ when
     * one is shared on a folder someone else owns).
     */
    public function isAccessibleBy(int $id, string $uid): bool {
        $share = $this->loadShare($id);
        return $share->getShareOwner() === $uid || $share->getSharedBy() === $uid;
    }

    /**
     * Load a share by its numeric oc_share id. Alerts only cover public links,
     * always served by the default ("ocinternal") provider.
     */
    public function loadShare(int $id): IShare {
        return $this->shareManager->getShareById('ocinternal:' . $id);
    }
}
