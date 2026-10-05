<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use RuntimeException;

final class StringCodedException extends RuntimeException
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message);

        $this->code = $code;
    }
}
