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

class JsonContains extends FunctionNode
{
    public const FUNCTION_NAME = 'JSON_CONTAINS';

    public Node $jsonDocExpr;
    public Node $jsonValExpr;
    public ?Node $jsonPathExpr = null;

    public function getSql(SqlWalker $sqlWalker): string
    {
        $jsonDocumentSql = $sqlWalker->walkStringPrimary($this->jsonDocExpr);
        $jsonValueSql = $sqlWalker->walkStringPrimary($this->jsonValExpr);

        $jsonPathSql = '';
        if (null !== $this->jsonPathExpr) {
            $jsonPathSql = ', ' . $sqlWalker->walkStringPrimary($this->jsonPathExpr);
        }

        if (true === ($sqlWalker->getConnection()->getDatabasePlatform() instanceof MySQLPlatform)) {
            return \sprintf('%s(%s, %s)', static::FUNCTION_NAME, $jsonDocumentSql, $jsonValueSql . $jsonPathSql);
        }

        throw new Exception(\sprintf('function `%s` is not supported', static::FUNCTION_NAME));
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
