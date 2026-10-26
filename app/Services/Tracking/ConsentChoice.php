<?php

namespace App\Services\Tracking;

use Carbon\CarbonImmutable;

/**
 * A shopper's stored tracking-consent decision. Necessary cookies are
 * always on and are not part of the choice.
 */
final readonly class ConsentChoice
{
    public function __construct(
        public bool $analytics,
        public bool $marketing,
        public int $version,
        public CarbonImmutable $decidedAt,
    ) {}

    /**
     * Google consent-mode state for this choice. Advertising storage follows
     * only the marketing choice; ad personalization is never offered, so it
     * stays denied.
     *
     * @return array{analytics_storage: string, ad_storage: string, ad_user_data: string, ad_personalization: string}
     */
    public function googleConsentState(): array
    {
        return [
            'analytics_storage' => $this->analytics ? 'granted' : 'denied',
            'ad_storage' => $this->marketing ? 'granted' : 'denied',
            'ad_user_data' => $this->marketing ? 'granted' : 'denied',
            'ad_personalization' => 'denied',
        ];
    }

    /**
     * The state used while no valid choice exists: everything denied.
     *
     * @return array{analytics_storage: string, ad_storage: string, ad_user_data: string, ad_personalization: string}
     */
    public static function deniedGoogleConsentState(): array
    {
        return [
            'analytics_storage' => 'denied',
            'ad_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
        ];
    }
}
