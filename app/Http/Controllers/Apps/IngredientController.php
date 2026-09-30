<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\Unit;
use App\Services\InventoryLedgerService;
use App\Services\OutletAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IngredientController extends Controller
{
    public function index(Request $request, OutletAccessService $outletAccess): Response
    {
        $warehouses = $outletAccess->warehousesFor($request->user());
        $warehouseIds = $warehouses->pluck('id');
        $page = Ingredient::query()
            ->with(['category:id,name', 'baseUnit:id,code,name,symbol'])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        $balances = InventoryBalance::query()
            ->where('item_type', 'ingredient')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('item_id', $page->getCollection()->pluck('id'))
            ->get(['warehouse_id', 'item_id', 'quantity'])
            ->groupBy('item_id');
        $page->through(function (Ingredient $ingredient) use ($balances, $warehouses) {
            $byWarehouse = $balances->get($ingredient->id, collect())->keyBy('warehouse_id');
            $ingredient->setAttribute('stock_by_warehouse', $warehouses->map(fn ($warehouse) => [
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'quantity' => $byWarehouse->get($warehouse->id)?->quantity ?? '0.0000',
            ])->values());

            return $ingredient;
        });

        return Inertia::render('Dashboard/Ingredients/Index', [
            'ingredients' => $page,
            'categories' => IngredientCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'code', 'name', 'symbol']),
            'warehouses' => $warehouses,
            'canAdjustStock' => $request->user()->can('ingredients-adjust'),
        ]);
    }

    public function adjustStock(Request $request, Ingredient $ingredient, OutletAccessService $outletAccess, InventoryLedgerService $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'not_in:0', 'between:-999999999999,999999999999'],
            'reason' => ['required', 'string', 'max:500'],
            'adjustment_key' => ['required', 'uuid'],
        ]);
        abort_unless($outletAccess->warehousesFor($request->user())->contains('id', (int) $validated['warehouse_id']), 403);

        $balance = InventoryBalance::where('balance_key', "warehouse:{$validated['warehouse_id']}:ingredient:{$ingredient->id}")->first();
        $ledger->record([
            'idempotency_key' => "ingredient-adjust:{$validated['adjustment_key']}",
            'item_type' => 'ingredient',
            'item_id' => $ingredient->id,
            'location_type' => 'warehouse',
            'location_id' => (int) $validated['warehouse_id'],
            'movement_type' => 'stock_adjustment',
            'quantity' => (string) $validated['quantity'],
            'unit_id' => $ingredient->base_unit_id,
            'unit_cost' => (string) ($balance?->average_unit_cost ?? $ingredient->default_unit_cost),
            'reference_type' => Ingredient::class,
            'reference_id' => $ingredient->id,
            'reference_number' => 'IA-'.strtoupper(substr($validated['adjustment_key'], 0, 12)),
            'notes' => $validated['reason'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Penyesuaian stok bahan baku berhasil dicatat.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:ingredients,code'],
            'name' => ['required', 'string', 'max:150'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'default_unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        Ingredient::create($validated + ['is_active' => true]);

        return back()->with('success', 'Bahan baku berhasil ditambahkan.');
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('ingredients', 'code')->ignore($ingredient->id)],
            'name' => ['required', 'string', 'max:150'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'default_unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $ingredient->update($validated);

        return back()->with('success', 'Bahan baku berhasil diperbarui.');
    }
}
