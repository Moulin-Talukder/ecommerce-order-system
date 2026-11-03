<x-layouts::admin title="Audit entry #{{ $log->id }}">
    <div class="mb-4">
        <a href="{{ route('admin.audit-logs.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">← Back to audit log</a>
    </div>

    <x-admin.page-header :title="$log->event->label()" :description="'Audit entry #'.$log->id.' · '.$log->event->value" />

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">Summary</h2>
            <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                <dt class="text-gray-500">When</dt>
                <dd class="col-span-2 text-gray-900">{{ $log->created_at?->format('d M Y, h:i:s A') }}</dd>
                <dt class="text-gray-500">Origin</dt>
                <dd class="col-span-2 text-gray-900">{{ $log->origin->label() }}</dd>
                <dt class="text-gray-500">Description</dt>
                <dd class="col-span-2 text-gray-900">{{ $log->description }}</dd>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">Who and what</h2>
            <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                <dt class="text-gray-500">Done by</dt>
                <dd class="col-span-2 text-gray-900">
                    @if ($log->actor_name)
                        {{ $log->actor_name }}
                        <span class="text-gray-500">· {{ App\Enums\UserRole::tryFrom((string) $log->actor_role)?->label() ?? $log->actor_role }}</span>
                    @else
                        <span class="text-gray-500">No signed-in user</span>
                    @endif
                </dd>
                <dt class="text-gray-500">Subject</dt>
                <dd class="col-span-2 text-gray-900">
                    @if ($log->subject_type)
                        {{ $log->subject_label ?? '—' }}
                        <div class="text-xs text-gray-500">{{ Str::of($log->subject_type)->replace('_', ' ')->ucfirst() }} #{{ $log->subject_id }}</div>
                    @else
                        <span class="text-gray-500">—</span>
                    @endif
                </dd>
            </dl>
            <p class="mt-4 text-xs text-gray-500">Names, roles and labels are shown as they were when the entry was recorded.</p>
        </section>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white">
        <h2 class="border-b border-gray-200 px-6 py-4 text-sm font-semibold text-gray-900">Values</h2>
        @if ($changes === [])
            <p class="px-6 py-6 text-sm text-gray-500">No field values were recorded for this entry.</p>
        @else
            <div class="relative overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <tr>
                            <th scope="col" class="px-6 py-3">Field</th>
                            @if ($hasOld)
                                <th scope="col" class="px-6 py-3">Before</th>
                            @endif
                            @if ($hasNew)
                                <th scope="col" class="px-6 py-3">{{ $hasOld ? 'After' : 'Value' }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($changes as $change)
                            <tr>
                                <td class="px-6 py-3 font-mono text-xs text-gray-700">{{ $change['field'] }}</td>
                                @if ($hasOld)
                                    <td class="px-6 py-3 break-all text-gray-700">{{ $change['old'] ?? '—' }}</td>
                                @endif
                                @if ($hasNew)
                                    <td class="px-6 py-3 break-all text-gray-900">{{ $change['new'] ?? '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white">
        <h2 class="border-b border-gray-200 px-6 py-4 text-sm font-semibold text-gray-900">Context</h2>
        @if ($context === [])
            <p class="px-6 py-6 text-sm text-gray-500">No additional context.</p>
        @else
            <dl class="divide-y divide-gray-100 text-sm">
                @foreach ($context as $key => $value)
                    <div class="grid gap-1 px-6 py-3 sm:grid-cols-3">
                        <dt class="font-mono text-xs text-gray-700">{{ $key }}</dt>
                        <dd class="break-all text-gray-900 sm:col-span-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    <section class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-6">
        <h2 class="mb-1 text-sm font-semibold text-gray-900">Security metadata</h2>
        <p class="mb-4 text-xs text-gray-500">Network details of the request that caused this entry. Empty for command-line and system actions.</p>
        <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
            <dt class="text-gray-500">IP address</dt>
            <dd class="col-span-2 font-mono text-xs text-gray-900">{{ $log->ip_address ?? '—' }}</dd>
            <dt class="text-gray-500">User agent</dt>
            <dd class="col-span-2 break-all font-mono text-xs text-gray-900">{{ $log->user_agent ?? '—' }}</dd>
        </dl>
    </section>
</x-layouts::admin>
