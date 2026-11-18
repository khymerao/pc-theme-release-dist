<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

/**
 * SemVer 2.0 with an optional leading "v". Build metadata is accepted and ignored for ordering.
 */
final readonly class SemVer
{
    private const string PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /** @param list<string> $prerelease */
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public array $prerelease,
    ) {
    }

    public static function parse(string $value): ?self
    {
        if (strlen($value) > 128 || 1 !== preg_match(self::PATTERN, $value, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $pre = null === ($m[4] ?? null) ? [] : explode('.', $m[4]);

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $pre);
    }

    public function isPrerelease(): bool
    {
        return [] !== $this->prerelease;
    }

    /** -1, 0 or 1 by SemVer precedence. */
    public function compare(self $other): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            if ($this->$part !== $other->$part) {
                return $this->$part <=> $other->$part;
            }
        }
        if ($this->isPrerelease() !== $other->isPrerelease()) {
            return $this->isPrerelease() ? -1 : 1;
        }
        $count = max(count($this->prerelease), count($other->prerelease));
        for ($i = 0; $i < $count; $i++) {
            if (! isset($this->prerelease[$i])) {
                return -1;
            }
            if (! isset($other->prerelease[$i])) {
                return 1;
            }
            $result = self::compareIdentifier($this->prerelease[$i], $other->prerelease[$i]);
            if (0 !== $result) {
                return $result;
            }
        }

        return 0;
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);
        if ($aNumeric && $bNumeric) {
            return (int) $a <=> (int) $b;
        }
        if ($aNumeric !== $bNumeric) {
            return $aNumeric ? -1 : 1;
        }

        return strcmp($a, $b) <=> 0;
    }
}
