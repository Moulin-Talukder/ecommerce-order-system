<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerStatusRequest;
use App\Models\Customer;
use App\Services\Customers\CustomerService;
use App\Support\Like;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('search', ''));
        $status = CustomerStatus::tryFrom((string) $request->query('status'));

        $customers = Customer::query()
            ->withCount('orders')
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->when($status, fn (Builder $query, CustomerStatus $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Customer::class);

        return view('admin.customers.create', ['customer' => new Customer]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->validated());

        return to_route('admin.customers.show', $customer)->with('success', "Customer {$customer->name} created.");
    }

    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        return view('admin.customers.show', [
            'customer' => $customer,
            'summary' => $this->customers->summary($customer),
            'orders' => $customer->orders()->withCount('items')->latest('id')->paginate(10),
        ]);
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('admin.customers.edit', ['customer' => $customer]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());

        return to_route('admin.customers.show', $customer)->with('success', "Customer {$customer->name} updated.");
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, Customer $customer): RedirectResponse
    {
        $status = $request->enum('status', CustomerStatus::class);
        $this->customers->setStatus($customer, $status);

        return back()->with('success', $status === CustomerStatus::Blocked
            ? "{$customer->name} has been blocked."
            : "{$customer->name} has been unblocked.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $this->customers->delete($customer);

        return to_route('admin.customers.index')->with('success', "Customer {$customer->name} deleted.");
    }

    /**
     * Name/email substring search; phone search matches the normalized number
     * exactly when a full number is typed, otherwise a digit fragment.
     *
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $normalizedPhone = PhoneNumber::normalize($search);
        $digits = preg_replace('/\D+/', '', $search);

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        $query->where(function (Builder $query) use ($search, $normalizedPhone, $digits): void {
            $query->where('name', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search));

            if ($normalizedPhone !== null) {
                $query->orWhere('phone', $normalizedPhone)->orWhere('alternate_phone', $normalizedPhone);
            } elseif (strlen($digits) >= 3) {
                $query->orWhere('phone', 'like', Like::contains($digits))->orWhere('alternate_phone', 'like', Like::contains($digits));
            }
        });
    }
}
