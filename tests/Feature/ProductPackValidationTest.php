<?php

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * The pack repeater validates a row against a field outside the row, which is
 * exactly the case Filament's built-in lt() gets wrong. These drive the real
 * form so the rule is proven, not assumed.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin);

    $this->base = [
        'name' => 'Neozep Forte Tablet',
        'sku' => 'NEOZEP-1',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 500,
        'cost_price' => 900,
        'selling_price' => 1400,
        'minimum_stock' => 10,
    ];
});

test('a blister smaller than the box saves without complaint', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'packs' => [['label' => 'Blister', 'pieces' => 10]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', 'NEOZEP-1')->firstOrFail();

    expect($product->packs)->toHaveCount(1)
        ->and($product->packs->first()->pieces)->toBe(10)
        ->and($product->packs->first()->label)->toBe('Blister');
});

test('two different pack sizes both save', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'name' => 'Dry Comfort Diapers',
            'sku' => 'DIAP-L',
            'unit' => 'Case',
            'piece_unit' => 'pc',
            'pieces_per_box' => 48,
            'packs' => [
                ['label' => 'Pack of 12', 'pieces' => 12],
                ['label' => 'Pack of 6', 'pieces' => 6],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'DIAP-L')->firstOrFail()->packs)->toHaveCount(2);
});

test('a pack as big as the whole unit is refused', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'pieces_per_box' => 20,
            'packs' => [['label' => 'Blister', 'pieces' => 20]],
        ])
        ->call('create')
        ->assertHasFormErrors(['packs.0.pieces']);

    expect(Product::where('sku', 'NEOZEP-1')->exists())->toBeFalse();
});

test('a pack bigger than the whole unit is refused', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'pieces_per_box' => 20,
            'packs' => [['label' => 'Blister', 'pieces' => 50]],
        ])
        ->call('create')
        ->assertHasFormErrors(['packs.0.pieces']);
});

test('the same pack size twice is caught before it hits the database', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'packs' => [
                ['label' => 'Blister', 'pieces' => 10],
                ['label' => 'Strip', 'pieces' => 10],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['packs.0.pieces', 'packs.1.pieces']);
});

test('a product with no pack sizes at all still saves', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([...$this->base, 'packs' => []])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'NEOZEP-1')->firstOrFail()->packs)->toHaveCount(0);
});

test('an existing product can gain and lose pack sizes on the edit form', function () {
    $product = Product::create([
        ...$this->base,
        'sku' => 'EDIT-1',
        'is_batch_tracked' => false,
    ]);

    $blister = $product->packs()->create(['label' => 'Blister', 'pieces' => 10]);

    Livewire::test(App\Filament\Resources\ProductResource\Pages\EditProduct::class, [
        'record' => $product->getKey(),
    ])
        ->fillForm(fn (array $state): array => [
            ...$state,
            'packs' => [
                ['label' => 'Blister', 'pieces' => 10],
                ['label' => 'Half box', 'pieces' => 250],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->packs->pluck('pieces')->sort()->values()->all())->toBe([10, 250]);

    // And removing one takes it off the till.
    Livewire::test(App\Filament\Resources\ProductResource\Pages\EditProduct::class, [
        'record' => $product->getKey(),
    ])
        ->fillForm(fn (array $state): array => [...$state, 'packs' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->packs)->toHaveCount(0)
        ->and(array_keys($product->fresh()->sellingUnitOptions()))->toBe(['BOX', 'PIECE']);
});
