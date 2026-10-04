<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandRequest;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Brand;
use App\Services\Catalog\BrandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function __construct(private BrandService $brands) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Brand::class);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $brands = Brand::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.brands.index', [
            'brands' => $brands,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Brand::class);

        return view('admin.brands.create', [
            'brand' => new Brand(['is_active' => true]),
        ]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $brand = $this->brands->create($request->validated());

        return to_route('admin.brands.index')->with('success', "Brand “{$brand->name}” created.");
    }

    public function edit(Brand $brand): View
    {
        Gate::authorize('update', $brand);

        $brand->loadCount('products');

        return view('admin.brands.edit', [
            'brand' => $brand,
        ]);
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->brands->update($brand, $request->validated());

        return to_route('admin.brands.index')->with('success', "Brand “{$brand->name}” updated.");
    }

    public function updateStatus(UpdateStatusRequest $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('update', $brand);

        $this->brands->setActive($brand, $request->boolean('is_active'));

        return back()->with('success', "Brand “{$brand->name}” ".($brand->is_active ? 'activated.' : 'deactivated.'));
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        Gate::authorize('delete', $brand);

        $this->brands->delete($brand);

        return to_route('admin.brands.index')->with('success', "Brand “{$brand->name}” deleted.");
    }
}
