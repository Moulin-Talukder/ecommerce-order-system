<?php

namespace App\Enums;

/**
 * How the customer pays for an order.
 */
enum PaymentMethod: string
{
    case CashOnDelivery = 'cod';
    case BankTransfer = 'bank_transfer';
    case OnlinePayment = 'online';

    public function label(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Cash on Delivery',
            self::BankTransfer => 'Bank Transfer',
            self::OnlinePayment => 'Online Payment',
        };
    }
}
