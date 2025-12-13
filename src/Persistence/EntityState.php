<?php

declare(strict_types=1);

namespace PureMapper\Persistence;

enum EntityState: string
{
    case New = 'new';
    case Managed = 'managed';
    case Dirty = 'dirty';
    case Removed = 'removed';
}
