<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

    public function getForm(): TemplateResponse {
        return new TemplateResponse('share_audit_dashboard', 'admin');
    }

    public function getSection(): string {
        return 'share_audit_dashboard';
    }

    public function getPriority(): int {
        return 50;
    }
}
