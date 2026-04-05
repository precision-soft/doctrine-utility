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
use Doctrine\ORM\Query\TokenType;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

class DateFormat extends FunctionNode
{
    public const FUNCTION_NAME = 'DATE_FORMAT';

    public Node $firstDateExpression;
    public Node $secondDateExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->firstDateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->secondDateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        if (true === ($sqlWalker->getConnection()->getDatabasePlatform() instanceof MySQLPlatform)) {
            return \sprintf(
                '%s(%s, %s)',
                static::FUNCTION_NAME,
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->secondDateExpression->dispatch($sqlWalker),
            );
        }

        throw new Exception(\sprintf('method `%s` is not supported', static::FUNCTION_NAME));
    }
}
