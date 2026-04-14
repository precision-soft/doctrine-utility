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

class JsonUnquote extends AbstractJsonSearch
{
    public const FUNCTION_NAME = 'JSON_UNQUOTE';

    public Node $jsonValExpr;

    public function getSql(SqlWalker $sqlWalker): string
    {
        $this->assertMySQLPlatform($sqlWalker);

        $jsonValueSql = $sqlWalker->walkStringPrimary($this->jsonValExpr);

        return \sprintf('%s(%s)', static::FUNCTION_NAME, $jsonValueSql);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonValExpr = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
