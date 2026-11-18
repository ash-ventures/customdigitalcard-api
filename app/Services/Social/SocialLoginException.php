<?php

namespace App\Services\Social;

use RuntimeException;

class SocialLoginException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Social login failed: {$reason}");
    }
}
