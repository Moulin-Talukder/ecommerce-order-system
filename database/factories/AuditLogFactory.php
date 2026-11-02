<?php

namespace Database\Factories;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictional audit rows: example.* emails, documentation-range IPs
 * (RFC 5737) and no credentials of any kind.
 *
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sku = 'SKU-'.fake()->unique()->numerify('#####');

        return [
            'event' => AuditEvent::ProductUpdated,
            'origin' => AuditOrigin::Admin,
            'actor_id' => null,
            'actor_name' => null,
            'actor_email' => null,
            'actor_role' => null,
            'subject_type' => 'product',
            'subject_id' => fake()->numberBetween(1, 5000),
            'subject_label' => $sku,
            'description' => AuditEvent::ProductUpdated->label(),
            'old_values' => ['selling_price' => '1200.00'],
            'new_values' => ['selling_price' => '1150.00'],
            'context' => null,
            'ip_address' => '203.0.113.'.fake()->numberBetween(1, 254),
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) ExampleBrowser/1.0',
        ];
    }

    /**
     * Performed by the given user, with their identity snapshotted.
     */
    public function by(User $user): static
    {
        return $this->state(fn () => [
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => $user->role->value,
        ]);
    }

    /**
     * Recorded by an Artisan command (no request details).
     */
    public function fromCli(): static
    {
        return $this->state(fn () => [
            'origin' => AuditOrigin::Cli,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
