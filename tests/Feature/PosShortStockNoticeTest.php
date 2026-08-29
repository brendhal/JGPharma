<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * Four words, mid-queue.
 *
 * The short-stock notice used to name the product and count out what was left
 * — a sentence telling a cashier what they are already holding. It says one
 * thing now, and says it the same way whether the shelf ran out on the first
 * scan or on the fifth press of plus.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'barcode' => '4800000000044',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    // Exactly one box on the shelf.
    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 100,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);
});

test('adding beyond the shelf says only that stock is short', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id);

    // Read the line's own key rather than guessing how units are keyed.
    $key = array_key_first($till->get('cart'));

    // The expected notice carries no body, so a notice that still spelled the
    // product out would fail this rather than quietly passing on the title.
    $till->call('increment', $key)
        ->assertNotified(
            Notification::make()->title('Not enough stock')->warning()
        );
});

test('a second scan of a sold-out line is refused the same way', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->call('addToCart', $this->product->id)
        ->assertNotified(
            Notification::make()->title('Not enough stock')->warning()
        );

    // Refused, not silently stacked: still one box in the cart.
    expect($till->get('cart'))->toHaveCount(1);
});

test('a sale inside the shelf raises nothing', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->assertNotNotified('Not enough stock');
});
