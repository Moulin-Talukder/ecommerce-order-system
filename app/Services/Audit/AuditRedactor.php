<?php

namespace App\Services\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use UnitEnum;

/**
 * The privacy boundary for audit records. Everything stored in an audit
 * row's old_values, new_values, context and subject_label passes through
 * here:
 *
 *  1. old/new values keep only the subject type's allowlisted fields, each
 *     with its masking rule, so a newly added column is never logged until
 *     it is listed here;
 *  2. context is free-form, but personal-data keys are masked by name;
 *  3. a recursive denylist then drops secrets (keys normalized, so
 *     "Remember-Token" or "API_KEY" cannot slip through) and secret-looking
 *     values such as password hashes or bearer tokens, whatever the
 *     allowlist says;
 *  4. strings are capped at MAX_STRING characters, and a JSON column that
 *     would still exceed MAX_JSON_BYTES is replaced by an explicit
 *     "omitted" marker rather than being cut into a misleading fragment.
 *
 * Objects are never serialized (a model or an auth event passed by mistake
 * is stored as UNSUPPORTED), so hidden attributes and event credentials
 * cannot leak through toArray() or __toString().
 */
class AuditRedactor
{
    public const MAX_STRING = 500;

    public const MAX_JSON_BYTES = 16384;

    public const MAX_DEPTH = 5;

    /** Stands in for free text (addresses, notes) that is never stored. */
    public const CHANGED = '[changed]';

    public const UNSUPPORTED = '[unsupported]';

    private const MASK = '•';

    private const PLAIN = 'plain';

    private const NAME = 'name';

    private const EMAIL = 'email';

    private const PHONE = 'phone';

    private const TEXT = 'text';

    private const REFERENCE = 'reference';

    /**
     * Fields that may be stored in old/new values, per subject type, with
     * their masking rule.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWLIST = [
        'user' => [
            'name' => self::PLAIN, 'email' => self::PLAIN, 'role' => self::PLAIN, 'is_active' => self::PLAIN,
        ],
        'product' => [
            'category_id' => self::PLAIN, 'brand_id' => self::PLAIN, 'name' => self::PLAIN, 'slug' => self::PLAIN,
            'sku' => self::PLAIN, 'purchase_price' => self::PLAIN, 'selling_price' => self::PLAIN,
            'discount_price' => self::PLAIN, 'manage_stock' => self::PLAIN, 'stock_quantity' => self::PLAIN,
            'stock_status' => self::PLAIN, 'low_stock_threshold' => self::PLAIN, 'status' => self::PLAIN,
            'is_featured' => self::PLAIN, 'weight' => self::PLAIN, 'length' => self::PLAIN, 'width' => self::PLAIN,
            'height' => self::PLAIN, 'short_description' => self::TEXT, 'description' => self::TEXT,
            'meta_title' => self::TEXT, 'meta_description' => self::TEXT,
        ],
        'product_image' => [
            'product_id' => self::PLAIN, 'alt_text' => self::PLAIN, 'sort_order' => self::PLAIN,
        ],
        'category' => [
            'parent_id' => self::PLAIN, 'name' => self::PLAIN, 'slug' => self::PLAIN, 'is_active' => self::PLAIN,
            'sort_order' => self::PLAIN, 'description' => self::TEXT, 'meta_title' => self::TEXT,
            'meta_description' => self::TEXT,
        ],
        'brand' => [
            'name' => self::PLAIN, 'slug' => self::PLAIN, 'is_active' => self::PLAIN, 'description' => self::TEXT,
        ],
        'customer' => [
            'name' => self::NAME, 'email' => self::EMAIL, 'phone' => self::PHONE, 'alternate_phone' => self::PHONE,
            'address' => self::TEXT, 'notes' => self::TEXT, 'status' => self::PLAIN,
        ],
        'order' => [
            'order_number' => self::PLAIN, 'customer_id' => self::PLAIN, 'customer_name' => self::NAME,
            'customer_phone' => self::PHONE, 'customer_alternate_phone' => self::PHONE,
            'customer_email' => self::EMAIL, 'shipping_address' => self::TEXT, 'customer_note' => self::TEXT,
            'admin_note' => self::TEXT, 'currency' => self::PLAIN, 'subtotal' => self::PLAIN,
            'discount_total' => self::PLAIN, 'delivery_charge' => self::PLAIN, 'total' => self::PLAIN,
            'amount_paid' => self::PLAIN, 'payment_method' => self::PLAIN, 'payment_status' => self::PLAIN,
            'status' => self::PLAIN, 'source' => self::PLAIN,
        ],
        'payment' => [
            'order_id' => self::PLAIN, 'type' => self::PLAIN, 'method' => self::PLAIN, 'status' => self::PLAIN,
            'amount' => self::PLAIN, 'currency' => self::PLAIN, 'transaction_reference' => self::REFERENCE,
            'paid_at' => self::PLAIN, 'note' => self::TEXT,
        ],
        'courier_shipment' => [
            'order_id' => self::PLAIN, 'courier' => self::PLAIN, 'invoice' => self::PLAIN,
            'consignment_id' => self::PLAIN, 'tracking_code' => self::PLAIN, 'status' => self::PLAIN,
            'cod_amount' => self::PLAIN, 'delivery_fee' => self::PLAIN, 'last_error' => self::TEXT,
        ],
    ];

    /**
     * Personal-data keys in context, by normalized key name.
     *
     * @var array<string, string>
     */
    private const CONTEXT_RULES = [
        'customername' => self::NAME, 'recipientname' => self::NAME,
        'email' => self::EMAIL, 'customeremail' => self::EMAIL, 'recipientemail' => self::EMAIL,
        'phone' => self::PHONE, 'mobile' => self::PHONE, 'alternatephone' => self::PHONE,
        'customerphone' => self::PHONE, 'customeralternatephone' => self::PHONE, 'recipientphone' => self::PHONE,
        'address' => self::TEXT, 'shippingaddress' => self::TEXT, 'recipientaddress' => self::TEXT,
        'note' => self::TEXT, 'notes' => self::TEXT, 'customernote' => self::TEXT, 'adminnote' => self::TEXT,
        'lasterror' => self::TEXT, 'trackingmessage' => self::TEXT,
        'reference' => self::REFERENCE, 'transactionreference' => self::REFERENCE,
    ];

    /**
     * Normalized keys that are always dropped, wherever they appear.
     *
     * @var list<string>
     */
    private const DENIED_KEYS = [
        'session', 'sessionid', 'sessionkey', 'laravelsession', 'pin', 'otp', 'cvv', 'cvc', 'cardnumber',
    ];

    /**
     * A normalized key containing any of these is always dropped.
     *
     * @var list<string>
     */
    private const DENIED_KEY_FRAGMENTS = [
        'password', 'passwd', 'token', 'secret', 'apikey', 'accesskey', 'privatekey', 'authorization',
        'payload', 'gatewayresponse', 'credential', 'cookie', 'csrf', 'xsrf',
    ];

    /**
     * Values that look like credentials are dropped even under an innocent key.
     *
     * @var list<string>
     */
    private const SECRET_VALUE_PATTERNS = [
        '/^\$2[abxy]\$\d{2}\$/',           // bcrypt hash
        '/^\$argon2(i|d|id)\$/',           // argon2 hash
        '/^bearer\s+\S/i',                 // Authorization header value
        '/^eyJ[\w-]+\.eyJ[\w-]+\.[\w-]*$/', // JWT
    ];

    private object $dropped;

    public function __construct()
    {
        $this->dropped = new stdClass;
    }

    /**
     * Old or new attribute values of a subject: only the subject type's
     * allowlisted fields survive. Without a subject type nothing does.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>|null null when nothing is left
     */
    public function values(?string $subjectType, array $values): ?array
    {
        $rules = self::ALLOWLIST[$subjectType] ?? [];
        $clean = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! isset($rules[$key]) || $this->isDeniedKey($key)) {
                continue;
            }

            $value = $this->applyRule($rules[$key], $value, 1);

            if ($value !== $this->dropped) {
                $clean[$key] = $value;
            }
        }

        return $this->fit($clean);
    }

    /**
     * The fields of a subject type that may be audited.
     *
     * @return list<string>
     */
    public function fields(string $subjectType): array
    {
        return array_keys(self::ALLOWLIST[$subjectType] ?? []);
    }

    /**
     * Free-form context: secrets dropped, personal data masked by key name.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>|null null when nothing is left
     */
    public function context(array $context): ?array
    {
        return $this->fit($this->cleanArray($context, 1));
    }

    /**
     * A display label that identifies the subject without exposing personal data.
     */
    public function subjectLabel(string $subjectType, Model $subject): ?string
    {
        $label = match ($subjectType) {
            'user' => $subject->getAttribute('email'),
            'product' => $subject->getAttribute('sku'),
            'product_image' => 'Image #'.$subject->getKey(),
            'category', 'brand' => $subject->getAttribute('name'),
            'customer' => implode(' · ', array_filter([$this->maskName($subject->getAttribute('name')), $this->maskPhone($subject->getAttribute('phone'))])),
            'order' => $subject->getAttribute('order_number'),
            'payment' => ucfirst((string) $this->scalar($subject->getAttribute('type'))).' #'.$subject->getKey(),
            'courier_shipment' => $subject->getAttribute('invoice'),
            default => null,
        };

        if (! is_string($label) || $label === '' || $this->isSecretValue($label)) {
            return null;
        }

        return $this->truncate($label, 191);
    }

    /**
     * Cap a string at $max characters (multibyte-safe, invalid UTF-8 replaced).
     */
    public function truncate(?string $value, int $max = self::MAX_STRING): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_scrub($value, 'UTF-8');

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    /**
     * "Rahim Uddin" -> "R••• U•••".
     */
    public function maskName(mixed $name): ?string
    {
        $name = $this->scalar($name);

        if ($name === null || trim($name) === '') {
            return null;
        }

        return implode(' ', array_map(
            fn (string $word) => mb_substr($word, 0, 1).str_repeat(self::MASK, 3),
            preg_split('/\s+/u', trim($name)) ?: [],
        ));
    }

    /**
     * "rahim@example.com" -> "r•••@example.com".
     */
    public function maskEmail(mixed $email): ?string
    {
        $email = $this->scalar($email);

        if ($email === null || $email === '') {
            return null;
        }

        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return str_repeat(self::MASK, 3);
        }

        $local = mb_substr($email, 0, $at);
        $first = mb_strlen($local) > 1 ? mb_substr($local, 0, 1) : '';

        return $this->truncate($first.str_repeat(self::MASK, 3).'@'.mb_substr($email, $at + 1));
    }

    /**
     * Only the last three digits: "01712345678" -> "••••••••678".
     */
    public function maskPhone(mixed $phone): ?string
    {
        $phone = $this->scalar($phone);

        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $phone);

        return strlen($digits) > 3 ? str_repeat(self::MASK, strlen($digits) - 3).substr($digits, -3) : str_repeat(self::MASK, 3);
    }

    /**
     * Only the last four characters: "TRX-889100234" -> "••••0234".
     */
    public function maskReference(mixed $reference): ?string
    {
        $reference = $this->scalar($reference);

        if ($reference === null || $reference === '') {
            return null;
        }

        return str_repeat(self::MASK, 4).(mb_strlen($reference) > 4 ? mb_substr($reference, -4) : '');
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     */
    private function cleanArray(array $array, int $depth): array
    {
        $clean = [];

        foreach ($array as $key => $value) {
            if (is_string($key) && $this->isDeniedKey($key)) {
                continue;
            }

            $rule = is_string($key) ? (self::CONTEXT_RULES[$this->normalizeKey($key)] ?? self::PLAIN) : self::PLAIN;
            $value = $this->applyRule($rule, $value, $depth);

            if ($value !== $this->dropped) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function applyRule(string $rule, mixed $value, int $depth): mixed
    {
        if ($rule === self::PLAIN) {
            return $this->clean($value, $depth);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if ($rule === self::TEXT) {
            return self::CHANGED;
        }

        if ($this->scalar($value) === null) {
            return self::UNSUPPORTED;
        }

        return match ($rule) {
            self::NAME => $this->maskName($value),
            self::EMAIL => $this->maskEmail($value),
            self::PHONE => $this->maskPhone($value),
            self::REFERENCE => $this->maskReference($value),
        };
    }

    private function clean(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : self::UNSUPPORTED,
            is_string($value) => $this->isSecretValue($value) ? $this->dropped : $this->truncate($value),
            $value instanceof BackedEnum => $this->clean($value->value, $depth),
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_array($value) => $depth >= self::MAX_DEPTH ? self::UNSUPPORTED : $this->cleanArray($value, $depth + 1),
            default => self::UNSUPPORTED,
        };
    }

    /**
     * A scalar (or backed enum) as a string; anything else is null.
     */
    private function scalar(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            default => null,
        };
    }

    /**
     * Lower-case and strip everything but letters and digits, so
     * "Remember-Token", "remember_token" and "REMEMBERTOKEN" compare equal.
     */
    private function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }

    private function isDeniedKey(string $key): bool
    {
        $key = $this->normalizeKey($key);

        if (in_array($key, self::DENIED_KEYS, true)) {
            return true;
        }

        foreach (self::DENIED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isSecretValue(string $value): bool
    {
        $value = trim($value);

        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>|null
     */
    private function fit(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $json = json_encode($values);

        if ($json === false) {
            return ['_omitted' => 'unencodable'];
        }

        if (strlen($json) > self::MAX_JSON_BYTES) {
            return ['_omitted' => 'too_large', '_bytes' => strlen($json)];
        }

        return $values;
    }
}
