<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

final readonly class CompareResult
{
    public function __construct(public Containment $containment, public ?int $commitDate)
    {
    }
}
