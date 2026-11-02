<?php

namespace Tests\Unit\Audit;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class AuditRedactorTest extends TestCase
{
    private AuditRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new AuditRedactor;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenKeys(): array
    {
        return array_combine($keys = [
            'password', 'password_confirmation', 'current_password', 'password_hash', 'remember_token',
            'session', 'session_id', '_token', 'csrf_token', 'xsrf', 'authorization', 'api_token',
            'webhook_token', 'access_token', 'secret', 'client_secret', 'api_key', 'private_key',
            'request_payload', 'response_payload', 'gateway_response', 'credentials', 'cookie', 'cvv', 'card_number',
        ], array_map(fn (string $key) => [$key], $keys));
    }

    #[DataProvider('forbiddenKeys')]
    public function test_every_forbidden_key_is_removed(string $key): void
    {
        $this->assertSame(['order_id' => 7], $this->redactor->context(['order_id' => 7, $key => 'leaked-value']));
    }

    public function test_sensitive_keys_are_removed_at_any_depth(): void
    {
        $context = $this->redactor->context([
            'request' => ['headers' => ['authorization' => 'Bearer abc', 'accept' => 'text/html'], 'body' => ['password' => 'x', 'qty' => 2]],
            'items' => [['sku' => 'A1', 'api_key' => 'k'], ['sku' => 'B2', 'remember_token' => 't']],
        ]);

        $this->assertSame([
            'request' => ['headers' => ['accept' => 'text/html'], 'body' => ['qty' => 2]],
            'items' => [['sku' => 'A1'], ['sku' => 'B2']],
        ], $context);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disguisedKeys(): array
    {
        return array_combine($keys = [
            'PASSWORD', 'PassWord', 'Remember-Token', 'REMEMBER_TOKEN', 'remember token', 'API_KEY', 'X-Api-Key',
            'apiKey', 'X-CSRF-TOKEN', 'Session-ID', 'sessionId', 'Gateway.Response', 'Authorization', 'new_password_1',
        ], array_map(fn (string $key) => [$key], $keys));
    }

    #[DataProvider('disguisedKeys')]
    public function test_case_and_separator_variations_are_still_removed(string $key): void
    {
        $this->assertNull($this->redactor->context([$key => 'leaked-value']));
        $this->assertSame(['nested' => []], $this->redactor->context(['nested' => [$key => 'leaked-value']]));
    }

    public function test_secret_looking_values_are_dropped_even_under_harmless_or_allowlisted_keys(): void
    {
        $context = $this->redactor->context([
            'hash' => '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
            'argon' => '$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA',
            'header' => 'Bearer 1|plain-api-token',
            'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig',
            'kept' => 'ordinary text',
        ]);

        $this->assertSame(['kept' => 'ordinary text'], $context);
        $this->assertNull($this->redactor->values('user', ['name' => '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234']));
    }

    public function test_only_allowlisted_fields_of_the_subject_type_are_kept(): void
    {
        $values = $this->redactor->values('user', [
            'name' => 'Karim Staff', 'email' => 'karim@example.test', 'role' => 'staff', 'is_active' => true,
            'email_verified_at' => '2026-01-01', 'brand_new_column' => 'x', 'id' => 4, 0 => 'list item',
        ]);

        $this->assertSame(['name' => 'Karim Staff', 'email' => 'karim@example.test', 'role' => 'staff', 'is_active' => true], $values);
        $this->assertNull($this->redactor->values('product', ['description' => 'Long marketing copy', 'path' => 'x.jpg']));
        $this->assertNull($this->redactor->values(null, ['name' => 'Anything']), 'Without a subject type nothing is kept.');
        $this->assertNull($this->redactor->values('unknown_type', ['name' => 'Anything']));
    }

    public function test_product_image_values_never_include_the_file_location(): void
    {
        $this->assertSame(
            ['alt_text' => 'Front view', 'sort_order' => 0],
            $this->redactor->values('product_image', ['disk' => 'public', 'path' => 'products/1/a.jpg', 'alt_text' => 'Front view', 'sort_order' => 0]),
        );
    }

    public function test_emails_keep_only_the_first_letter_and_the_domain(): void
    {
        $this->assertSame('r•••@example.com', $this->redactor->maskEmail('rahim.uddin@example.com'));
        $this->assertSame('•••@example.com', $this->redactor->maskEmail('r@example.com'));
        $this->assertSame('•••', $this->redactor->maskEmail('not-an-email'));
        $this->assertNull($this->redactor->maskEmail(null));
        $this->assertSame(['customer_email' => 'c•••@example.net'], $this->redactor->context(['customer_email' => 'customer@example.net']));
    }

    public function test_phones_keep_only_the_last_three_digits(): void
    {
        $this->assertSame('••••••••678', $this->redactor->maskPhone('01712345678'));
        $this->assertSame('••••••••••678', $this->redactor->maskPhone('+880 1712-345678'));
        $this->assertSame('•••', $this->redactor->maskPhone('12'));
        $this->assertSame(
            ['phone' => '••••••••678', 'alternate_phone' => '••••••••111'],
            $this->redactor->values('customer', ['phone' => '01712345678', 'alternate_phone' => '01811111111']),
        );
    }

    public function test_addresses_and_free_text_notes_are_never_stored(): void
    {
        $values = $this->redactor->values('order', [
            'shipping_address' => 'House 12, Road 5, Dhanmondi',
            'customer_note' => 'Call my sister on 01911111111',
            'admin_note' => '',
        ]);
        $context = $this->redactor->context(['note' => 'Customer said: house 12', 'address' => null]);

        $this->assertSame(['shipping_address' => AuditRedactor::CHANGED, 'customer_note' => AuditRedactor::CHANGED, 'admin_note' => null], $values);
        $this->assertSame(['note' => AuditRedactor::CHANGED, 'address' => null], $context);
        $this->assertStringNotContainsString('Dhanmondi', (string) json_encode([$values, $context]));
    }

    public function test_transaction_references_keep_only_the_last_four_characters(): void
    {
        $this->assertSame('••••0234', $this->redactor->maskReference('TRX-889100234'));
        $this->assertSame('••••', $this->redactor->maskReference('ABC'));
        $this->assertSame(
            ['transaction_reference' => '••••0234', 'amount' => '500.00', 'type' => 'refund'],
            $this->redactor->values('payment', ['transaction_reference' => 'TRX-889100234', 'amount' => '500.00', 'type' => PaymentType::Refund, 'gateway_response' => ['card' => '4111']]),
        );
    }

    public function test_customer_names_and_labels_are_masked(): void
    {
        $customer = new Customer(['name' => 'Rahim Uddin', 'phone' => '01712345678', 'email' => 'rahim@example.test']);

        $this->assertSame('R••• U••• · ••••••••678', $this->redactor->subjectLabel('customer', $customer));
        $this->assertSame(
            ['name' => 'R••• U•••', 'email' => 'r•••@example.test', 'phone' => '••••••••678'],
            $this->redactor->values('customer', $customer->only(['name', 'email', 'phone'])),
        );
        $this->assertSame(['customer_name' => 'R••• U•••'], $this->redactor->values('order', ['customer_name' => 'Rahim Uddin']));
    }

    public function test_other_subject_labels_identify_the_record_without_personal_data(): void
    {
        $order = (new Order)->forceFill(['order_number' => 'ORD-20261101-0001', 'customer_name' => 'Rahim Uddin']);
        $payment = (new Payment)->forceFill(['id' => 12, 'type' => PaymentType::Refund]);

        $this->assertSame('ORD-20261101-0001', $this->redactor->subjectLabel('order', $order));
        $this->assertSame('Refund #12', $this->redactor->subjectLabel('payment', $payment));
        $this->assertSame('SKU-1', $this->redactor->subjectLabel('product', new Product(['sku' => 'SKU-1'])));
        $this->assertSame('staff@example.test', $this->redactor->subjectLabel('user', new User(['email' => 'staff@example.test'])));
    }

    public function test_long_strings_are_capped(): void
    {
        $context = $this->redactor->context(['message' => str_repeat('a', 600), 'name' => str_repeat('ব', 600)]);

        $this->assertSame(AuditRedactor::MAX_STRING, mb_strlen($context['message']));
        $this->assertStringEndsWith('…', $context['message']);
        $this->assertSame(AuditRedactor::MAX_STRING, mb_strlen($context['name']));
        $this->assertSame(500, mb_strlen($this->redactor->truncate(str_repeat('x', 900))));
        $this->assertSame('short', $this->redactor->truncate('short'));
    }

    public function test_oversized_json_is_replaced_by_an_explicit_marker_not_cut(): void
    {
        $context = $this->redactor->context(array_fill_keys(array_map(fn (int $i) => "line_{$i}", range(1, 60)), str_repeat('x', 400)));

        $this->assertSame('too_large', $context['_omitted']);
        $this->assertGreaterThan(AuditRedactor::MAX_JSON_BYTES, $context['_bytes']);
        $this->assertCount(2, $context);
        $this->assertNotFalse(json_encode($context));
    }

    public function test_unsupported_structures_are_handled_without_serializing_them(): void
    {
        $user = new User(['name' => 'Hidden', 'email' => 'hidden@example.test', 'password' => 'never-serialized-1A']);
        $failed = new Failed('web', null, ['email' => 'x@example.test', 'password' => 'plaintext-attempt-1A']);
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]];

        $context = $this->redactor->context([
            'object' => new stdClass,
            'model' => $user,
            'event' => $failed,
            'infinite' => INF,
            'invalid_utf8' => "caf\xC3",
            'status' => OrderStatus::Cancelled,
            'at' => CarbonImmutable::parse('2026-11-02 10:00:00', 'Asia/Dhaka'),
            'list' => [1, 2, 3],
            'deep' => $deep,
        ]);
        $json = (string) json_encode($context);

        $this->assertSame(AuditRedactor::UNSUPPORTED, $context['object']);
        $this->assertSame(AuditRedactor::UNSUPPORTED, $context['model']);
        $this->assertSame(AuditRedactor::UNSUPPORTED, $context['event']);
        $this->assertSame(AuditRedactor::UNSUPPORTED, $context['infinite']);
        $this->assertTrue(mb_check_encoding($context['invalid_utf8'], 'UTF-8'));
        $this->assertSame('cancelled', $context['status']);
        $this->assertSame('2026-11-02T10:00:00+06:00', $context['at']);
        $this->assertSame([1, 2, 3], $context['list']);
        $this->assertSame(AuditRedactor::UNSUPPORTED, $context['deep']['a']['b']['c']['d']);
        $this->assertStringNotContainsString('never-serialized', $json);
        $this->assertStringNotContainsString('plaintext-attempt', $json);
    }

    public function test_masked_fields_given_non_scalar_values_are_not_stored(): void
    {
        $this->assertSame(
            ['email' => AuditRedactor::UNSUPPORTED, 'phone' => AuditRedactor::UNSUPPORTED, 'address' => AuditRedactor::CHANGED],
            $this->redactor->values('customer', ['email' => ['a@example.test'], 'phone' => new stdClass, 'address' => ['line' => 'x']]),
        );
    }

    public function test_empty_input_stores_nothing(): void
    {
        $this->assertNull($this->redactor->values('user', []));
        $this->assertNull($this->redactor->context([]));
        $this->assertNull($this->redactor->context(['password' => 'x']));
    }
}
