<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

abstract class AbstractJsonSearch extends FunctionNode
{
    public const MODE_ONE = 'one';
    public const MODE_ALL = 'all';

    public Node $mode;

    protected function parsePathMode(Parser $parser): void
    {
        $lexer = $parser->getLexer();
        $lookaheadValue = $lexer->lookahead->value;

        if (0 !== \strcasecmp(self::MODE_ONE, $lookaheadValue) && 0 !== \strcasecmp(self::MODE_ALL, $lookaheadValue)) {
            throw new Exception(
                \sprintf('mode `%s` is not supported by `%s`', $lookaheadValue, static::class),
            );
        }

        $this->mode = $parser->StringPrimary();
    }
}
