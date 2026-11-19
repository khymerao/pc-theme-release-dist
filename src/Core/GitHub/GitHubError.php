<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\GitHub;

/**
 * A failed GitHub call. Carries only the kind and the HTTP status: never a URL, header or body.
 */
final class GitHubError extends \RuntimeException
{
    public function __construct(public readonly GitHubErrorKind $kind, public readonly int $httpStatus)
    {
        parent::__construct($kind->value, $httpStatus);
    }
}
