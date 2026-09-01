<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository\Criteria;

readonly class Criteria
{
    /**
     * @param list<Filter> $filters
     * @param list<Sort> $sorts
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public ?int $limit = null,
        public ?Keyset $keyset = null,
    ) {}
}
