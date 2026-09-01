<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository\Criteria;

enum Operator: string
{
    case Equal = '=';
    case NotEqual = '<>';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case Like = 'LIKE';
    case IsNull = 'IS NULL';
    case IsNotNull = 'IS NOT NULL';
}
