<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->superAdmin()->create(['name' => 'Owner']);
    }

    /**
     * @return list<int>
     */
    private function listedIds(array $query = []): array
    {
        return $this->actingAs($this->owner)->get(route('admin.audit-logs.index', $query))->assertOk()->viewData('logs')->pluck('id')->all();
    }

    // --- Authorization -------------------------------------------------------------------------

    public function test_an_active_super_admin_can_open_the_list_and_an_entry(): void
    {
        $log = AuditLog::factory()->create();

        $this->actingAs($this->owner)->get(route('admin.audit-logs.index'))->assertOk()->assertSee('Audit log');
        $this->get(route('admin.audit-logs.show', $log))->assertOk()->assertSee('Product updated');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherRoles(): array
    {
        return ['admin' => ['admin'], 'manager' => ['manager'], 'staff' => ['staff']];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_are_refused_and_do_not_see_the_link(string $role): void
    {
        $log = AuditLog::factory()->create();
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();
        $this->get(route('admin.audit-logs.show', $log))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.audit-logs.index'), false);
    }

    public function test_the_link_is_shown_to_super_admins(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertSee(route('admin.audit-logs.index'), false);
    }

    public function test_an_inactive_super_admin_and_guests_are_sent_to_login(): void
    {
        $log = AuditLog::factory()->create();

        $this->get(route('admin.audit-logs.index'))->assertRedirect(route('login'));
        $this->get(route('admin.audit-logs.show', $log))->assertRedirect(route('login'));

        $inactive = User::factory()->superAdmin()->inactive()->create();
        $this->actingAs($inactive)->get(route('admin.audit-logs.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($inactive)->get(route('admin.audit-logs.show', $log))->assertRedirect(route('login'));
    }

    public function test_the_viewer_is_read_only(): void
    {
        $log = AuditLog::factory()->create(['description' => 'Original']);
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.audit-logs.'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
        $this->actingAs($this->owner)->put(route('admin.audit-logs.show', $log), ['description' => 'x'])->assertMethodNotAllowed();
        $this->delete(route('admin.audit-logs.show', $log))->assertMethodNotAllowed();
        $this->assertSame('Original', $log->refresh()->description);
    }

    // --- Listing ---------------------------------------------------------------------------------

    public function test_entries_are_listed_newest_first_25_per_page(): void
    {
        $ids = AuditLog::factory()->count(30)->create()->pluck('id')->all();

        $first = $this->listedIds();
        $second = $this->listedIds(['page' => 2]);

        $this->assertSame(array_slice(array_reverse($ids), 0, 25), $first);
        $this->assertSame(array_slice(array_reverse($ids), 25), $second);
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $actor = User::factory()->admin()->create();
        $login = AuditLog::factory()->create(['event' => AuditEvent::AuthLoginSucceeded, 'subject_type' => 'user', 'subject_label' => 'staff@example.test']);
        $cli = AuditLog::factory()->fromCli()->create(['event' => AuditEvent::UserPasswordReset, 'subject_type' => 'user']);
        $byActor = AuditLog::factory()->by($actor)->create(['subject_type' => 'order', 'subject_label' => 'ORD-261103-ABC123']);
        $old = AuditLog::factory()->create(['created_at' => '2026-01-15 10:00:00']);

        $this->assertSame([$login->id], $this->listedIds(['event' => 'auth.login_succeeded']));
        $this->assertSame([$cli->id], $this->listedIds(['origin' => 'cli']));
        $this->assertSame([$cli->id, $login->id], $this->listedIds(['subject_type' => 'user']));
        $this->assertSame([$byActor->id], $this->listedIds(['actor' => $actor->id]));
        $this->assertSame([$byActor->id], $this->listedIds(['search' => 'ABC123']));
        $this->assertSame([$old->id], $this->listedIds(['from' => '2026-01-01', 'to' => '2026-01-31']));
        $this->assertSame([], $this->listedIds(['event' => 'auth.login_succeeded', 'origin' => 'cli']));
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $all = AuditLog::factory()->count(3)->create()->pluck('id')->reverse()->values()->all();

        $this->assertSame($all, $this->listedIds(['event' => 'order.deleted', 'origin' => 'mars', 'subject_type' => 'App\\Models\\User', 'actor' => 999999]));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'impossible date' => [['from' => '2026-02-30'], 'Enter dates as YYYY-MM-DD.'],
            'not a date' => [['to' => 'yesterday'], 'Enter dates as YYYY-MM-DD.'],
            'reversed' => [['from' => '2026-06-01', 'to' => '2026-01-01'], 'The start date must be on or before the end date.'],
            'too long' => [['from' => '2024-01-01', 'to' => '2026-01-01'], 'A date range can cover at most 366 days.'],
        ];
    }

    /**
     * @param  array<string, string>  $range
     */
    #[DataProvider('invalidRanges')]
    public function test_invalid_date_ranges_show_an_error_and_no_rows(array $range, string $message): void
    {
        AuditLog::factory()->count(3)->create();

        $this->actingAs($this->owner)->get(route('admin.audit-logs.index', $range))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('No audit entries match your filters.');
        $this->assertSame([], $this->listedIds($range));
    }

    public function test_the_list_shows_snapshots_but_never_network_details(): void
    {
        AuditLog::factory()->by(User::factory()->admin()->create(['name' => 'Arif Admin']))->create([
            'ip_address' => '203.0.113.99', 'user_agent' => 'SecretBrowser/9.9', 'subject_label' => 'SKU-777',
        ]);

        $this->actingAs($this->owner)->get(route('admin.audit-logs.index'))
            ->assertSee('Arif Admin')->assertSee('SKU-777')->assertSee('Admin panel')
            ->assertDontSee('203.0.113.99')->assertDontSee('SecretBrowser');
    }

    // --- Detail and privacy ------------------------------------------------------------------------

    public function test_the_entry_shows_snapshots_context_and_network_details(): void
    {
        $log = AuditLog::factory()->create([
            'event' => AuditEvent::CustomerUpdated, 'subject_type' => 'customer', 'subject_label' => 'R••• A••• · ••••••••678',
            'old_values' => ['phone' => '••••••••678', 'address' => '[changed]', 'status' => 'active'],
            'new_values' => ['phone' => '••••••••999', 'address' => '[changed]', 'status' => 'blocked'],
            'context' => ['restocked' => [['product_id' => 5, 'quantity' => 2]], 'forced' => true, 'note' => null],
            'ip_address' => '203.0.113.99', 'user_agent' => 'SecretBrowser/9.9', 'created_at' => '2026-11-03 14:05:09',
        ]);

        $this->actingAs($this->owner)->get(route('admin.audit-logs.show', $log))
            ->assertOk()
            ->assertSeeInOrder(['Customer updated', 'customer.updated', '03 Nov 2026, 02:05:09 PM', 'R••• A••• · ••••••••678'])
            ->assertSeeInOrder(['phone', '••••••••678', '••••••••999'])
            ->assertSeeInOrder(['status', 'active', 'blocked'])
            // MySQL's JSON type reorders keys, so each group is checked on its own.
            ->assertSeeInOrder(['restocked.0.product_id', '5'])
            ->assertSeeInOrder(['restocked.0.quantity', '2'])
            ->assertSeeInOrder(['forced', 'Yes'])
            ->assertSee('203.0.113.99')->assertSee('SecretBrowser/9.9')
            ->assertDontSee('"phone":', false)->assertDontSee('&quot;phone&quot;', false)->assertDontSee('"product_id":', false);
    }

    public function test_empty_snapshots_and_missing_metadata_render_safely(): void
    {
        $log = AuditLog::factory()->fromCli()->create(['subject_type' => null, 'subject_id' => null, 'subject_label' => null, 'old_values' => null, 'new_values' => null, 'context' => null]);

        $this->actingAs($this->owner)->get(route('admin.audit-logs.show', $log))
            ->assertOk()
            ->assertSee('No field values were recorded for this entry.')
            ->assertSee('No additional context.')
            ->assertSee('No signed-in user')
            ->assertSee('Command line');
    }

    public function test_stored_values_are_escaped(): void
    {
        $log = AuditLog::factory()->create([
            'subject_label' => '<script>alert("label")</script>',
            'new_values' => ['name' => '<img src=x onerror=alert(1)>'],
            'user_agent' => '<b>agent</b>',
        ]);

        $this->actingAs($this->owner)->get(route('admin.audit-logs.index'))->assertOk()->assertDontSee('<script>alert("label")</script>', false);
        $this->get(route('admin.audit-logs.show', $log))->assertOk()
            ->assertDontSee('<script>alert("label")</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('<b>agent</b>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_entries_show_the_recorded_snapshot_not_live_records(): void
    {
        $actor = User::factory()->admin()->create(['name' => 'Name At The Time']);
        $product = Product::factory()->create(['name' => 'Live Product Name', 'sku' => 'LIVE-SKU']);
        $log = AuditLog::factory()->by($actor)->create(['subject_type' => 'product', 'subject_id' => $product->id, 'subject_label' => 'SKU-AT-THE-TIME']);
        $actor->forceFill(['name' => 'Renamed Later'])->save();

        DB::enableQueryLog();
        $this->actingAs($this->owner)->get(route('admin.audit-logs.show', $log))
            ->assertSee('Name At The Time')->assertSee('SKU-AT-THE-TIME')
            ->assertDontSee('Renamed Later')->assertDontSee('Live Product Name')->assertDontSee('LIVE-SKU');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('`products`', $queries, 'The subject record is never loaded.');
        $this->assertStringNotContainsString('`users` where `users`.`id` in', $queries, 'The actor relation is never loaded.');
    }
}
