<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

    public function __construct(
        private IL10N $l,
        private IURLGenerator $url,
    ) {
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
