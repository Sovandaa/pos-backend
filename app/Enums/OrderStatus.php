<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
