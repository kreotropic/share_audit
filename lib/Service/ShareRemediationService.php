<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\Share\Exceptions\ShareNotFound;
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
        private ExpiryDefaultsService $expiryDefaults,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function applyPassword(int $id, string $password = ''): array {
        $share = $this->loadShare($id);
        $this->refuseIfExpired($share);
        $plain = $password !== '' ? $password : $this->passwordGenerator->generate();
        $share->setPassword($plain);
        $this->shareManager->updateShare($share);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
        return ['id' => $id, 'success' => true, 'action' => 'password', 'password' => $plain];
    }

    /**
     * Expire a share $days days from today. A $days of zero or less means
     * "the instance's default" (see ExpiryDefaultsService) — and where the
     * instance enforces a longest lifetime, a longer request is capped to it
     * rather than failing: IShareManager would reject the share otherwise,
     * and "as long as your sharing policy allows" is what the caller wants.
     *
     * @return array<string, mixed>
     */
    public function applyExpiration(int $id, int $days = 0): array {
        $share = $this->loadShare($id);
        $this->refuseIfExpired($share);
        $policy = $this->expiryDefaults->forShareType($share->getShareType());
        $days = $days > 0 ? $days : $policy['days'];
        if ($policy['maxDays'] !== null) {
            $days = min($days, $policy['maxDays']);
        }
        $date = (new \DateTime('today'))->modify('+' . $days . ' days');
        $share->setExpirationDate($date);
        $this->shareManager->updateShare($share);
        $this->analyzer->invalidate($share->getShareOwner(), $share->getSharedBy());
        return ['id' => $id, 'success' => true, 'action' => 'expiration', 'expiration' => $date->format('Y-m-d')];
    }

    /**
     * Revoke a share. Works on an expired one too, which is exactly what an
     * audit tool is asked to clean up.
     *
     * Revoking is idempotent: a share that is already gone (revoked a moment
     * ago, or removed by Nextcloud's own expiry job) is the state the caller
     * wanted, so it is reported as done rather than as a failure.
     *
     * @return array<string, mixed>
     */
    public function revoke(int $id): array {
        try {
            $share = $this->loadShare($id);
        } catch (ShareNotFound) {
            return ['id' => $id, 'success' => true, 'action' => 'revoke', 'alreadyGone' => true];
        }
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
     * Load a share by its numeric oc_share id. Alerts only cover public
     * links and native group shares, both always served by the default
     * provider; see ShareProviderResolver::OCINTERNAL, the shared source
     * of truth this assumption is pinned against.
     *
     * Loaded WITHOUT Nextcloud's validity check ($onlyValid = false). With it,
     * asking for an expired share makes Nextcloud delete that share and then
     * throw ShareNotFound, so merely looking at one (to revoke it, to change
     * it, even to check who owns it) destroyed it and reported a failure.
     * Expired links are exactly what the "already expired" alert lists, and the
     * same check also hides a link whose owner may no longer create links.
     */
    public function loadShare(int $id): IShare {
        return $this->shareManager->getShareById(ShareProviderResolver::OCINTERNAL . ':' . $id, null, false);
    }

    /**
     * A change to an expired share is refused. IShareManager::updateShare() runs
     * the validity check that deletes an expired share, so letting it through
     * would destroy the share while reporting an error.
     *
     * @throws ShareExpiredException
     */
    private function refuseIfExpired(IShare $share): void {
        if ($share->isExpired()) {
            throw new ShareExpiredException();
        }
    }

    /**
     * Why an action failed, when the client can act on it: 'expired' (the share
     * can only be revoked) or 'not_found' (it is gone). Null for anything else,
     * which is logged and reported generically.
     */
    public static function failureReason(\Throwable $e): ?string {
        return match (true) {
            $e instanceof ShareExpiredException => 'expired',
            $e instanceof ShareNotFound => 'not_found',
            default => null,
        };
    }
}
