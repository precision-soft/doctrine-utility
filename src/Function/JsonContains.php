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

class JsonContains extends AbstractJsonSearch
{
    public const FUNCTION_NAME = 'JSON_CONTAINS';

    public Node $jsonDocExpr;
    public Node $jsonValExpr;
    public ?Node $jsonPathExpr = null;

    /**
     * @throws Exception if the database platform is not MySQL
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        $this->assertMySQLPlatform($sqlWalker);

        $jsonDocumentSql = $sqlWalker->walkStringPrimary($this->jsonDocExpr);
        $jsonValueSql = $sqlWalker->walkStringPrimary($this->jsonValExpr);

        $jsonPathSql = '';
        if (null !== $this->jsonPathExpr) {
            $jsonPathSql = ', ' . $sqlWalker->walkStringPrimary($this->jsonPathExpr);
        }

        return \sprintf('%s(%s, %s)', static::FUNCTION_NAME, $jsonDocumentSql, $jsonValueSql . $jsonPathSql);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonDocExpr = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->jsonValExpr = $parser->StringPrimary();

        if (true === $parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->jsonPathExpr = $parser->StringPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
