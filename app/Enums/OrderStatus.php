<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Placed => [self::Processing, self::Cancelled],
            self::Processing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed],
            default => [],
        }, true);
    }
}
