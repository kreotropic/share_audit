<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
        'Permissions', 'Created', 'Expires', 'Password',
    ];

    /**
     * Render normalized share rows as a CSV document.
     *
     * The Token column is opt-in: a public link's token is a bare credential
     * (anyone holding it can open the share without logging in), so it is
     * only written when the caller explicitly asks for it — the export UI
     * must warn the admin before setting this.
     *
     * @param array<int, array<string, mixed>> $rows rows from ShareCollectorService
     * @return string the full CSV payload (with BOM)
     */
    public function buildCsv(array $rows, bool $includeTokens = false): string {
        $fh = fopen('php://temp', 'r+');
        $headers = self::HEADERS;
        if ($includeTokens) {
            $headers[] = 'Token';
        }
        fputcsv($fh, $headers, escape: '');

        foreach ($rows as $row) {
            $line = [
                $this->cell($row['category'] ?? ''),
                $this->cell($row['path'] ?? ''),
                $this->cell($row['owner'] ?? ''),
                $this->cell($row['initiator'] ?? ''),
                $this->cell($this->recipient($row)),
                implode(' + ', $row['permissionLabels'] ?? []),
                !empty($row['created']) ? date('Y-m-d H:i', (int)$row['created']) : '',
                $row['expiration'] ?? '',
                !empty($row['hasPassword']) ? 'yes' : 'no',
            ];
            if ($includeTokens) {
                $line[] = $this->cell($row['token'] ?? '');
            }
            fputcsv($fh, $line, escape: '');
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

    /**
     * Neutralize spreadsheet formula injection: a cell whose value starts
     * with a character a spreadsheet app treats as a formula prefix
     * (=, +, -, @, tab, CR) gets a leading apostrophe, which forces it to be
     * read as plain text instead of executed when the CSV is opened.
     */
    private function cell(string $value): string {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
