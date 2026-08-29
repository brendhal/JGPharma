<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;

/**
 * The printable purchase order a supplier receives.
 *
 * It is a picking list: what to send and how much of it. No buying prices
 * appear on it — the supplier quotes their own on the invoice. It stays
 * inside the same fence as the purchasing screens all the same: management
 * only, and only for orders in their branch.
 */
beforeEach(function () {
    $this->branch = Branch::create([
        'code' => 'MAIN',
        'name' => 'Public Market Branch',
        'address' => '12 Market Street',
    ]);

    $this->other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);

    $this->admin = User::factory()->create([
        'name' => 'Ada Admin',
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->supplier = Supplier::create([
        'name' => 'MediSupply Ph',
        'contact_person' => 'Rita Reyes',
        'address' => '5 Warehouse Road',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'sku' => 'AMOX-500',
        'name' => 'Amoxicillin 500mg Capsule',
        'unit' => 'Box',
        'piece_unit' => 'Capsule',
        'pieces_per_box' => 100,
        'cost_price' => 145,
        'selling_price' => 220,
        'is_batch_tracked' => true,
    ]);

    $this->purchase = Purchase::create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'purchase_date' => now(),
    ]);

    $this->purchase->items()->create([
        'product_id' => $this->product->id,
        'quantity' => 7,
        'cost_price' => 145,
    ]);
});

it('prints the order with its supplier, items and quantities', function () {
    $this->actingAs($this->admin)
        ->get(route('purchases.print', $this->purchase))
        ->assertOk()
        ->assertSee($this->purchase->reference_number)
        ->assertSee('MediSupply Ph')
        ->assertSee('Public Market Branch')
        ->assertSee('Amoxicillin 500mg Capsule')
        ->assertSee('Qty ordered')
        ->assertSee('7')
        ->assertSee('Received by (supplier)');
});

it('keeps buying prices off the sheet the supplier is handed', function () {
    $this->actingAs($this->admin)
        ->get(route('purchases.print', $this->purchase))
        ->assertOk()
        // 7 boxes at 145 — neither the unit cost nor the order value belongs
        // on a document whose only job is to say what to deliver.
        ->assertDontSee('145.00')
        ->assertDontSee('1,015.00')
        ->assertDontSee('Unit cost')
        ->assertDontSee('Amount');
});

it('is closed to staff outside management', function () {
    $cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($cashier)
        ->get(route('purchases.print', $this->purchase))
        ->assertForbidden();
});

it('will not print another branch order', function () {
    $manager = User::factory()->create([
        'role' => UserRole::MANAGER,
        'branch_id' => $this->other->id,
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('purchases.print', $this->purchase))
        ->assertNotFound();
});

it('turns guests away', function () {
    $this->get(route('purchases.print', $this->purchase))
        ->assertRedirect();
});
