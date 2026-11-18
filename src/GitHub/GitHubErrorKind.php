<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

enum GitHubErrorKind: string
{
    case Unauthorized = 'github_unauthorized';
    case NotFound = 'github_not_found';
    case Forbidden = 'github_forbidden';
    case RateLimited = 'github_rate_limited';
    case Server = 'github_server';
    case Transport = 'github_unreachable';
    case BadResponse = 'github_bad_response';
    case ForeignRedirect = 'github_foreign_redirect';
    case NoToken = 'github_no_token';
}
