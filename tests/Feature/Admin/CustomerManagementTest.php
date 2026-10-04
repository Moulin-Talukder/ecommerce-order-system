<?php

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Customers\CustomerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahima Akter',
            'phone' => '01712345678',
            'alternate_phone' => '',
            'email' => 'rahima@example.com',
            'address' => 'House 12, Road 5, Block C',
            'area' => 'Mirpur',
            'city' => 'Dhaka',
            'postal_code' => '1216',
            'notes' => 'Prefers evening delivery.',
        ], $overrides);
    }

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    // --- Listing & search ----------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
        $this->post(route('admin.customers.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, Customer::count());
    }

    public function test_the_list_shows_key_customer_information(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Karim Uddin', 'phone' => '01811111111', 'email' => 'karim@example.com', 'area' => 'Gulshan', 'city' => 'Dhaka',
        ]);
        Order::factory()->for($customer)->count(2)->create();
        Customer::factory()->blocked()->create(['name' => 'Blocked Person']);

        $this->actingAs($this->manager())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Karim Uddin')
            ->assertSee('01811111111')
            ->assertSee('karim@example.com')
            ->assertSee('Gulshan, Dhaka')
            ->assertViewHas('customers', fn ($customers) => $customers->firstWhere('name', 'Karim Uddin')->orders_count === 2)
            ->assertSee('Blocked');
    }

    public function test_the_list_is_paginated(): void
    {
        Customer::factory()->count(30)->create();

        $this->actingAs($this->manager())
            ->get(route('admin.customers.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('customers', fn ($customers) => $customers->total() === 30 && $customers->count() === 5);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchTerms(): array
    {
        return [
            'name fragment' => ['salma'],
            'email fragment' => ['salma.b@'],
            'canonical phone' => ['01912345678'],
            '+880 phone' => ['+880 1912-345678'],
            'phone digit fragment' => ['12345678'],
            'alternate phone' => ['01556667777'],
            'alternate phone in 880 format' => ['8801556667777'],
        ];
    }

    #[DataProvider('searchTerms')]
    public function test_search_finds_customers_by_name_email_phone_and_alternate_phone(string $term): void
    {
        Customer::factory()->create(['name' => 'Salma Begum', 'email' => 'salma.b@example.com', 'phone' => '01912345678', 'alternate_phone' => '01556667777']);
        Customer::factory()->create(['name' => 'Other Person', 'email' => 'other@example.com', 'phone' => '01399999999']);

        $this->actingAs($this->manager())
            ->get(route('admin.customers.index', ['search' => $term]))
            ->assertOk()
            ->assertSee('Salma Begum')
            ->assertDontSee('Other Person');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        Customer::factory()->create(['name' => 'Anyone']);

        $this->actingAs($this->manager())
            ->get(route('admin.customers.index', ['search' => '%']))
            ->assertDontSee('Anyone')
            ->assertSee('No customers match your filters.');
    }

    public function test_status_filter(): void
    {
        Customer::factory()->create(['name' => 'Good Customer']);
        Customer::factory()->blocked()->create(['name' => 'Fake Orderer']);

        $this->actingAs($this->manager())
            ->get(route('admin.customers.index', ['status' => 'blocked']))
            ->assertSee('Fake Orderer')
            ->assertDontSee('Good Customer');
    }

    // --- Creating ---------------------------------------------------------------------

    public function test_a_manager_can_create_a_customer(): void
    {
        $response = $this->actingAs($this->manager())->post(route('admin.customers.store'), $this->payload());

        $customer = Customer::sole();
        $response->assertRedirect(route('admin.customers.show', $customer))->assertSessionHas('success');
        $this->assertSame('Rahima Akter', $customer->name);
        $this->assertSame('01712345678', $customer->phone);
        $this->assertNull($customer->alternate_phone);
        $this->assertSame('rahima@example.com', $customer->email);
        $this->assertSame('Mirpur', $customer->area);
        $this->assertSame(CustomerStatus::Active, $customer->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phoneFormats(): array
    {
        return [
            '+880 format' => ['+8801712345678'],
            '880 format' => ['8801712345678'],
            '00880 format' => ['008801712345678'],
            'missing leading zero' => ['1712345678'],
            'spaces and dashes' => ['017 1234-5678'],
            'formatted international' => ['+880 1712-345678'],
        ];
    }

    #[DataProvider('phoneFormats')]
    public function test_phone_numbers_are_normalized(string $input): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.customers.store'), $this->payload(['phone' => $input]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['phone' => '01712345678']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alternatePhoneFormats(): array
    {
        return [
            '+880 format' => ['+8801812345678'],
            '880 format' => ['8801812345678'],
            '00880 format' => ['008801812345678'],
            'missing leading zero' => ['1812345678'],
            'spaces and dashes' => ['018 1234-5678'],
        ];
    }

    #[DataProvider('alternatePhoneFormats')]
    public function test_alternate_phone_numbers_are_normalized_the_same_way(string $input): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.customers.store'), $this->payload(['alternate_phone' => $input]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['phone' => '01712345678', 'alternate_phone' => '01812345678']);
    }

    #[DataProvider('phoneFormats')]
    public function test_the_same_number_in_another_format_is_a_duplicate(string $input): void
    {
        Customer::factory()->create(['phone' => '01712345678']);

        $this->actingAs($this->manager())
            ->post(route('admin.customers.store'), $this->payload(['phone' => $input]))
            ->assertSessionHasErrors(['phone' => 'Another customer already uses this phone number.']);

        $this->assertSame(1, Customer::count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCustomerInput(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 151)], 'name'],
            'missing phone' => [['phone' => ''], 'phone'],
            'phone too short' => [['phone' => '0171234567'], 'phone'],
            'phone too long' => [['phone' => '017123456789'], 'phone'],
            'invalid operator 012' => [['phone' => '01212345678'], 'phone'],
            'landline' => [['phone' => '029876543'], 'phone'],
            'foreign number' => [['phone' => '+14155552671'], 'phone'],
            'letters' => [['phone' => 'call me'], 'phone'],
            'invalid alternate phone' => [['alternate_phone' => '12345'], 'alternate_phone'],
            'alternate equals primary' => [['alternate_phone' => '+8801712345678'], 'alternate_phone'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'email without domain' => [['email' => 'rahima@'], 'email'],
            'email too long' => [['email' => str_repeat('a', 250).'@x.com'], 'email'],
            'address too long' => [['address' => str_repeat('a', 501)], 'address'],
            'city too long' => [['city' => str_repeat('a', 101)], 'city'],
            'area too long' => [['area' => str_repeat('a', 101)], 'area'],
            'invalid postal code' => [['postal_code' => '<12>'], 'postal_code'],
            'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
            'status submitted' => [['status' => 'blocked'], 'status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidCustomerInput')]
    public function test_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.customers.store'), $this->payload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Customer::count());
    }

    public function test_email_is_stored_lowercase(): void
    {
        $this->actingAs($this->manager())->post(route('admin.customers.store'), $this->payload(['email' => 'Rahima@Example.COM']));

        $this->assertSame('rahima@example.com', Customer::sole()->email);
    }

    public function test_extra_fields_cannot_be_mass_assigned(): void
    {
        $this->actingAs($this->manager())->post(route('admin.customers.store'), $this->payload([
            'id' => 999, 'role' => 'super_admin', 'created_at' => '2000-01-01 00:00:00',
        ]))->assertSessionHasNoErrors();

        $customer = Customer::sole();
        $this->assertNotSame(999, $customer->id);
        $this->assertTrue($customer->created_at->isToday());
    }

    public function test_a_lost_race_on_the_phone_index_becomes_a_validation_error(): void
    {
        Customer::factory()->create(['phone' => '01712345678']);

        try {
            app(CustomerService::class)->create(['name' => 'Racer', 'phone' => '+8801712345678']);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $this->assertSame(1, Customer::count());
    }

    public function test_staff_cannot_create_customers(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.customers.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.customers.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, Customer::count());
    }

    // --- Updating ---------------------------------------------------------------------

    public function test_a_customer_can_be_updated(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678']);

        $this->actingAs($this->manager())
            ->put(route('admin.customers.update', $customer), $this->payload([
                'name' => 'Rahima Khatun', 'phone' => '+8801912000000', 'alternate_phone' => '01812000000', 'city' => 'Gazipur',
            ]))
            ->assertRedirect(route('admin.customers.show', $customer))
            ->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('Rahima Khatun', $customer->name);
        $this->assertSame('01912000000', $customer->phone);
        $this->assertSame('01812000000', $customer->alternate_phone);
        $this->assertSame('Gazipur', $customer->city);
    }

    public function test_a_customer_keeps_their_own_phone_in_any_format(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678']);

        $this->actingAs($this->manager())
            ->put(route('admin.customers.update', $customer), $this->payload(['phone' => '+880 1712-345678']))
            ->assertSessionHasNoErrors();

        $this->assertSame('01712345678', $customer->refresh()->phone);
    }

    public function test_updating_to_another_customers_phone_is_rejected(): void
    {
        Customer::factory()->create(['phone' => '01712345678']);
        $customer = Customer::factory()->create(['phone' => '01912345678']);

        $this->actingAs($this->manager())
            ->put(route('admin.customers.update', $customer), $this->payload(['phone' => '8801712345678']))
            ->assertSessionHasErrors('phone');

        $this->assertSame('01912345678', $customer->refresh()->phone);
    }

    public function test_status_cannot_be_changed_through_the_edit_form(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager())
            ->put(route('admin.customers.update', $customer), $this->payload(['status' => 'blocked']))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    public function test_staff_cannot_update_customers(): void
    {
        $customer = Customer::factory()->create(['name' => 'Original']);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.customers.edit', $customer))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.customers.update', $customer), $this->payload())->assertForbidden();

        $this->assertSame('Original', $customer->refresh()->name);
    }

    // --- Status -----------------------------------------------------------------------

    public function test_a_customer_can_be_blocked_and_unblocked(): void
    {
        $customer = Customer::factory()->create(['name' => 'Fake Orderer']);
        $user = $this->manager();

        $this->actingAs($user)
            ->patch(route('admin.customers.status', $customer), ['status' => 'blocked'])
            ->assertSessionHas('success', 'Fake Orderer has been blocked.');
        $this->assertSame(CustomerStatus::Blocked, $customer->refresh()->status);

        $this->actingAs($user)
            ->patch(route('admin.customers.status', $customer), ['status' => 'active'])
            ->assertSessionHas('success', 'Fake Orderer has been unblocked.');
        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    public function test_blocking_keeps_the_customer_and_their_orders(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->manager())->patch(route('admin.customers.status', $order->customer), ['status' => 'blocked']);

        $this->assertModelExists($order->customer);
        $this->assertModelExists($order);
    }

    public function test_invalid_status_values_are_rejected(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager())
            ->from(route('admin.customers.show', $customer))
            ->patch(route('admin.customers.status', $customer), ['status' => 'deleted'])
            ->assertRedirect(route('admin.customers.show', $customer));

        // The validation error is flashed and rendered in the Status panel.
        $this->get(route('admin.customers.show', $customer))->assertSee('The selected status is invalid.');
        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    public function test_staff_cannot_change_customer_status(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.customers.status', $customer), ['status' => 'blocked'])
            ->assertForbidden();

        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    // --- Viewing & order history ---------------------------------------------------------

    public function test_the_details_page_shows_contact_address_status_and_notes(): void
    {
        $customer = Customer::factory()->blocked()->create([
            'name' => 'Nasrin Sultana', 'phone' => '01712345678', 'alternate_phone' => '01812345678',
            'email' => 'nasrin@example.com', 'address' => 'Flat 4B, Lake Road', 'area' => 'Dhanmondi', 'city' => 'Dhaka',
            'postal_code' => '1209', 'notes' => 'Call before delivery.',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('Nasrin Sultana')
            ->assertSee('01712345678')
            ->assertSee('01812345678')
            ->assertSee('nasrin@example.com')
            ->assertSee('Flat 4B, Lake Road')
            ->assertSee('Dhanmondi, Dhaka, 1209')
            ->assertSee('Blocked')
            ->assertSee('Call before delivery.');
    }

    public function test_a_customer_without_orders_shows_an_empty_history(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('This customer has not placed any orders yet.')
            ->assertSee('৳0.00');
    }

    public function test_order_history_lists_only_this_customers_orders(): void
    {
        $customer = Customer::factory()->create();
        $mine = Order::factory()->for($customer)->create(['order_number' => 'ORD-261004-MINE01', 'total' => '1060.00']);
        OrderItem::factory()->for($mine)->count(2)->create();
        Order::factory()->create(['order_number' => 'ORD-261004-OTHER1']);

        $this->actingAs($this->manager())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('ORD-261004-MINE01')
            ->assertSee('৳1,060.00')
            ->assertDontSee('ORD-261004-OTHER1')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->items_count === 2);
    }

    public function test_order_history_is_newest_first_and_paginated(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->for($customer)->count(12)->create();

        $this->actingAs($this->manager())
            ->get(route('admin.customers.show', $customer))
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 12
                && $orders->count() === 10
                && $orders->first()->id === $customer->orders()->max('id'));
    }

    public function test_the_summary_is_computed_from_orders(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->for($customer)->status(OrderStatus::Delivered)->create(['total' => '1000.50']);
        Order::factory()->for($customer)->status(OrderStatus::Pending)->create(['total' => '200.25']);
        Order::factory()->for($customer)->status(OrderStatus::Cancelled)->create(['total' => '999.00']);
        Order::factory()->for($customer)->status(OrderStatus::Returned)->create(['total' => '500.00']);
        Order::factory()->status(OrderStatus::Delivered)->create(['total' => '7777.00']);

        $summary = app(CustomerService::class)->summary($customer);

        $this->assertSame(4, $summary['orders_count']);
        $this->assertSame(1, $summary['completed_orders_count']);
        $this->assertSame(2, $summary['cancelled_or_returned_count']);
        $this->assertSame('1200.75', $summary['total_spent']);
        $this->assertNotNull($summary['last_order_at']);

        $this->actingAs($this->manager())->get(route('admin.customers.show', $customer))->assertSee('৳1,200.75');
    }

    public function test_staff_can_view_customers_but_see_no_mutation_controls(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertDontSee(route('admin.customers.edit', $customer))
            ->assertDontSee('Block customer')
            ->assertDontSee('Delete customer');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertDontSee('New customer');
    }

    // --- Deleting --------------------------------------------------------------------------

    public function test_an_admin_can_delete_a_customer_without_orders(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.customers.destroy', $customer))
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($customer);
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.customers.show', $order->customer))
            ->delete(route('admin.customers.destroy', $order->customer))
            ->assertRedirect(route('admin.customers.show', $order->customer))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has orders'));

        $this->assertModelExists($order->customer);
    }

    public function test_blocking_a_customer_without_orders_does_not_delete_them(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->patch(route('admin.customers.status', $customer), ['status' => 'blocked']);

        $this->assertModelExists($customer);
        $this->assertSame(CustomerStatus::Blocked, $customer->refresh()->status);
    }

    public function test_the_database_constraint_is_the_final_protection(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('customers')->where('id', $order->customer_id)->delete();
    }

    public function test_the_details_page_explains_why_a_customer_with_orders_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.customers.show', $order->customer))
            ->assertSee('must be kept for order history')
            ->assertDontSee('Delete customer</button>', false);
    }

    public function test_managers_and_staff_cannot_delete_customers(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager())->delete(route('admin.customers.destroy', $customer))->assertForbidden();
        $this->actingAs(User::factory()->create())->delete(route('admin.customers.destroy', $customer))->assertForbidden();

        $this->assertModelExists($customer);
    }
}
