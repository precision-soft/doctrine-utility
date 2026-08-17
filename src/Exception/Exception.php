<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Exception;

use Exception as BaseException;
use PrecisionSoft\Doctrine\Utility\Contract\ExceptionInterface;
use PrecisionSoft\Doctrine\Utility\Exception\Trait\ExceptionTrait;
use Throwable;

class Exception extends BaseException implements ExceptionInterface
{
    use ExceptionTrait;

    /** @param array<string, mixed>|null $context */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?array $context = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->setContext($context);
    }
}
