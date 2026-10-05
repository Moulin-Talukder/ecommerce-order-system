@props(['entries'])

{{-- Chronological order events built by App\Services\Orders\OrderTimeline. --}}
<ol class="relative space-y-4 border-l border-gray-200 pl-5 text-sm">
    @foreach ($entries as $entry)
        <li class="relative">
            <span aria-hidden="true" @class([
                'absolute -left-[26px] top-1 size-3 rounded-full ring-4 ring-white',
                'bg-blue-500' => $entry->tone === 'blue',
                'bg-green-500' => $entry->tone === 'green',
                'bg-red-500' => $entry->tone === 'red',
                'bg-yellow-400' => $entry->tone === 'yellow',
                'bg-gray-400' => $entry->tone === 'gray',
            ])></span>
            <div class="font-medium text-gray-900">{{ $entry->title }}</div>
            <div class="text-xs text-gray-500">
                <time datetime="{{ $entry->at->toIso8601String() }}">{{ $entry->at->format('d M Y, h:i A') }}</time>
                · {{ $entry->actor ?? 'System' }}
            </div>
            @if ($entry->detail)
                <p class="mt-1 text-gray-700">{{ $entry->detail }}</p>
            @endif
        </li>
    @endforeach
</ol>
