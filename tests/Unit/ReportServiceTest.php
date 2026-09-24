<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CSV export: which columns it has and in what order, that the
 * share's own name is in it (the filtered export is only useful for a search by
 * name if it shows the name), and that the free text in it cannot run in the
 * admin's spreadsheet.
 */
class ReportServiceTest extends TestCase {

    private const BASE_COLUMNS = [
        'Type', 'Path', 'Owner', 'Initiator', 'Recipient',
        'Permissions', 'Created', 'Expires', 'Password', 'Share name',
    ];

    private ReportService $report;

    protected function setUp(): void {
        $this->report = new ReportService();
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function row(array $override = []): array {
        return $override + [
            'category' => 'link',
            'path' => '/Finance/Q3.xlsx',
            'owner' => 'alice',
            'initiator' => 'alice',
            'recipient' => '',
            'permissionLabels' => ['Read'],
            'created' => 1_700_000_000,
            'expiration' => null,
            'hasPassword' => false,
            'token' => 'abc123',
            'name' => null,
        ];
    }

    /**
     * The CSV as a list of rows, header first.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, string>>
     */
    private function parse(array $rows, bool $includeTokens = false): array {
        $csv = $this->report->buildCsv($rows, $includeTokens);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'spreadsheets need the BOM to read it as UTF-8');

        $lines = [];
        foreach (explode("\n", trim(substr($csv, 3))) as $line) {
            $lines[] = str_getcsv($line, ',', '"', '');
        }
        return $lines;
    }

    public function testAPublicLinkHasNoRecipientAndSaysSo(): void {
        [, $line] = $this->parse([$this->row(['recipient' => ''])]);

        $this->assertSame('(public)', $line[4]);
    }

    /**
     * A Talk share whose conversation has no name, or whose token was redacted
     * for the export: an empty cell would read as "no recipient".
     */
    public function testATalkShareWithNoNameToShowIsMarkedNotLeftBlank(): void {
        [, $line] = $this->parse([$this->row(['category' => 'talk', 'recipient' => ''])]);

        $this->assertSame('(unnamed conversation)', $line[4]);
    }

    public function testATalkShareWithANameShowsIt(): void {
        [, $line] = $this->parse([$this->row(['category' => 'talk', 'recipient' => 'Equipa de Marketing'])]);

        $this->assertSame('Equipa de Marketing', $line[4]);
    }

    public function testTheShareNameIsAColumnOfItsOwn(): void {
        [$header] = $this->parse([$this->row()]);

        $this->assertSame(self::BASE_COLUMNS, $header);
    }

    public function testNoExistingColumnMoves(): void {
        // The first nine columns are what the export had before the name was
        // added; a script reading them by position must keep working.
        [$header] = $this->parse([$this->row()]);

        $this->assertSame(
            ['Type', 'Path', 'Owner', 'Initiator', 'Recipient', 'Permissions', 'Created', 'Expires', 'Password'],
            array_slice($header, 0, 9),
        );
    }

    public function testTheTokenStaysTheLastColumn(): void {
        [$header, $line] = $this->parse([$this->row()], true);

        $this->assertSame([...self::BASE_COLUMNS, 'Token'], $header);
        $this->assertSame('abc123', end($line));
    }

    public function testTheNameOfAShareIsWrittenInItsColumn(): void {
        [, $line] = $this->parse([$this->row(['name' => 'Q3 Budget — external review'])]);

        $this->assertSame('Q3 Budget — external review', $line[9]);
    }

    public function testAShareWithoutANameHasAnEmptyCell(): void {
        [, $line] = $this->parse([$this->row(['name' => null])]);

        $this->assertSame('', $line[9]);
        $this->assertCount(count(self::BASE_COLUMNS), $line);
    }

    public function testAnOldRowWithoutTheKeyStillExports(): void {
        $row = $this->row();
        unset($row['name']);

        [, $line] = $this->parse([$row]);

        $this->assertSame('', $line[9]);
    }

    /**
     * Anybody who can share a file can type anything as its name, and the admin
     * opens the export in a spreadsheet.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function formulaPrefixes(): iterable {
        yield 'equals' => ['=HYPERLINK("http://evil.example","open me")'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-2+3'];
        yield 'at' => ['@SUM(A1:A9)'];
    }

    /**
     * @dataProvider formulaPrefixes
     */
    public function testAFormulaInAShareNameIsNotExecuted(string $name): void {
        [, $line] = $this->parse([$this->row(['name' => $name])]);

        $this->assertSame("'" . $name, $line[9]);
    }

    public function testAPlainNameIsLeftAsItIs(): void {
        [, $line] = $this->parse([$this->row(['name' => 'Budget = 2026'])]);

        $this->assertSame('Budget = 2026', $line[9]);
    }

    public function testACommaOrQuoteInANameStaysOneCell(): void {
        [, $line] = $this->parse([$this->row(['name' => 'Smith, "Q3" plan'])]);

        $this->assertSame('Smith, "Q3" plan', $line[9]);
        $this->assertCount(count(self::BASE_COLUMNS), $line);
    }
}
