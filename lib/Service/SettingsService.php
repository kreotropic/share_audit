<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the app's configurable security-alert rules:
 * the list of sensitive file extensions and which alert rules are active.
 * Stored as Nextcloud app config values.
 */
class SettingsService {

    public const RULES = ['no_password', 'no_expiration', 'sensitive_file'];

    private const DEFAULT_EXTENSIONS = [
        'xlsx', 'xls', 'docx', 'doc', 'pdf', 'csv', 'sql', 'bak', 'pptx', 'ppt',
    ];

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
            'rules' => $rules,
        ];
    }

    /**
     * Persist settings from the admin form.
     *
     * @param array<string, bool> $rules rule code => enabled
     */
    public function saveSettings(string $extensions, array $rules): void {
        $this->config->setValueString(
            Application::APP_ID,
            'sensitive_extensions',
            implode(',', $this->parseExtensions($extensions)),
        );
        foreach (self::RULES as $rule) {
            $enabled = !empty($rules[$rule]);
            $this->config->setValueString(Application::APP_ID, 'rule_' . $rule, $enabled ? 'yes' : 'no');
        }
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
