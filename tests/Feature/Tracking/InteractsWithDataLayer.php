<?php

namespace Tests\Feature\Tracking;

use Illuminate\Testing\TestResponse;

/**
 * Helpers that read the dataLayer exactly as the browser would: the events
 * array is decoded from the rendered storefront script.
 */
trait InteractsWithDataLayer
{
    protected function enableGtm(string $containerId = 'GTM-TEST123'): void
    {
        config(['services.gtm.container_id' => $containerId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function dataLayerEvents(TestResponse $response): array
    {
        $html = (string) $response->getContent();
        $end = strpos($html, "').forEach(push)");

        if ($end === false) {
            return [];
        }

        $start = strrpos(substr($html, 0, $end), "JSON.parse('") + strlen("JSON.parse('");
        $match = [1 => substr($html, $start, $end - $start)];

        return json_decode(json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The Google consent-mode defaults rendered before the GTM container.
     *
     * @return array<string, string>|null
     */
    protected function googleConsentDefaults(TestResponse $response): ?array
    {
        if (! preg_match("/gtag\\('consent', 'default', JSON\\.parse\\('([^']*)'\\)\\);/", (string) $response->getContent(), $match)) {
            return null;
        }

        return json_decode(json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The page-context object pushed before the GTM container.
     *
     * @return array<string, mixed>|null
     */
    protected function pushedPageContext(TestResponse $response): ?array
    {
        if (! preg_match("/window\\.dataLayer\\.push\\(JSON\\.parse\\('([^']*)'\\)\\);<\\/script>/", (string) $response->getContent(), $match)) {
            return null;
        }

        return json_decode(json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    protected function eventsNamed(array $events, string $name): array
    {
        return array_values(array_filter($events, fn (array $event) => ($event['event'] ?? null) === $name));
    }
}
