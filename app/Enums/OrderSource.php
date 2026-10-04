<?php

namespace App\Enums;

/**
 * Channel through which an order was placed.
 */
enum OrderSource: string
{
    case Website = 'website';
    case Admin = 'admin';
    case Phone = 'phone';
    case Facebook = 'facebook';
    case WhatsApp = 'whatsapp';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Admin => 'Admin Panel',
            self::Phone => 'Phone',
            self::Facebook => 'Facebook',
            self::WhatsApp => 'WhatsApp',
            self::Api => 'API',
        };
    }
}
