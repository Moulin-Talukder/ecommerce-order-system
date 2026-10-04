<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Category;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\CategoryTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $categories) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Category::class);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $tree = CategoryTree::load();

        $rows = collect($tree->flattened())
            ->map(fn (array $row) => $row + ['path' => $tree->path($row['category']->id)])
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn (array $row) => Str::contains($row['path'], $search, ignoreCase: true)
                    || Str::contains($row['category']->slug, $search, ignoreCase: true)
            ))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($rows) => $rows->filter(
                fn (array $row) => $row['category']->is_active === ($status === 'active')
            ))
            ->values();

        return view('admin.categories.index', [
            'rows' => $rows,
            'isFiltered' => $search !== '' || filled($status),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Category::class);

        return view('admin.categories.create', [
            'category' => new Category(['is_active' => true, 'parent_id' => $request->integer('parent') ?: null]),
            'parentOptions' => CategoryTree::load()->parentOptions(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = $this->categories->create($request->validated());

        return to_route('admin.categories.index')->with('success', "Category “{$category->name}” created.");
    }

    public function edit(Category $category): View
    {
        Gate::authorize('update', $category);

        $category->loadCount(['products', 'children']);

        return view('admin.categories.edit', [
            'category' => $category,
            'parentOptions' => CategoryTree::load()->parentOptions($category->id),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->categories->update($category, $request->validated());

        return to_route('admin.categories.index')->with('success', "Category “{$category->name}” updated.");
    }

    public function updateStatus(UpdateStatusRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->categories->setActive($category, $request->boolean('is_active'));

        return back()->with('success', "Category “{$category->name}” ".($category->is_active ? 'activated.' : 'deactivated.'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $this->categories->delete($category);

        return to_route('admin.categories.index')->with('success', "Category “{$category->name}” deleted.");
    }
}
