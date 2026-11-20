<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

/**
 * SemVer 2.0 with an optional leading "v", extended to three or more numbers (`v1.0.40.39`). Numbers compare
 * left to right; when one version has more numbers and the rest are equal, it is the newer one (`1.0.40` <
 * `1.0.40.0`), like PHP version_compare(). Build metadata is accepted and ignored for ordering.
 */
final readonly class SemVer
{
    private const string PATTERN = '/^v?((?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*)){2,})'
        . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /**
     * @param list<int>    $numbers    three or more
     * @param list<string> $prerelease
     */
    private function __construct(
        public array $numbers,
        public array $prerelease,
    ) {
    }

    public static function parse(string $value): ?self
    {
        if (strlen($value) > 128 || 1 !== preg_match(self::PATTERN, $value, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $pre = null === ($m[2] ?? null) ? [] : explode('.', $m[2]);

        return new self(array_map(intval(...), explode('.', $m[1])), $pre);
    }

    public function isPrerelease(): bool
    {
        return [] !== $this->prerelease;
    }

    /** -1, 0 or 1 by SemVer precedence. */
    public function compare(self $other): int
    {
        $count = max(count($this->numbers), count($other->numbers));
        for ($i = 0; $i < $count; $i++) {
            if (! isset($this->numbers[$i])) {
                return -1;
            }
            if (! isset($other->numbers[$i])) {
                return 1;
            }
            if ($this->numbers[$i] !== $other->numbers[$i]) {
                return $this->numbers[$i] <=> $other->numbers[$i];
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
