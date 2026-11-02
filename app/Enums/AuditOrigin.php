<?php

namespace App\Enums;

/**
 * Where an audited action came from.
 */
enum AuditOrigin: string
{
    case Admin = 'admin';
    case Storefront = 'storefront';
    case Cli = 'cli';
    case Webhook = 'webhook';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin panel',
            self::Storefront => 'Storefront',
            self::Cli => 'Command line',
            self::Webhook => 'Courier webhook',
            self::System => 'System',
        };
    }
}
