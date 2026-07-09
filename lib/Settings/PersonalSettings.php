<?php

declare(strict_types=1);

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
        return new TemplateResponse('share_audit_dashboard', 'personal', [
            'enabled' => $this->settings->isPersonalViewEnabled(),
        ]);
    }

    public function getSection(): string {
        return 'share_audit_dashboard';
    }

    public function getPriority(): int {
        return 50;
    }
}
