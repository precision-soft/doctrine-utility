<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Function;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;

abstract class AbstractJsonSearch extends FunctionNode
{
    public const MODE_ONE = 'one';
    public const MODE_ALL = 'all';

    public string $mode;

    protected function parsePathMode(Parser $parser): void
    {
        $lexer = $parser->getLexer();
        $value = $lexer->lookahead['value'];

        if (0 === \strcasecmp(self::MODE_ONE, $value)) {
            $this->mode = self::MODE_ONE;
            $parser->StringPrimary();

            return;
        }

        if (0 === \strcasecmp(self::MODE_ALL, $value)) {
            $this->mode = self::MODE_ALL;
            $parser->StringPrimary();

            return;
        }

        throw new Exception(
            \sprintf('mode `%s` is not supported by `%s`', $value, static::class),
        );
    }
}
