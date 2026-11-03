<x-layouts::admin title="Audit log">
    <x-admin.page-header title="Audit log" description="Read-only record of sign-ins and changes across the admin panel and storefront. Personal data is stored masked." />

    @php
        $inputClass = 'block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900';
        $eventGroups = collect(App\Enums\AuditEvent::cases())->groupBy(fn (App\Enums\AuditEvent $case) => Str::of($case->value)->before('.')->replace('_', ' ')->ucfirst()->toString());
        $filtered = $filters['event'] || $filters['origin'] || $filters['subject_type'] || $filters['actor'] || $filters['from'] !== '' || $filters['to'] !== '' || $filters['search'] !== '';
    @endphp

    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="mb-1 block text-xs font-medium text-gray-600">Subject</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Order number, SKU, name…" class="{{ $inputClass }}">
        </div>
        <div>
            <label for="event" class="mb-1 block text-xs font-medium text-gray-600">Event</label>
            <select id="event" name="event" class="{{ $inputClass }}">
                <option value="">All events</option>
                @foreach ($eventGroups as $group => $cases)
                    <optgroup label="{{ $group }}">
                        @foreach ($cases as $case)
                            <option value="{{ $case->value }}" @selected($filters['event'] === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="subject_type" class="mb-1 block text-xs font-medium text-gray-600">Subject type</label>
            <select id="subject_type" name="subject_type" class="{{ $inputClass }}">
                <option value="">All types</option>
                @foreach (App\Models\AuditLog::SUBJECT_TYPES as $type)
                    <option value="{{ $type }}" @selected($filters['subject_type'] === $type)>{{ Str::of($type)->replace('_', ' ')->ucfirst() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="origin" class="mb-1 block text-xs font-medium text-gray-600">Origin</label>
            <select id="origin" name="origin" class="{{ $inputClass }}">
                <option value="">All origins</option>
                @foreach (App\Enums\AuditOrigin::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['origin'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="actor" class="mb-1 block text-xs font-medium text-gray-600">Done by</label>
            <select id="actor" name="actor" class="{{ $inputClass }}">
                <option value="">Anyone</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" @selected($filters['actor'] === $actor->id)>{{ $actor->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="mb-1 block text-xs font-medium text-gray-600">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="{{ $inputClass }}">
        </div>
        <div>
            <label for="to" class="mb-1 block text-xs font-medium text-gray-600">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="{{ $inputClass }}">
        </div>
        <div class="flex items-end gap-3">
            <x-admin.button variant="secondary">Filter</x-admin.button>
            @if ($filtered)
                <a href="{{ route('admin.audit-logs.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear</a>
            @endif
        </div>
    </form>

    @if ($dateError)
        <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $dateError }}</div>
    @endif

    <div class="relative overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">When</th>
                    <th scope="col" class="px-4 py-3">Event</th>
                    <th scope="col" class="px-4 py-3">Subject</th>
                    <th scope="col" class="px-4 py-3">Done by</th>
                    <th scope="col" class="px-4 py-3">Origin</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $log->event->label() }}</div>
                            <div class="text-xs text-gray-500">{{ $log->event->value }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            @if ($log->subject_type)
                                <div>{{ $log->subject_label ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ Str::of($log->subject_type)->replace('_', ' ')->ucfirst() }} #{{ $log->subject_id }}</div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            @if ($log->actor_name)
                                <div>{{ $log->actor_name }}</div>
                                <div class="text-xs text-gray-500">{{ App\Enums\UserRole::tryFrom((string) $log->actor_role)?->label() ?? $log->actor_role }}</div>
                            @else
                                <span class="text-gray-400">No signed-in user</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap"><x-admin.badge>{{ $log->origin->label() }}</x-admin.badge></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.audit-logs.show', $log) }}" class="font-medium text-gray-900 hover:underline">View<span class="sr-only"> entry {{ $log->id }}</span></a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">No audit entries match your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts::admin>
