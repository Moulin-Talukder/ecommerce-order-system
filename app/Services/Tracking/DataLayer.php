<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\Cache;

/**
 * Collects dataLayer events for the current storefront response.
 *
 * Every method is a no-op while Tag Manager is disabled, so tracking never
 * touches the session or cache unless it is switched on. Events are only
 * rendered by the storefront layout; nothing here talks to Google.
 * Registered as a scoped singleton (one per request).
 */
class DataLayer
{
    private const FLASH_KEY = 'tracking.flashed_events';

    private const ONCE_KEY = 'tracking.once';

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function __construct(private TagManager $tagManager) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void
    {
        if ($this->tagManager->enabled()) {
            $this->events[] = $event;
        }
    }

    /**
     * Keep an event for the next page (after a redirect).
     *
     * @param  array<string, mixed>  $event
     */
    public function flash(array $event): void
    {
        if ($this->tagManager->enabled()) {
            session()->flash(self::FLASH_KEY, [...(array) session(self::FLASH_KEY, []), $event]);
        }
    }

    /**
     * Push only when $state differs from the last state pushed under $key in
     * this browser session (e.g. begin_checkout once per cart content).
     *
     * @param  array<string, mixed>  $event
     */
    public function pushWhenChanged(string $key, string $state, array $event): void
    {
        if (! $this->tagManager->enabled() || session(self::ONCE_KEY.'.'.$key) === $state) {
            return;
        }

        session()->put(self::ONCE_KEY.'.'.$key, $state);
        $this->events[] = $event;
    }

    /**
     * Push only the first time $key is claimed, across all sessions and
     * concurrent requests (atomic Cache::add), e.g. one purchase per order.
     *
     * @param  array<string, mixed>  $event
     */
    public function pushOnce(string $key, array $event, int $days = 30): void
    {
        if ($this->tagManager->enabled() && Cache::add('tracking:once:'.$key, true, now()->addDays($days))) {
            $this->events[] = $event;
        }
    }

    /**
     * All events to render now: those flashed by the previous request first.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        if (! $this->tagManager->enabled()) {
            return [];
        }

        return [...(array) session(self::FLASH_KEY, []), ...$this->events];
    }
}
