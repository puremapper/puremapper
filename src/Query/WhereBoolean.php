<?php

declare(strict_types=1);

namespace PureMapper\Query;

enum WhereBoolean: string
{
    case And = 'AND';
    case Or = 'OR';
}
