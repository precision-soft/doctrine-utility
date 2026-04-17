<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

class DateFormat extends AbstractJsonSearch
{
    public const FUNCTION_NAME = 'DATE_FORMAT';

    public Node|string $firstDateExpression;
    public Node|string $secondDateExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->firstDateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->secondDateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /**
     * @throws Exception if the database platform is not MySQL
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        $this->assertMySQLPlatform($sqlWalker);

        /** @info dispatch() is used (not walkStringPrimary) because date expressions are ArithmeticPrimary nodes — dispatch() polymorphically calls the correct walker method for the actual node type */
        $firstSql = true === ($this->firstDateExpression instanceof Node)
            ? $this->firstDateExpression->dispatch($sqlWalker)
            : $this->firstDateExpression;

        $secondSql = true === ($this->secondDateExpression instanceof Node)
            ? $this->secondDateExpression->dispatch($sqlWalker)
            : $this->secondDateExpression;

        return \sprintf('%s(%s, %s)', static::FUNCTION_NAME, $firstSql, $secondSql);
    }
}
