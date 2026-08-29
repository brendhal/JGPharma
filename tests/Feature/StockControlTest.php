<?php

use App\Enums\MovementType;
use App\Filament\Resources\BranchInventoryResource\Pages\ListBranchInventories;
use App\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->annex = Branch::create(['code' => 'ANNEX', 'name' => 'Annex Branch']);

    $make = fn (string $sku, string $name) => Product::create([
        'sku' => $sku,
        'name' => $name,
        'cost_price' => 5,
        'selling_price' => 10,
        'minimum_stock' => 20,
    ]);

    $this->low = $make('LOW-1', 'Low Stock Product');
    $this->empty = $make('OUT-1', 'Out Of Stock Product');
    $this->healthy = $make('OK-1', 'Healthy Product');

    $stock = fn (Product $product, int $quantity, Branch $branch) => BranchInventory::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
    ]);

    $this->lowRow = $stock($this->low, 5, $this->main);
    $this->emptyRow = $stock($this->empty, 0, $this->main);
    $this->healthyRow = $stock($this->healthy, 500, $this->main);
    $this->annexRow = $stock($this->low, 5, $this->annex);

    $this->admin = User::factory()->create(['role' => UserRole::ADMIN, 'branch_id' => null]);
});

/* ------------------------------------------------------------ Filters */

test('the low stock filter narrows the table to what is running out', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$this->lowRow, $this->annexRow])
        ->assertCanNotSeeTableRecords([$this->emptyRow, $this->healthyRow]);
});

test('the out of stock filter shows only what has run dry', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->filterTable('out_of_stock')
        ->assertCanSeeTableRecords([$this->emptyRow])
        ->assertCanNotSeeTableRecords([$this->lowRow, $this->healthyRow]);
});

test('the branch filter keeps one branch on screen', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->filterTable('branch_id', $this->annex->id)
        ->assertCanSeeTableRecords([$this->annexRow])
        ->assertCanNotSeeTableRecords([$this->lowRow, $this->emptyRow, $this->healthyRow]);
});

test('an unfiltered table still shows everything', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->assertCanSeeTableRecords([$this->lowRow, $this->emptyRow, $this->healthyRow, $this->annexRow]);
});

/* --------------------------------------------------------- Adjustment */

test('an admin with no branch can adjust stock by choosing one', function () {
    Livewire::actingAs($this->admin)
        ->test(ListStockMovements::class)
        ->callAction('adjustLevels', [
            'branch_id' => $this->main->id,
            'product_id' => $this->low->id,
            'counted_quantity' => 12,
            'remarks' => 'Physical audit',
        ])
        ->assertHasNoActionErrors();

    $movement = StockMovement::query()->latest('id')->first();

    expect($movement)->not->toBeNull()
        ->and($movement->branch_id)->toBe($this->main->id)
        ->and($movement->product_id)->toBe($this->low->id)
        ->and($movement->type)->toBe(MovementType::ADJUSTMENT)
        ->and($movement->quantity)->toBe(7);

    expect($this->lowRow->fresh()->quantity)->toBe(12);
});

test('the adjustment lands on the branch that was picked, not the other one', function () {
    Livewire::actingAs($this->admin)
        ->test(ListStockMovements::class)
        ->callAction('adjustLevels', [
            'branch_id' => $this->annex->id,
            'product_id' => $this->low->id,
            'counted_quantity' => 9,
            'remarks' => 'Physical audit',
        ])
        ->assertHasNoActionErrors();

    expect($this->annexRow->fresh()->quantity)->toBe(9)
        ->and($this->lowRow->fresh()->quantity)->toBe(5);
});

test('a branch manager adjusts against their own branch', function () {
    $manager = User::factory()->create([
        'role' => UserRole::MANAGER,
        'branch_id' => $this->main->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListStockMovements::class)
        ->callAction('adjustLevels', [
            'product_id' => $this->low->id,
            'counted_quantity' => 3,
            'remarks' => 'Shelf count',
        ])
        ->assertHasNoActionErrors();

    expect($this->lowRow->fresh()->quantity)->toBe(3);
});

test('an adjustment cannot be saved without a branch', function () {
    Livewire::actingAs($this->admin)
        ->test(ListStockMovements::class)
        ->callAction('adjustLevels', [
            'product_id' => $this->low->id,
            'counted_quantity' => 12,
            'remarks' => 'Physical audit',
        ])
        ->assertHasActionErrors(['branch_id']);
});

/* ---------------------------------------------------------- Regression */

test('every filter query closure names its first argument $query', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        preg_match_all('/->query\(fn \(([^)]*)\)/', $file->getContents(), $matches);

        foreach ($matches[1] as $parameters) {
            $first = trim(explode(',', $parameters)[0]);

            // Filament injects the table query by PARAMETER NAME. Anything
            // else gets an unrelated builder from the container, and the
            // filter silently stops touching the table.
            if ($first !== '' && ! str_ends_with($first, '$query')) {
                $offenders[] = $file->getRelativePathname().' → '.$first;
            }
        }
    }

    expect($offenders)->toBeEmpty();
});
