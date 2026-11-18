<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

final readonly class Tag
{
    public function __construct(public string $name, public string $sha)
    {
    }
}
