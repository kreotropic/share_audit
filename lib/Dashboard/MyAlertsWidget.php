<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Dashboard;

use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Dashboard widget: the current user's own public links that need attention
 * (no password / no expiration / sensitive file). Scoped per-user via $userId,
 * so it works for admins and regular users alike. Clicking an item opens the
 * personal "My shares audit" page.
 */
class MyAlertsWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget {

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private SecurityAnalyzerService $security,
    ) {
    }

    public function getId(): string {
        return 'share_audit_dashboard';
    }

    public function getTitle(): string {
        return $this->l10n->t('Shares needing attention');
    }

    public function getOrder(): int {
        return 20;
    }

    public function getIconClass(): string {
        return 'icon-category-security';
    }

    public function getIconUrl(): string {
        return $this->iconUrl();
    }

    public function getUrl(): ?string {
        return $this->personalUrl();
    }

    public function load(): void {
        // Rendered natively by the dashboard from getItems()/getItemsV2().
    }

    /**
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        $alerts = array_slice($this->security->getAlerts($userId), 0, $limit);
        $url = $this->personalUrl();
        $icon = $this->iconUrl();

        return array_map(function (array $alert) use ($url, $icon) {
            return new WidgetItem(
                $this->fileName($alert['path'] ?? ''),
                $this->subtitle($alert['issues'] ?? []),
                $url,
                $icon,
                (string)$alert['id'],
            );
        }, $alerts);
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        return new WidgetItems(
            $this->getItems($userId, $since, $limit),
            $this->l10n->t('None of your shares need attention.'),
        );
    }

    /**
     * @param array<int, array{code: string}> $issues
     */
    private function subtitle(array $issues): string {
        $labels = [
            'no_password' => $this->l10n->t('No password'),
            'no_expiration' => $this->l10n->t('No expiration'),
            'sensitive_file' => $this->l10n->t('Sensitive file'),
        ];
        $parts = array_map(fn (array $i) => $labels[$i['code']] ?? $i['code'], $issues);
        return implode(' · ', $parts);
    }

    private function fileName(string $path): string {
        $parts = array_values(array_filter(explode('/', $path)));
        return $parts === [] ? '—' : end($parts);
    }

    private function personalUrl(): string {
        return $this->urlGenerator->linkToRouteAbsolute(
            'settings.PersonalSettings.index',
            ['section' => 'share_audit_dashboard'],
        );
    }

    private function iconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('share_audit_dashboard', 'alert.svg'),
        );
    }
}
