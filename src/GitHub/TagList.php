<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

final readonly class TagList
{
    /** @param list<Tag> $tags */
    public function __construct(public array $tags, public bool $truncated)
    {
    }
}
