<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\GitHub;

/**
 * Ties a downloaded zip to the commit it was asked for (audit N7), in plain PHP so it works without
 * ext-zip: the archive comment (git archive stores the commit id there) must be exactly the SHA, and every
 * entry must sit in one top-level folder whose name ends with "-" and a prefix (7 to 40 characters) of the
 * SHA, as GitHub names it ("owner-repo-1a2b3c4"). Anything unreadable fails.
 */
final class Zipball
{
    /** Shortest SHA prefix accepted at the end of the folder name. */
    public const int MIN_PREFIX = 7;
    private const string EOCD = "PK\x05\x06";
    private const string CENTRAL = "PK\x01\x02";
    /** End-of-central-directory record (22 bytes) plus the longest comment. */
    private const int TAIL = 22 + 65535;

    public static function matches(string $file, string $sha): bool
    {
        if (1 !== preg_match(GitHubClient::SHA_PATTERN, $sha) || ! is_file($file) || ! is_readable($file)) {
            return false;
        }
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return false;
        }
        try {
            $folders = self::topFolders($handle, $sha);
        } finally {
            fclose($handle);
        }
        if (null === $folders || 1 !== count($folders)) {
            return false;
        }

        return self::folderNamesCommit($folders[0], $sha);
    }

    public static function folderNamesCommit(string $folder, string $sha): bool
    {
        return 1 === preg_match('/-([0-9a-f]{' . self::MIN_PREFIX . ',40})$/', $folder, $m) && str_starts_with($sha, $m[1]);
    }

    /**
     * The distinct top-level folders of the archive, or null when the comment is not the SHA, the archive is
     * not readable, or an entry sits at the top level as a file.
     *
     * @param resource $handle
     * @return list<string>|null
     */
    private static function topFolders($handle, string $sha): ?array
    {
        $stat = fstat($handle);
        $size = false === $stat ? 0 : $stat['size'];
        if ($size < 22) {
            return null;
        }
        $tailLength = min($size, self::TAIL);
        fseek($handle, $size - $tailLength);
        $tail = (string) fread($handle, $tailLength);
        $at = strrpos($tail, self::EOCD);
        if (false === $at || strlen($tail) - $at < 22) {
            return null;
        }
        $eocd = unpack('ventries/Vsize/Voffset/vcomment', substr($tail, $at + 10, 12));
        if (! is_array($eocd)) {
            return null;
        }
        // The comment runs to the end of the file, and is exactly the commit SHA.
        if ($at + 22 + $eocd['comment'] !== strlen($tail) || substr($tail, $at + 22) !== $sha) {
            return null;
        }
        if (0 === $eocd['entries'] || 0xFFFF === $eocd['entries'] || 0xFFFFFFFF === $eocd['offset'] || $eocd['offset'] + $eocd['size'] > $size) {
            return null;
        }
        fseek($handle, $eocd['offset']);
        $directory = (string) fread($handle, $eocd['size']);

        return self::names($directory, $eocd['entries']);
    }

    /** @return list<string>|null */
    private static function names(string $directory, int $entries): ?array
    {
        $folders = [];
        $pos = 0;
        for ($i = 0; $i < $entries; $i++) {
            if (substr($directory, $pos, 4) !== self::CENTRAL || strlen($directory) < $pos + 46) {
                return null;
            }
            $lengths = unpack('vname/vextra/vcomment', substr($directory, $pos + 28, 6));
            if (! is_array($lengths)) {
                return null;
            }
            $name = substr($directory, $pos + 46, $lengths['name']);
            $slash = strpos($name, '/');
            if (false === $slash || 0 === $slash) {
                return null;
            }
            $folders[substr($name, 0, $slash)] = true;
            $pos += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
        }

        return array_map('strval', array_keys($folders));
    }
}
