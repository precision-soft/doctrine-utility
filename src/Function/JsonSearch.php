<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\DBAL\Platforms\MySqlPlatform;
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
    public Node $jsonEscapeExpr;
    public array $jsonPaths = [];

    public function getSql(SqlWalker $sqlWalker): string
    {
        $jsonDoc = $sqlWalker->walkStringPrimary($this->jsonDocExpr);
        $mode = $sqlWalker->walkStringPrimary($this->mode);
        $searchArgs = $sqlWalker->walkStringPrimary($this->jsonSearchExpr);

        if (false === empty($this->jsonEscapeExpr)) {
            $searchArgs .= ', ' . $sqlWalker->walkStringPrimary($this->jsonEscapeExpr);

            if (false === empty($this->jsonPaths)) {
                $jsonPaths = [];
                foreach ($this->jsonPaths as $path) {
                    $jsonPaths[] = $sqlWalker->walkStringPrimary($path);
                }
                $searchArgs .= ', ' . \implode(', ', $jsonPaths);
            }
        }

        if (($sqlWalker->getConnection()->getDatabasePlatform() instanceof MySqlPlatform) === true) {
            return \sprintf('%s(%s, %s, %s)', static::FUNCTION_NAME, $jsonDoc, $mode, $searchArgs);
        }

        throw new Exception(\sprintf('method `%s` is not supported', static::FUNCTION_NAME));
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
