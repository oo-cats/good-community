<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class DomainError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422, string $message = '')
    {
        parent::__construct($message ?: $reason);
    }
}
