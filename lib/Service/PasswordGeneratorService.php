<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCP\Security\ISecureRandom;

/**
 * Generates strong random passwords for public links, mixed enough to satisfy
 * typical Nextcloud password policies (upper, lower, digit, symbol).
 */
class PasswordGeneratorService {

    private const SYMBOLS = '!@#$%&*?';
    private const LENGTH = 14;

    public function __construct(
        private ISecureRandom $random,
    ) {
    }

    public function generate(): string {
        // Guarantee at least one character from each class.
        $chars = [
            $this->random->generate(1, ISecureRandom::CHAR_UPPER),
            $this->random->generate(1, ISecureRandom::CHAR_LOWER),
            $this->random->generate(1, ISecureRandom::CHAR_DIGITS),
            $this->random->generate(1, self::SYMBOLS),
        ];

        $all = ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER
            . ISecureRandom::CHAR_DIGITS . self::SYMBOLS;
        for ($i = count($chars); $i < self::LENGTH; $i++) {
            $chars[] = $this->random->generate(1, $all);
        }

        // Shuffle so the guaranteed characters are not always in front.
        shuffle($chars);
        return implode('', $chars);
    }
}
