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

class JsonContainsPath extends AbstractJsonSearch
{
    public const FUNCTION_NAME = 'JSON_CONTAINS_PATH';

    public Node $jsonDocExpr;
    /** @var Node[] */
    public array $jsonPaths = [];

    public function getSql(SqlWalker $sqlWalker): string
    {
        $this->assertMySQLPlatform($sqlWalker);

        $jsonDocumentSql = $sqlWalker->walkStringPrimary($this->jsonDocExpr);
        $modeSql = $sqlWalker->walkStringPrimary($this->mode);

        $walkedPaths = [];
        foreach ($this->jsonPaths as $jsonPath) {
            $walkedPaths[] = $sqlWalker->walkStringPrimary($jsonPath);
        }

        return \sprintf('%s(%s, %s, %s)', static::FUNCTION_NAME, $jsonDocumentSql, $modeSql, \implode(', ', $walkedPaths));
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonDocExpr = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->parsePathMode($parser);

        $parser->match(TokenType::T_COMMA);

        $this->jsonPaths[] = $parser->StringPrimary();

        while (true === $parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->jsonPaths[] = $parser->StringPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
