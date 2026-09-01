<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository\Criteria;

enum Direction: string
{
    case Ascending = 'ASC';
    case Descending = 'DESC';
}
