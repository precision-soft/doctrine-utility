<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Join;

use Doctrine\ORM\Query\Expr\Join;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

class JoinCollection
{
    /** @var Join[] */
    private array $joins = [];

    /**
     * @return Join[]
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    public function addJoin(Join $join): static
    {
        $alias = $join->getAlias();

        if (true === isset($this->joins[$alias])) {
            throw new Exception(
                \sprintf('duplicate alias `%s` in join collection', $alias),
            );
        }

        $this->joins[$alias] = $join;

        return $this;
    }

    /** @return string[] */
    public function getAliases(): array
    {
        return \array_keys($this->joins);
    }
}
