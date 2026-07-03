<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

/**
 * Builds exportable reports (currently CSV) from normalized share rows
 * produced by ShareCollectorService.
 */
class ReportService {

    /** UTF-8 BOM so spreadsheet apps (Excel) detect the encoding correctly. */
    private const BOM = "\xEF\xBB\xBF";

    private const HEADERS = [
        'Type', 'Path', 'Owner', 'Initiator', 'Recipient',
        'Permissions', 'Created', 'Expires', 'Password', 'Token',
    ];

    /**
     * Render normalized share rows as a CSV document.
     *
     * @param array<int, array<string, mixed>> $rows rows from ShareCollectorService
     * @return string the full CSV payload (with BOM)
     */
    public function buildCsv(array $rows): string {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::HEADERS);

        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['category'] ?? '',
                $row['path'] ?? '',
                $row['owner'] ?? '',
                $row['initiator'] ?? '',
                $this->recipient($row),
                implode(' + ', $row['permissionLabels'] ?? []),
                !empty($row['created']) ? date('Y-m-d H:i', (int)$row['created']) : '',
                $row['expiration'] ?? '',
                !empty($row['hasPassword']) ? 'yes' : 'no',
                $row['token'] ?? '',
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return self::BOM . $csv;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recipient(array $row): string {
        $recipient = (string)($row['recipient'] ?? '');
        if ($recipient !== '') {
            return $recipient;
        }
        return ($row['category'] ?? '') === 'link' ? '(public)' : '';
    }
}
