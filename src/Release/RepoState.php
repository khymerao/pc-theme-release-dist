<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

/**
 * What the last check learnt about one repository for the site's channel.
 */
final readonly class RepoState
{
    /**
     * @param list<ChannelRelease>  $releases  Channel tags contained in the branch, newest first (≤ 20).
     * @param array<string, string> $tagShas   Every tag name GitHub listed (all channels) → commit SHA.
     * @param list<string>          $warnings  Warning codes.
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
    ) {
    }

    public static function empty(): self
    {
        return new self([], null, [], 0, null, '', []);
    }

    /** "channel|branch" the state was computed for; offers from another context are void. */
    public static function contextFor(\ProCoders\ThemeRelease\Config\Channel $channel, string $branch): string
    {
        return $channel->value . '|' . $branch;
    }

    public function release(string $tag): ?ChannelRelease
    {
        foreach ($this->releases as $release) {
            if ($release->tag === $tag) {
                return $release;
            }
        }

        return null;
    }

    public function releaseBySha(string $sha): ?ChannelRelease
    {
        foreach ($this->releases as $release) {
            if ($release->sha === $sha) {
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
        ];
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
        );
    }
}
