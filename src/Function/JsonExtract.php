<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

class JsonExtract extends FunctionNode
{
    public const FUNCTION_NAME = 'JSON_EXTRACT';

    public Node $jsonDocExpr;
    public Node $firstJsonPathExpr;
    public array $jsonPaths = [];

    public function getSql(SqlWalker $sqlWalker): string
    {
        $jsonDoc = $sqlWalker->walkStringPrimary($this->jsonDocExpr);

        $paths = [];
        foreach ($this->jsonPaths as $path) {
            $paths[] = $sqlWalker->walkStringPrimary($path);
        }

        if (($sqlWalker->getConnection()->getDatabasePlatform() instanceof MySqlPlatform) === true) {
            return \sprintf('%s(%s, %s)', static::FUNCTION_NAME, $jsonDoc, \implode(', ', $paths));
        }

        throw new Exception(\sprintf('method `%s` is not supported', static::FUNCTION_NAME));
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonDocExpr = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->firstJsonPathExpr = $parser->StringPrimary();
        $this->jsonPaths[] = $this->firstJsonPathExpr;

        while (true === $parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->jsonPaths[] = $parser->StringPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
