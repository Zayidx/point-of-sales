<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Unit;
use App\Services\RecipeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecipeController extends Controller
{
    public function __construct(private readonly RecipeService $recipeService) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard/Recipes/Index', [
            'menus' => Product::query()->with(['units:id,code,name,symbol', 'recipeVersions.unit:id,code,name,symbol', 'recipeVersions.items.ingredient:id,name', 'recipeVersions.items.unit:id,symbol'])->orderBy('title')->paginate(20)->withQueryString(),
            'ingredients' => Ingredient::where('is_active', true)->with('baseUnit:id,symbol')->orderBy('name')->get(['id', 'name', 'base_unit_id']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'symbol']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_id' => ['required', 'integer', 'exists:units,id'],
        ]);

        $this->recipeService->createVersion(
            Product::findOrFail($validated['product_id']),
            $validated['items'],
            $validated['yield_quantity'],
            $validated['notes'] ?? null,
            $request->user()->id,
            $validated['unit_id'] ?? null,
        );

        return back()->with('success', 'Versi resep baru berhasil disimpan.');
    }
}
