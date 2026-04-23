<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

abstract class AbstractJsonSearch extends FunctionNode
{
    public const MODE_ONE = 'one';
    public const MODE_ALL = 'all';

    public Node $mode;

    /**
     * @throws Exception if the database platform is not MySQL
     */
    protected function assertMySQLPlatform(SqlWalker $sqlWalker): void
    {
        if (false === ($sqlWalker->getConnection()->getDatabasePlatform() instanceof MySQLPlatform)) {
            throw new Exception(\sprintf('function `%s` is not supported', static::FUNCTION_NAME)); // @phpstan-ignore classConstant.notFound
        }
    }

    /**
     * @throws Exception if the mode argument is missing or not 'one'/'all'
     */
    protected function parsePathMode(Parser $parser): void
    {
        $lexer = $parser->getLexer();

        if (null === $lexer->lookahead) {
            throw new Exception(
                \sprintf('unexpected end of input in `%s`', static::class),
            );
        }

        $lookaheadValue = (string)$lexer->lookahead->value;

        if (0 !== \strcasecmp(static::MODE_ONE, $lookaheadValue) && 0 !== \strcasecmp(static::MODE_ALL, $lookaheadValue)) {
            throw new Exception(
                \sprintf('mode `%s` is not supported by `%s`', $lookaheadValue, static::class),
            );
        }

        $this->mode = $parser->StringPrimary();
    }
}
