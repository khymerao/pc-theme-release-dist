<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\Config\Channel;

/**
 * What the last check learnt about one repository for the site's channel.
 */
final readonly class RepoState
{
    /** Warning codes (stored with the state, shown by Status\ThemeStatus). */
    public const string TAGS_TRUNCATED = 'tags_truncated';
    public const string CONTAINMENT_UNKNOWN = 'containment_unknown';
    public const string OLDER_UNCONFIRMED = 'older_unconfirmed';
    public const string COMPARE_BUDGET_EXHAUSTED = 'compare_budget_exhausted';
    /** Error code of a check whose channel branch does not exist on GitHub. */
    public const string BRANCH_MISSING = 'branch_missing';
    /** Error codes of a repository that is not the one mapped (audit N1): nothing is offered or installed. */
    public const string REPO_PUBLIC = 'repo_public';
    public const string REPO_REPLACED = 'repo_replaced';
    /** Ancestry statuses that let a newer tag be offered over the installed commit (audit N2). */
    public const array DESCENDANT = ['ahead', 'identical'];

    /**
     * @param list<ChannelRelease>  $releases  Channel tags contained in the branch, newest first (≤ 20).
     * @param array<string, string> $tagShas   Every tag name GitHub listed (all channels) → commit SHA.
     * @param list<string>          $warnings  Warning codes.
     * @param array{base?: string, head?: string, status?: string} $ancestry How the offerable tag's commit (head)
     *        relates to the installed commit (base): GitHub's compare status, or "unknown".
     */
    public function __construct(
        public array $releases,
        public ?ChannelRelease $offerable,
        public array $tagShas,
        public int $checkedAt,
        public ?string $error,
        public string $errorDetail,
        public array $warnings,
        public string $context = '',
        public array $ancestry = [],
    ) {
    }

    /** The same state with the ancestry verdict for one (installed, offerable) pair. */
    public function withAncestry(string $base, string $head, string $status): self
    {
        return new self($this->releases, $this->offerable, $this->tagShas, $this->checkedAt, $this->error, $this->errorDetail, $this->warnings, $this->context, ['base' => $base, 'head' => $head, 'status' => $status]);
    }

    /** The stored compare status for exactly this pair, or null when none was stored for it. */
    public function ancestryFor(string $base, string $head): ?string
    {
        if (($this->ancestry['base'] ?? null) !== $base || ($this->ancestry['head'] ?? null) !== $head) {
            return null;
        }

        return $this->ancestry['status'] ?? null;
    }

    public static function empty(): self
    {
        return new self([], null, [], 0, null, '', []);
    }

    /** "channel|branch" the state was computed for; offers from another context are void. */
    public static function contextFor(Channel $channel, string $branch): string
    {
        return $channel->value . '|' . $branch;
    }

    public function hasWarning(string $code): bool
    {
        return in_array($code, $this->warnings, true);
    }

    public function release(string $tag): ?ChannelRelease
    {
        return $this->find(static fn (ChannelRelease $release): bool => $release->tag === $tag);
    }

    public function releaseBySha(string $sha): ?ChannelRelease
    {
        return $this->find(static fn (ChannelRelease $release): bool => $release->sha === $sha);
    }

    /** @param \Closure(ChannelRelease): bool $matches */
    private function find(\Closure $matches): ?ChannelRelease
    {
        foreach ($this->releases as $release) {
            if ($matches($release)) {
                return $release;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'releases' => array_map(static fn (ChannelRelease $r): array => $r->toArray(), $this->releases),
            'offerable' => $this->offerable?->tag,
            'tag_shas' => $this->tagShas,
            'checked_at' => $this->checkedAt,
            'error' => $this->error,
            'error_detail' => $this->errorDetail,
            'warnings' => $this->warnings,
            'context' => $this->context,
        ] + ([] === $this->ancestry ? [] : ['ancestry' => $this->ancestry]);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $releases = [];
        foreach (is_array($data['releases'] ?? null) ? $data['releases'] : [] as $raw) {
            $release = is_array($raw) ? ChannelRelease::fromArray($raw) : null;
            if (null !== $release) {
                $releases[] = $release;
            }
        }
        $offerTag = is_string($data['offerable'] ?? null) ? $data['offerable'] : null;
        $offerable = null;
        foreach ($releases as $release) {
            if ($release->tag === $offerTag) {
                $offerable = $release;
            }
        }
        $tagShas = [];
        foreach (is_array($data['tag_shas'] ?? null) ? $data['tag_shas'] : [] as $name => $sha) {
            if (is_string($sha)) {
                $tagShas[(string) $name] = $sha;
            }
        }
        $warnings = array_values(array_filter(
            is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            'is_string'
        ));

        return new self(
            $releases,
            $offerable,
            $tagShas,
            is_int($data['checked_at'] ?? null) ? $data['checked_at'] : 0,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            is_string($data['error_detail'] ?? null) ? $data['error_detail'] : '',
            $warnings,
            is_string($data['context'] ?? null) ? $data['context'] : '',
            self::ancestryFrom($data['ancestry'] ?? null),
        );
    }

    /** @return array{base?: string, head?: string, status?: string} */
    private static function ancestryFrom(mixed $raw): array
    {
        if (! is_array($raw) || ! is_string($raw['base'] ?? null) || ! is_string($raw['head'] ?? null) || ! is_string($raw['status'] ?? null)) {
            return [];
        }

        return ['base' => $raw['base'], 'head' => $raw['head'], 'status' => $raw['status']];
    }
}
