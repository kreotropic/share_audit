<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Settings;

use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {

    public function __construct(
        private SettingsService $settings,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('share_audit_dashboard', 'personal');
    }

    /**
     * Returning null (rather than the section id) drops this settings entry
     * from its section entirely, so the "My shares audit" nav link itself
     * disappears from Settings → Personal when the admin has turned the
     * feature off — not just its content — see ISettings::getSection()'s
     * docblock ("null to not show the setting").
     */
    public function getSection(): ?string {
        return $this->settings->isPersonalViewEnabled() ? 'share_audit_dashboard' : null;
    }

    public function getPriority(): int {
        return 50;
    }
}
