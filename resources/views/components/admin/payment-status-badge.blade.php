@props(['status'])

<x-admin.badge :tone="match ($status) {
    App\Enums\PaymentStatus::Paid => 'green',
    App\Enums\PaymentStatus::PartiallyPaid => 'blue',
    App\Enums\PaymentStatus::Pending => 'yellow',
    App\Enums\PaymentStatus::Failed => 'red',
    App\Enums\PaymentStatus::Refunded, App\Enums\PaymentStatus::PartiallyRefunded => 'gray',
}">{{ $status->label() }}</x-admin.badge>
