<?php

namespace App\Support;

use RuntimeException;

final class ClientTestingProvisionException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct(
            "Client-testing schema validation failed:\n- ".implode("\n- ", $errors)
        );
    }
}
