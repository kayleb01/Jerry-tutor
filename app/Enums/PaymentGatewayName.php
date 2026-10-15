<?php

namespace App\Enums;

enum PaymentGatewayName: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';

    public function label(): string
    {
        return match ($this) {
            self::Paystack => 'Paystack',
            self::Flutterwave => 'Flutterwave',
        };
    }
}
