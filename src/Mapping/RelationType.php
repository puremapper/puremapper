<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

enum RelationType: string
{
    case HasOne = 'hasOne';
    case HasMany = 'hasMany';
    case BelongsTo = 'belongsTo';
    case ManyToMany = 'manyToMany';
}
