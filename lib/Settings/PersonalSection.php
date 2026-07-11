<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Settings;

use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\AppFramework\QueryException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

    /**
     * @throws QueryException when the admin turned the personal view off
     *         (SettingsService::isPersonalViewEnabled()). \OC\Settings\
     *         Manager::getSections() specifically catches QueryException
     *         around this construction and silently drops the section from
     *         the sidebar on failure — the supported way for an optional
     *         settings section to hide itself, instead of always showing a
     *         link that leads to a "disabled" notice.
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $url,
        private SettingsService $settings,
    ) {
        if (!$this->settings->isPersonalViewEnabled()) {
            throw new QueryException('Personal view disabled by administrator');
        }
    }

    public function getID(): string {
        return 'share_audit_dashboard';
    }

    public function getName(): string {
        return $this->l->t('My shares audit');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->url->imagePath('share_audit_dashboard', 'app-dark.svg');
    }
}
