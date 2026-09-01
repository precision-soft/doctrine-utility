<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository\Criteria;

readonly class Sort
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {}
}
