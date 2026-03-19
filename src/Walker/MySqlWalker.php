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

        if (false === empty($index = $this->getQuery()->getHint(self::HINT_USE_INDEX))) {
            $result = \preg_replace($regex, '\1 USE INDEX (' . $index . ')', $result);
        }

        if (false === empty($index = $this->getQuery()->getHint(self::HINT_IGNORE_INDEX))) {
            $result = \preg_replace($regex, '\1 IGNORE INDEX (' . $index . ')', $result);
        }

        if (false === empty($index = $this->getQuery()->getHint(self::HINT_FORCE_INDEX))) {
            $result = \preg_replace($regex, '\1 FORCE INDEX (' . $index . ')', $result);
        }

        return $result;
    }

    public function walkWhereClause(mixed $whereClause): string
    {
        $result = parent::walkWhereClause($whereClause);

        if (false === empty($this->getQuery()->getHint(self::HINT_SELECT_FOR_UPDATE))) {
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

        if (false === empty($ignoreIndex = $this->getQuery()->getHint(static::HINT_IGNORE_INDEX_ON_JOIN))) {
            [$index, $table] = $ignoreIndex;
            if (2 !== \count($ignoreIndex) || true === empty($table)) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            if (1 === \preg_match('/`' . $table . '`/', $result)) {
                $result = \preg_replace('/ON/', 'IGNORE INDEX (' . $index . ') ON', $result);
            }
        }

        return $result;
    }
}
