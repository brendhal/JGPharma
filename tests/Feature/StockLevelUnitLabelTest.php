<?php

use App\Enums\UserRole;
use App\Filament\Resources\BranchInventoryResource\Pages\ListBranchInventories;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * Naming the unit the shelf count is in.
 *
 * "84" above a breakdown reading "14 Case" invites the reader to take the 84
 * for cases. On hand and Reorder at both carry the piece unit now, so the two
 * lines cannot be mistaken for the same unit.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'PUBM', 'name' => 'JG Drugmart — Public Market']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->gatorade = Product::create([
        'sku' => 'GATORADE-149',
        'name' => 'gatorade',
        'unit' => 'Case',
        'piece_unit' => 'Bottle',
        'pieces_per_box' => 6,
        'cost_price' => 250,
        'selling_price' => 330,
        'minimum_stock' => 120,
        'is_batch_tracked' => false,
    ]);

    $this->row = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->gatorade->id,
        'quantity' => 84,
    ]);
});

test('the on-hand count names the unit it is counted in', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->assertTableColumnFormattedStateSet('quantity', '84 Bottles', $this->row);
});

test('the reorder level names the same unit', function () {
    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->assertTableColumnFormattedStateSet('reorder_level', '120 Bottles', $this->row);
});

test('the breakdown underneath still reads in cases', function () {
    expect($this->gatorade->formatPieces(84))->toBe('14 Case')
        ->and($this->gatorade->formatPieces(120))->toBe('20 Case');
});

test('one of something is not pluralised', function () {
    $this->row->update(['quantity' => 1]);

    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->assertTableColumnFormattedStateSet('quantity', '1 Bottle', $this->row->fresh());
});

test('a product sold whole is named by the only unit it has', function () {
    $thermometer = Product::create([
        'sku' => 'THERM-1',
        'name' => 'Digital Thermometer',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'cost_price' => 165,
        'selling_price' => 245,
        'is_batch_tracked' => false,
    ]);

    $row = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $thermometer->id,
        'quantity' => 3,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListBranchInventories::class)
        ->assertTableColumnFormattedStateSet('quantity', '3 Pieces', $row);
});
