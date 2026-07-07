<?php

declare(strict_types=1);

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
        return ['id' => $id, 'success' => true, 'action' => 'expiration', 'expiration' => $date->format('Y-m-d')];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(int $id): array {
        $this->shareManager->deleteShare($this->loadShare($id));
        return ['id' => $id, 'success' => true, 'action' => 'revoke'];
    }

    /**
     * The uid that owns the shared item, for ownership checks.
     */
    public function ownerOf(int $id): string {
        return $this->loadShare($id)->getShareOwner();
    }

    /**
     * Load a share by its numeric oc_share id. Alerts only cover public links,
     * always served by the default ("ocinternal") provider.
     */
    public function loadShare(int $id): IShare {
        return $this->shareManager->getShareById('ocinternal:' . $id);
    }
}
