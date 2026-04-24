<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Walker;

use Doctrine\ORM\Query\AST\Join;
use Doctrine\ORM\Query\SqlWalker;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

class MySqlWalker extends SqlWalker
{
    public const HINT_USE_INDEX = 'MySqlWalker.UseIndex';
    public const HINT_IGNORE_INDEX = 'MySqlWalker.IgnoreIndex';
    public const HINT_FORCE_INDEX = 'MySqlWalker.ForceIndex';
    public const HINT_SELECT_FOR_UPDATE = 'MySqlWalker.SelectForUpdate';
    public const HINT_IGNORE_INDEX_ON_JOIN = 'MySqlWalker.IgnoreIndexOnJoin';

    protected const FROM_CLAUSE_PATTERN = '/(\s+FROM\s+[`\w\.]+\s+\w*)/';
    protected const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(,\s*[a-zA-Z_][a-zA-Z0-9_]*)*$/';

    /**
     * @throws Exception if preg_replace fails on the SQL fragment
     */
    public function walkFromClause(mixed $fromClause): string
    {
        $fromClauseSql = parent::walkFromClause($fromClause);

        $fromClauseSql = $this->applyIndexHint($fromClauseSql, static::FROM_CLAUSE_PATTERN, static::HINT_USE_INDEX, 'USE INDEX');
        $fromClauseSql = $this->applyIndexHint($fromClauseSql, static::FROM_CLAUSE_PATTERN, static::HINT_IGNORE_INDEX, 'IGNORE INDEX');
        $fromClauseSql = $this->applyIndexHint($fromClauseSql, static::FROM_CLAUSE_PATTERN, static::HINT_FORCE_INDEX, 'FORCE INDEX');

        return $fromClauseSql;
    }

    public function walkWhereClause(mixed $whereClause): string
    {
        $whereClauseSql = parent::walkWhereClause($whereClause);

        $selectForUpdate = $this->getQuery()->getHint(static::HINT_SELECT_FOR_UPDATE);
        if (true === $selectForUpdate) {
            $whereClauseSql .= ' FOR UPDATE';
        }

        return $whereClauseSql;
    }

    /**
     * @throws Exception if the ignore-index-on-join hint has invalid parameters or if preg_replace fails
     */
    public function walkJoinAssociationDeclaration(
        mixed $joinAssociationDeclaration,
        mixed $joinType = Join::JOIN_TYPE_INNER,
        mixed $condExpr = null,
    ): string {
        $joinDeclarationSql = parent::walkJoinAssociationDeclaration($joinAssociationDeclaration, $joinType, $condExpr);

        $ignoreIndex = $this->getQuery()->getHint(static::HINT_IGNORE_INDEX_ON_JOIN);
        if (true === \is_array($ignoreIndex) && [] !== $ignoreIndex) {
            if (2 !== \count($ignoreIndex)) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            [$indexName, $tableName] = $ignoreIndex;

            if (null === $tableName || '' === $tableName) {
                throw new Exception('ignore index on join hint with invalid parameters');
            }

            $this->validateIdentifier($indexName);
            $this->validateIdentifier($tableName);

            if (1 === \preg_match('/`' . \preg_quote($tableName, '/') . '`/', $joinDeclarationSql)) {
                $replacedJoinDeclarationSql = \preg_replace('/\bON\b/', 'IGNORE INDEX (' . $indexName . ') ON', $joinDeclarationSql, 1);

                if (null === $replacedJoinDeclarationSql) {
                    throw new Exception('preg_replace failed on join declaration SQL');
                }

                $joinDeclarationSql = $replacedJoinDeclarationSql;
            }
        }

        return $joinDeclarationSql;
    }

    /**
     * @throws Exception if the identifier is invalid or if preg_replace fails
     */
    protected function applyIndexHint(string $sqlFragment, string $regex, string $hintName, string $indexType): string
    {
        $indexName = $this->getQuery()->getHint($hintName);

        if (false === \is_string($indexName) || '' === $indexName) {
            return $sqlFragment;
        }

        $this->validateIdentifier($indexName);

        $replacedSqlFragment = \preg_replace($regex, '\1 ' . $indexType . ' (' . $indexName . ')', $sqlFragment, 1);

        if (null === $replacedSqlFragment) {
            throw new Exception('preg_replace failed on SQL fragment');
        }

        return $replacedSqlFragment;
    }

    /**
     * @throws Exception if the identifier does not match the allowed pattern
     */
    protected function validateIdentifier(string $identifier): void
    {
        if (1 !== \preg_match(static::IDENTIFIER_PATTERN, $identifier)) {
            throw new Exception(
                \sprintf('invalid identifier `%s`', $identifier),
            );
        }
    }
}
