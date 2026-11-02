<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Exceptions\Audit\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_create_records_that_can_be_queried_with_their_actor(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $log = AuditLog::factory()->by($actor)->create();
        AuditLog::factory()->fromCli()->create(['event' => AuditEvent::UserPasswordReset]);

        $this->assertSame(2, AuditLog::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::UserPasswordReset)->where('origin', AuditOrigin::Cli)->whereNull('ip_address')->count());

        $loaded = AuditLog::query()->with('actor')->findOrFail($log->id);
        $this->assertTrue($loaded->actor->is($actor));
        $this->assertSame([$actor->email, 'super_admin'], [$loaded->actor_email, $loaded->actor_role]);
        $this->assertSame(['selling_price' => '1150.00'], $loaded->new_values);
        $this->assertNull($loaded->getAttribute('updated_at'));
        $this->assertStringStartsWith('203.0.113.', $loaded->ip_address);
    }

    public function test_nothing_is_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        new AuditLog(['event' => AuditEvent::UserCreated, 'description' => 'x']);
    }

    /**
     * @return array<string, array{Closure(AuditLog): mixed}>
     */
    public static function modifications(): array
    {
        return [
            'save after change' => [function (AuditLog $log) {
                $log->description = 'Rewritten';

                return $log->save();
            }],
            'save without change' => [fn (AuditLog $log) => $log->save()],
            'saveQuietly' => [fn (AuditLog $log) => $log->forceFill(['description' => 'x'])->saveQuietly()],
            'update' => [fn (AuditLog $log) => $log->update(['description' => 'Rewritten'])],
            'forceFill and push' => [fn (AuditLog $log) => $log->forceFill(['actor_name' => 'Someone else'])->push()],
            'touch' => [fn (AuditLog $log) => $log->touch()],
            'increment' => [fn (AuditLog $log) => $log->increment('subject_id')],
            'delete' => [fn (AuditLog $log) => $log->delete()],
            'deleteQuietly' => [fn (AuditLog $log) => $log->deleteQuietly()],
            'forceDelete' => [fn (AuditLog $log) => $log->forceDelete()],
            'destroy' => [fn (AuditLog $log) => AuditLog::destroy($log->id)],
            'query update' => [fn (AuditLog $log) => AuditLog::query()->whereKey($log->id)->update(['description' => 'Rewritten'])],
            'query increment' => [fn (AuditLog $log) => AuditLog::query()->whereKey($log->id)->increment('subject_id')],
            'query upsert' => [fn (AuditLog $log) => AuditLog::query()->upsert([['id' => $log->id, 'event' => 'user.created', 'origin' => 'admin', 'description' => 'x']], ['id'], ['description'])],
            'query delete' => [fn (AuditLog $log) => AuditLog::query()->whereKey($log->id)->delete()],
            'query forceDelete' => [fn (AuditLog $log) => AuditLog::query()->forceDelete()],
            'truncate through a relation' => [fn (AuditLog $log) => AuditLog::query()->where('actor_id', $log->actor_id)->delete()],
        ];
    }

    #[DataProvider('modifications')]
    public function test_existing_records_cannot_be_changed_or_deleted(Closure $modify): void
    {
        $log = AuditLog::factory()->by(User::factory()->create())->create(['description' => 'Original']);
        $before = AuditLog::query()->toBase()->where('id', $log->id)->first();

        try {
            $modify($log);
            $this->fail('The modification should have been refused.');
        } catch (AuditLogImmutableException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertEquals($before, AuditLog::query()->toBase()->where('id', $log->id)->first());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_new_records_can_still_be_created_after_a_refused_change(): void
    {
        $log = AuditLog::factory()->create();

        try {
            $log->delete();
        } catch (AuditLogImmutableException) {
        }

        $replica = $log->replicate();
        $replica->save();

        $this->assertSame(2, AuditLog::query()->count());
        $this->assertNotSame($log->id, $replica->id);
    }
}
