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

class JsonSearch extends AbstractJsonSearch
{
    public const FUNCTION_NAME = 'JSON_SEARCH';

    public Node $jsonDocExpr;
    public Node $jsonSearchExpr;
    public ?Node $jsonEscapeExpr = null;
    /** @var Node[] */
    public array $jsonPaths = [];

    /**
     * @throws Exception if the database platform is not MySQL
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        $this->assertMySQLPlatform($sqlWalker);

        $jsonDocumentSql = $sqlWalker->walkStringPrimary($this->jsonDocExpr);
        $modeSql = $sqlWalker->walkStringPrimary($this->mode);
        $searchArgsSql = $sqlWalker->walkStringPrimary($this->jsonSearchExpr);

        if (null !== $this->jsonEscapeExpr) {
            $searchArgsSql .= ', ' . $sqlWalker->walkStringPrimary($this->jsonEscapeExpr);

            if ([] !== $this->jsonPaths) {
                $walkedPaths = [];
                foreach ($this->jsonPaths as $jsonPath) {
                    $walkedPaths[] = $sqlWalker->walkStringPrimary($jsonPath);
                }
                $searchArgsSql .= ', ' . \implode(', ', $walkedPaths);
            }
        }

        return \sprintf('%s(%s, %s, %s)', static::FUNCTION_NAME, $jsonDocumentSql, $modeSql, $searchArgsSql);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonDocExpr = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->parsePathMode($parser);

        $parser->match(TokenType::T_COMMA);

        $this->jsonSearchExpr = $parser->StringPrimary();

        if (true === $parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->jsonEscapeExpr = $parser->StringPrimary();

            while (true === $parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
                $parser->match(TokenType::T_COMMA);
                $this->jsonPaths[] = $parser->StringPrimary();
            }
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
