<?php

use App\Enums\TransferStatus;
use App\Enums\UserRole;
use App\Filament\Resources\StockTransferResource\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransferResource\Pages\ListStockTransfers;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Livewire\Livewire;

/**
 * A transfer that says it shipped has to have moved the stock.
 *
 * The status column carries a PENDING database default and nothing set it in
 * PHP, so a freshly created transfer held a null status in memory. The items
 * repeater asks the record whether it is still pending to decide if its lines
 * are editable; a null status read as "not pending", the repeater disabled
 * itself, and a disabled repeater never runs saveRelationships(). The transfer
 * saved with no lines, then approving it looped over nothing and still marked
 * itself APPROVED — stock levels never moved.
 */
beforeEach(function () {
    $this->main = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->annex = Branch::create(['code' => 'ANNEX', 'name' => 'Annex Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    $this->tracked = Product::create([
        'sku' => 'BIOG-500',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => true,
    ]);

    $this->loose = Product::create([
        'sku' => 'ALCO-500',
        'name' => 'Green Cross Alcohol 500ml',
        'unit' => 'Bottle',
        'cost_price' => 70,
        'selling_price' => 110,
        'is_batch_tracked' => false,
    ]);

    ProductBatch::create([
        'branch_id' => $this->main->id,
        'product_id' => $this->tracked->id,
        'batch_number' => 'B-001',
        'expiration_date' => now()->addYear(),
        'quantity' => 100,
        'cost_price' => 60,
    ]);

    BranchInventory::create([
        'branch_id' => $this->main->id,
        'product_id' => $this->tracked->id,
        'quantity' => 100,
    ]);

    BranchInventory::create([
        'branch_id' => $this->main->id,
        'product_id' => $this->loose->id,
        'quantity' => 40,
    ]);
});

/** On-hand for a product at a branch, ignoring whose branch is signed in. */
function transferOnHand(Branch $branch, Product $product): int
{
    return (int) BranchInventory::withoutGlobalScopes()
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

function createTransferViaForm(User $actor, Branch $from, Branch $to, Product $product, int $quantity): StockTransfer
{
    Livewire::actingAs($actor)
        ->test(CreateStockTransfer::class)
        ->fillForm([
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'transfer_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return StockTransfer::withoutGlobalScopes()->latest('id')->firstOrFail();
}

/* ------------------------------------------------------------ The lines */

test('a new transfer keeps the lines that were typed into it', function () {
    $transfer = createTransferViaForm($this->admin, $this->main, $this->annex, $this->tracked, 30);

    expect($transfer->items()->count())->toBe(1)
        ->and($transfer->items()->first()->quantity)->toBe(30);
});

test('a new transfer starts pending in memory, not only in the database', function () {
    $transfer = new StockTransfer([
        'from_branch_id' => $this->main->id,
        'to_branch_id' => $this->annex->id,
    ]);

    $transfer->save();

    expect($transfer->status)->toBe(TransferStatus::PENDING)
        ->and($transfer->isPending())->toBeTrue();
});

/* -------------------------------------------------------- Moving stock */

test('approving a transfer takes the stock out of the sending branch', function () {
    $transfer = createTransferViaForm($this->admin, $this->main, $this->annex, $this->tracked, 30);

    Livewire::actingAs($this->admin)
        ->test(ListStockTransfers::class)
        ->callTableAction('approve', $transfer);

    expect($transfer->fresh()->status)->toBe(TransferStatus::APPROVED)
        ->and(transferOnHand($this->main, $this->tracked))->toBe(70)
        ->and(transferOnHand($this->annex, $this->tracked))->toBe(0);
});

test('confirming receipt puts the stock into the receiving branch', function () {
    $transfer = createTransferViaForm($this->admin, $this->main, $this->annex, $this->tracked, 30);

    Livewire::actingAs($this->admin)
        ->test(ListStockTransfers::class)
        ->callTableAction('approve', $transfer);

    Livewire::actingAs($this->admin)
        ->test(ListStockTransfers::class)
        ->callTableAction('receive', $transfer->fresh());

    expect($transfer->fresh()->status)->toBe(TransferStatus::RECEIVED)
        ->and(transferOnHand($this->main, $this->tracked))->toBe(70)
        ->and(transferOnHand($this->annex, $this->tracked))->toBe(30);
});

test('a product that is not batch tracked can still be transferred', function () {
    $transfer = createTransferViaForm($this->admin, $this->main, $this->annex, $this->loose, 15);

    $this->actingAs($this->admin);
    app(InventoryService::class)->shipTransfer($transfer);
    app(InventoryService::class)->receiveTransfer($transfer->fresh());

    expect(transferOnHand($this->main, $this->loose))->toBe(25)
        ->and(transferOnHand($this->annex, $this->loose))->toBe(15);
});

test('a transfer cannot ship more than the branch has', function () {
    $transfer = createTransferViaForm($this->admin, $this->main, $this->annex, $this->loose, 999);

    $this->actingAs($this->admin);

    expect(fn () => app(InventoryService::class)->shipTransfer($transfer))
        ->toThrow(RuntimeException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::PENDING)
        ->and(transferOnHand($this->main, $this->loose))->toBe(40);
});

/* ------------------------------------------------------ The empty case */

test('an empty transfer refuses to ship instead of quietly approving itself', function () {
    $transfer = StockTransfer::create([
        'from_branch_id' => $this->main->id,
        'to_branch_id' => $this->annex->id,
    ]);

    $this->actingAs($this->admin);

    expect(fn () => app(InventoryService::class)->shipTransfer($transfer))
        ->toThrow(RuntimeException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::PENDING);
});
