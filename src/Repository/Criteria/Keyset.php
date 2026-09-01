<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository\Criteria;

readonly class Keyset
{
    /** @param non-empty-array<string, mixed> $values */
    public function __construct(public array $values) {}
}
