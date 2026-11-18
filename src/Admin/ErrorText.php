<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\GitHub\GitHubErrorKind;

/**
 * Plain-language text for stored per-repository error and warning codes (spec call 7).
 */
final class ErrorText
{
    /** @param int $rateLimitedUntil When GitHub's rate limit resets (0: unknown). */
    public static function forRepo(string $code, string $detail, string $repo, int $rateLimitedUntil = 0): string
    {
        if ('branch_missing' === $code) {
            /* translators: %s: branch name. */
            return sprintf(__('Error: branch %s not found on GitHub. Nothing is offered.', 'pc-theme-release'), $detail);
        }

        if (GitHubErrorKind::RateLimited->value === $code && $rateLimitedUntil > 0) {
            /* translators: %s: date and time the rate limit resets. */
            return sprintf(__('Error: GitHub rate limit reached; rate-limited until %s. The plugin waits until then.', 'pc-theme-release'), wp_date('Y-m-d H:i', $rateLimitedUntil));
        }

        return match (GitHubErrorKind::tryFrom($code)) {
            GitHubErrorKind::Unauthorized => __('Error: the GitHub token expired or was revoked.', 'pc-theme-release'),
            /* translators: %s: owner/repo. */
            GitHubErrorKind::NotFound => sprintf(__('Error: the token cannot see repository %s (check the token\'s repository access).', 'pc-theme-release'), $repo),
            GitHubErrorKind::RateLimited => __('Error: GitHub rate limit reached. The plugin waits until it resets.', 'pc-theme-release'),
            GitHubErrorKind::Forbidden => __('Error: GitHub refused the request (403). Check the token permissions.', 'pc-theme-release'),
            GitHubErrorKind::Server => __('Error: GitHub answered with a server error. It is retried at the next check.', 'pc-theme-release'),
            GitHubErrorKind::Transport => __('Error: GitHub could not be reached from this server.', 'pc-theme-release'),
            GitHubErrorKind::NoToken => __('Error: no usable GitHub token.', 'pc-theme-release'),
            default => __('Error: GitHub returned an unexpected answer.', 'pc-theme-release'),
        };
    }

    public static function warning(string $code): string
    {
        return match ($code) {
            'tags_truncated' => __('Warning: more than 1000 tags; only the first 1000 GitHub returns are read. Delete old tags.', 'pc-theme-release'),
            'containment_unknown' => __('Warning: GitHub could not confirm the branch of the newest tag. Nothing is offered until the next check.', 'pc-theme-release'),
            'older_unconfirmed' => __('Warning: some older tags could not be confirmed; they are not listed until the next check.', 'pc-theme-release'),
            'compare_budget_exhausted' => __('Warning: more than 30 of the newest channel tags are not in the channel branch, so older tags were not checked. Delete or move the stray tags.', 'pc-theme-release'),
            default => __('Warning.', 'pc-theme-release'),
        };
    }
}
