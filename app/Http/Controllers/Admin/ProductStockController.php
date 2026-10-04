<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StockAdjustmentRequest;
use App\Http\Requests\Admin\UpdateProductAvailabilityRequest;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Illuminate\Http\RedirectResponse;

class ProductStockController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function store(StockAdjustmentRequest $request, Product $product): RedirectResponse
    {
        $movement = $this->stock->adjust(
            $product,
            $request->integer('quantity_change'),
            $request->enum('type', StockMovementType::class),
            $request->user(),
            $request->validated('note'),
        );

        return to_route('admin.products.show', $product)
            ->with('success', sprintf('Stock %s by %d. New quantity: %d.', $movement->quantity_change > 0 ? 'increased' : 'reduced', abs($movement->quantity_change), $movement->quantity_after));
    }

    public function availability(UpdateProductAvailabilityRequest $request, Product $product): RedirectResponse
    {
        $status = $request->enum('stock_status', StockStatus::class);
        $this->stock->setManualStatus($product, $status);

        return to_route('admin.products.show', $product)->with('success', "Availability set to “{$status->label()}”.");
    }
}
