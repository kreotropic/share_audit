<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the app's configurable security-alert rules:
 * the list of sensitive file extensions and which alert rules are active.
 * Stored as Nextcloud app config values.
 */
class SettingsService {

    public const RULES = [
        'no_password', 'no_expiration', 'sensitive_file',
        'group_share_editable', 'public_upload',
    ];

    private const DEFAULT_EXTENSIONS = [
        'xlsx', 'xls', 'docx', 'doc', 'pdf', 'csv', 'sql', 'bak', 'pptx', 'ppt',
    ];

    /** Default threshold (member count) above which an editable group share is flagged. */
    private const DEFAULT_GROUP_SHARE_MIN_MEMBERS = 20;

    /** Default days a revoked share stays in the recycle bin before being purged. */
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private IAppConfig $config,
    ) {
    }

    /**
     * @return string[] lowercase extensions, without the dot
     */
    public function getSensitiveExtensions(): array {
        $raw = $this->config->getValueString(
            Application::APP_ID,
            'sensitive_extensions',
            implode(',', self::DEFAULT_EXTENSIONS),
        );
        return $this->parseExtensions($raw);
    }

    /**
     * Whether a given alert rule is currently enabled (all default to on).
     */
    public function isRuleEnabled(string $rule): bool {
        return $this->config->getValueString(Application::APP_ID, 'rule_' . $rule, 'yes') === 'yes';
    }

    /**
     * Member count above which an editable/resharable group share is
     * flagged by the `group_share_editable` rule.
     */
    public function getGroupShareMinMembers(): int {
        $value = $this->config->getValueInt(
            Application::APP_ID,
            'group_share_min_members',
            self::DEFAULT_GROUP_SHARE_MIN_MEMBERS,
        );
        return max(1, $value);
    }

    /**
     * Whether the personal "My shares audit" page (Settings → Personal) and
     * its dashboard widget are available to users. Defaults to on; an admin
     * who wants sharing audits to stay an admin-only concern can turn it off
     * instance-wide.
     */
    public function isPersonalViewEnabled(): bool {
        return $this->config->getValueString(Application::APP_ID, 'personal_view_enabled', 'yes') === 'yes';
    }

    /**
     * Days a revoked share stays in the recycle bin (Deleted shares tab)
     * before PurgeDeletedSharesJob permanently removes it.
     */
    public function getRetentionDays(): int {
        $value = $this->config->getValueInt(
            Application::APP_ID,
            'retention_days',
            self::DEFAULT_RETENTION_DAYS,
        );
        return max(1, $value);
    }

    /**
     * Full settings payload for the frontend.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array {
        $rules = [];
        foreach (self::RULES as $rule) {
            $rules[$rule] = $this->isRuleEnabled($rule);
        }
        return [
            'sensitiveExtensions' => implode(', ', $this->getSensitiveExtensions()),
            'groupShareMinMembers' => $this->getGroupShareMinMembers(),
            'rules' => $rules,
            'personalViewEnabled' => $this->isPersonalViewEnabled(),
            'retentionDays' => $this->getRetentionDays(),
        ];
    }

    /**
     * Persist settings from the admin form.
     *
     * @param array<string, bool> $rules rule code => enabled
     */
    public function saveSettings(
        string $extensions,
        array $rules,
        bool $personalViewEnabled = true,
        int $groupShareMinMembers = self::DEFAULT_GROUP_SHARE_MIN_MEMBERS,
        int $retentionDays = self::DEFAULT_RETENTION_DAYS,
    ): void {
        $this->config->setValueString(
            Application::APP_ID,
            'sensitive_extensions',
            implode(',', $this->parseExtensions($extensions)),
        );
        $this->config->setValueInt(Application::APP_ID, 'group_share_min_members', max(1, $groupShareMinMembers));
        foreach (self::RULES as $rule) {
            $enabled = !empty($rules[$rule]);
            $this->config->setValueString(Application::APP_ID, 'rule_' . $rule, $enabled ? 'yes' : 'no');
        }
        $this->config->setValueString(
            Application::APP_ID,
            'personal_view_enabled',
            $personalViewEnabled ? 'yes' : 'no',
        );
        $this->config->setValueInt(Application::APP_ID, 'retention_days', max(1, $retentionDays));
    }

    /**
     * Normalize a comma/space separated extension list into lowercase tokens.
     *
     * @return string[]
     */
    private function parseExtensions(string $raw): array {
        $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
        $parts = array_map(static fn (string $e) => ltrim(trim($e), '.'), $parts);
        return array_values(array_unique(array_filter($parts, static fn (string $e) => $e !== '')));
    }
}
