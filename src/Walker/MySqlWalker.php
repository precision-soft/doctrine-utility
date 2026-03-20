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

    private const INDEX_NAME_PATTERN = '/^[\w`,\s]+$/';

    public function walkFromClause(mixed $fromClause): string
    {
        $regex = '/(\s+FROM\s+[`\w\.]+\s+\w*)/';

        $result = parent::walkFromClause($fromClause);

        $result = $this->applyIndexHint($result, $regex, self::HINT_USE_INDEX, 'USE INDEX');
        $result = $this->applyIndexHint($result, $regex, self::HINT_IGNORE_INDEX, 'IGNORE INDEX');
        $result = $this->applyIndexHint($result, $regex, self::HINT_FORCE_INDEX, 'FORCE INDEX');

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
            if (2 !== \count($ignoreIndex)) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            [$index, $table] = $ignoreIndex;

            if (null === $table || '' === $table) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            $this->validateIndexName($index);
            $this->validateIndexName($table);

            if (1 === \preg_match('/`' . \preg_quote($table, '/') . '`/', $result)) {
                $result = \preg_replace('/ON/', 'IGNORE INDEX (' . $index . ') ON', $result, 1);
            }
        }

        return $result;
    }

    private function applyIndexHint(string $result, string $regex, string $hintName, string $indexType): string
    {
        $index = $this->getQuery()->getHint($hintName);

        if (null === $index || '' === $index) {
            return $result;
        }

        $this->validateIndexName($index);

        return \preg_replace($regex, '\1 ' . $indexType . ' (' . $index . ')', $result);
    }

    private function validateIndexName(string $index): void
    {
        if (1 !== \preg_match(self::INDEX_NAME_PATTERN, $index)) {
            throw new Exception(
                \sprintf('invalid index name `%s`', $index),
            );
        }
    }
}
