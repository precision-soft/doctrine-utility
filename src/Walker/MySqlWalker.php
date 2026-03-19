<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Walker;

use Doctrine\ORM\Query\AST\Join;
use Doctrine\ORM\Query\SqlWalker;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

/**
 * $qb->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, MySqlWalker::class);
 * $qb->setHint(MySqlWalker::HINT_IGNORE_INDEX, 'PRIMARY, other_index');
 */
class MySqlWalker extends SqlWalker
{
    public const HINT_USE_INDEX = 'MySqlWalker.UseIndex';
    public const HINT_IGNORE_INDEX = 'MySqlWalker.IgnoreIndex';
    public const HINT_FORCE_INDEX = 'MySqlWalker.ForceIndex';
    public const HINT_SELECT_FOR_UPDATE = 'MySqlWalker.SelectForUpdate';
    public const HINT_IGNORE_INDEX_ON_JOIN = 'MySqlWalker.IgnoreIndexOnJoin';

    public function walkFromClause(mixed $fromClause): string
    {
        $regex = '/(\s+FROM\s+[`\w\.]+\s+\w*)/';

        $result = parent::walkFromClause($fromClause);

        $index = $this->getQuery()->getHint(self::HINT_USE_INDEX);
        if (null !== $index && '' !== $index) {
            $result = \preg_replace($regex, '\1 USE INDEX (' . $index . ')', $result);
        }

        $index = $this->getQuery()->getHint(self::HINT_IGNORE_INDEX);
        if (null !== $index && '' !== $index) {
            $result = \preg_replace($regex, '\1 IGNORE INDEX (' . $index . ')', $result);
        }

        $index = $this->getQuery()->getHint(self::HINT_FORCE_INDEX);
        if (null !== $index && '' !== $index) {
            $result = \preg_replace($regex, '\1 FORCE INDEX (' . $index . ')', $result);
        }

        return $result;
    }

    public function walkWhereClause(mixed $whereClause): string
    {
        $result = parent::walkWhereClause($whereClause);

        $selectForUpdate = $this->getQuery()->getHint(self::HINT_SELECT_FOR_UPDATE);
        if (null !== $selectForUpdate && '' !== $selectForUpdate) {
            $result .= ' FOR UPDATE';
        }

        return $result;
    }

    public function walkJoinAssociationDeclaration(
        mixed $joinAssociationDeclaration,
        mixed $joinType = Join::JOIN_TYPE_INNER,
        mixed $condExpr = null,
    ): string {
        $result = parent::walkJoinAssociationDeclaration($joinAssociationDeclaration, $joinType, $condExpr);

        $ignoreIndex = $this->getQuery()->getHint(static::HINT_IGNORE_INDEX_ON_JOIN);
        if (null !== $ignoreIndex && [] !== $ignoreIndex) {
            [$index, $table] = $ignoreIndex;
            if (2 !== \count($ignoreIndex) || null === $table || '' === $table) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            if (1 === \preg_match('/`' . $table . '`/', $result)) {
                $result = \preg_replace('/ON/', 'IGNORE INDEX (' . $index . ') ON', $result);
            }
        }

        return $result;
    }
}
