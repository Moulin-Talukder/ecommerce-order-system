<?php

namespace App\Services\Tracking;

/**
 * Google Tag Manager configuration for the storefront.
 *
 * Enabled only when GTM_CONTAINER_ID holds a well-formed container id; an
 * empty or malformed value disables every tracking script and event.
 */
class TagManager
{
    private const CONTAINER_ID_PATTERN = '/^GTM-[A-Z0-9]{4,12}$/';

    public function enabled(): bool
    {
        return $this->containerId() !== null;
    }

    public function containerId(): ?string
    {
        $id = strtoupper(trim((string) config('services.gtm.container_id')));

        return preg_match(self::CONTAINER_ID_PATTERN, $id) === 1 ? $id : null;
    }
}
