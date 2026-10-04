@props(['status'])

<x-admin.badge :tone="$status === App\Enums\CustomerStatus::Blocked ? 'red' : 'green'">{{ $status->label() }}</x-admin.badge>
