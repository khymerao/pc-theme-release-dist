<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

enum Containment: string
{
    case Contained = 'contained';
    case NotContained = 'not_contained';
    case BranchMissing = 'branch_missing';
}
