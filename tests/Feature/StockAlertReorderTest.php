<?php

use App\Enums\UserRole;
use App\Filament\Resources\PurchaseResource;
use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'AMOX-500',
        'name' => 'Amoxicillin 500mg Capsule',
        'cost_price' => 7.25,
        'selling_price' => 12.00,
        'minimum_stock' => 20,
    ]);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
    ]);
});

test('the suggested quantity restocks to twice the reorder level', function (int $onHand, int $expected) {
    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => $onHand,
        'minimum_stock' => 20,
    ]);

    expect($inventory->suggested_reorder_quantity)->toBe($expected);
})->with([
    'out of stock' => [0, 40],
    'half way down' => [10, 30],
    'right on the line' => [20, 20],
    'never below one' => [100, 1],
]);

test('the reorder button carries the product, branch and quantity', function () {
    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 0,
        'minimum_stock' => 20,
    ]);

    $url = PurchaseResource::getUrl('create', [
        'product' => $inventory->product_id,
        'branch' => $inventory->branch_id,
        'quantity' => $inventory->suggested_reorder_quantity,
    ]);

    expect($url)
        ->toContain('product='.$this->product->id)
        ->toContain('branch='.$this->branch->id)
        ->toContain('quantity=40');
});

test('a reordered product lands on the first line of the purchase order', function () {
    $this->actingAs($this->admin);

    $items = Livewire::withQueryParams([
        'product' => $this->product->id,
        'branch' => $this->branch->id,
        'quantity' => 40,
    ])->test(CreatePurchase::class)->get('data.items');

    expect($items)->toHaveCount(1);

    $line = reset($items);

    expect((int) $line['product_id'])->toBe($this->product->id)
        ->and((int) $line['quantity'])->toBe(40)
        ->and((float) $line['cost_price'])->toBe(7.25);
});

test('the receiving branch follows the alert that raised it', function () {
    $other = Branch::create(['code' => 'ANNEX', 'name' => 'Annex Branch']);

    $this->actingAs($this->admin);

    $branchId = Livewire::withQueryParams([
        'product' => $this->product->id,
        'branch' => $other->id,
    ])->test(CreatePurchase::class)->get('data.branch_id');

    expect((int) $branchId)->toBe($other->id);
});

test('opening the form directly still gives one blank line', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(CreatePurchase::class);

    $items = $component->get('data.items');

    expect($items)->toHaveCount(1);

    $line = reset($items);

    expect($line['product_id'] ?? null)->toBeNull()
        ->and((int) $component->get('data.branch_id'))->toBe($this->branch->id);
});
