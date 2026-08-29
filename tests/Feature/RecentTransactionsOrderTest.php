<?php

use App\Enums\PurchaseStatus;
use App\Enums\TransferStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PurchaseResource\Pages\ListPurchases;
use App\Filament\Resources\StockTransferResource\Pages\ListStockTransfers;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

/**
 * Newest first, whatever the status.
 *
 * `purchase_date` is a date with no time in it, so four orders raised on the
 * same day are all equal to a sort on that column alone and come back in
 * whatever order the database feels like — which in practice meant the oldest
 * at the top. The hour each was encoded breaks the tie.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->supplier = Supplier::create(['name' => 'MediSupply Ph']);

    $this->actingAs($this->admin);

    // Timestamps are guarded, so they are stamped after the fact with the
    // model's own clock switched off.
    $this->order = function (string $reference, string $date, string $encodedAt, PurchaseStatus $status): Purchase {
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->admin->id,
            'reference_number' => $reference,
            'purchase_date' => $date,
            'status' => $status,
            'total_amount' => 100,
        ]);

        $purchase->timestamps = false;
        $purchase->created_at = $encodedAt;
        $purchase->updated_at = $encodedAt;
        $purchase->save();

        return $purchase;
    };

    $this->transfer = function (string $number, string $date, string $encodedAt): StockTransfer {
        $transfer = StockTransfer::create([
            'transfer_number' => $number,
            'from_branch_id' => $this->branch->id,
            'to_branch_id' => $this->other->id,
            'user_id' => $this->admin->id,
            'transfer_date' => $date,
            'status' => TransferStatus::PENDING,
        ]);

        $transfer->timestamps = false;
        $transfer->created_at = $encodedAt;
        $transfer->updated_at = $encodedAt;
        $transfer->save();

        return $transfer;
    };
});

/* ----------------------------------------------------------------- Order */

test('same-day orders list newest-encoded first', function () {
    ($this->order)('PO-20260824-0001', '2026-08-24', '2026-08-24 08:00:00', PurchaseStatus::RECEIVED);
    ($this->order)('PO-20260824-0002', '2026-08-24', '2026-08-24 09:30:00', PurchaseStatus::RECEIVED);
    ($this->order)('PO-20260824-0003', '2026-08-24', '2026-08-24 14:15:00', PurchaseStatus::RECEIVED);
    ($this->order)('PO-20260824-0004', '2026-08-24', '2026-08-24 16:45:00', PurchaseStatus::RECEIVED);

    $references = Livewire::test(ListPurchases::class)
        ->instance()
        ->getTableRecords()
        ->pluck('reference_number')
        ->all();

    // The screenshot showed exactly the reverse of this.
    expect($references)->toBe([
        'PO-20260824-0004',
        'PO-20260824-0003',
        'PO-20260824-0002',
        'PO-20260824-0001',
    ]);
});

test('a pending order encoded later outranks a received one from the morning', function () {
    ($this->order)('PO-20260824-0001', '2026-08-24', '2026-08-24 08:00:00', PurchaseStatus::RECEIVED);
    $late = ($this->order)('PO-20260824-0002', '2026-08-24', '2026-08-24 17:00:00', PurchaseStatus::PENDING);

    $records = Livewire::test(ListPurchases::class)->instance()->getTableRecords();

    expect($records->first()->is($late))->toBeTrue()
        ->and($records->first()->status)->toBe(PurchaseStatus::PENDING);
});

test('an older day still sorts below a newer one', function () {
    ($this->order)('PO-20260823-0009', '2026-08-23', '2026-08-23 23:59:00', PurchaseStatus::PENDING);
    $newer = ($this->order)('PO-20260824-0001', '2026-08-24', '2026-08-24 00:01:00', PurchaseStatus::RECEIVED);

    $records = Livewire::test(ListPurchases::class)->instance()->getTableRecords();

    expect($records->first()->is($newer))->toBeTrue();
});

test('sorting the date column ascending flips the tiebreak too', function () {
    $first = ($this->order)('PO-20260824-0001', '2026-08-24', '2026-08-24 08:00:00', PurchaseStatus::RECEIVED);
    ($this->order)('PO-20260824-0002', '2026-08-24', '2026-08-24 16:45:00', PurchaseStatus::RECEIVED);

    $records = Livewire::test(ListPurchases::class)
        ->sortTable('purchase_date', 'asc')
        ->instance()
        ->getTableRecords();

    expect($records->first()->is($first))->toBeTrue();
});

test('stock transfers order the same way', function () {
    $early = ($this->transfer)('TR-0001', '2026-08-24', '2026-08-24 08:00:00');
    $late = ($this->transfer)('TR-0002', '2026-08-24', '2026-08-24 17:00:00');

    $records = Livewire::test(ListStockTransfers::class)->instance()->getTableRecords();

    expect($records->first()->is($late))->toBeTrue()
        ->and($records->last()->is($early))->toBeTrue();
});

/* -------------------------------------------------------------- Timezone */

test('the application runs on Philippine time', function () {
    expect(config('app.timezone'))->toBe('Asia/Manila')
        ->and(now()->getTimezone()->getName())->toBe('Asia/Manila');
});

test('a reference number is stamped from the Philippine date, not UTC', function () {
    // 16:30 UTC on 23 Aug is already 00:30 on 24 Aug in Manila. A PO raised
    // at that moment belongs to the 24th, which is the day the shop is open.
    $this->travelTo(Carbon\Carbon::parse('2026-08-23 16:30:00', 'UTC'), function () {
        expect(now()->format('Ymd'))->toBe('20260824')
            ->and(now()->format('g:i A'))->toBe('12:30 AM');
    });
});

test('an encoded time reads back in Philippine time', function () {
    $order = ($this->order)('PO-20260824-0001', '2026-08-24', '2026-08-24 16:45:00', PurchaseStatus::RECEIVED);

    expect($order->fresh()->created_at->format('g:i A'))->toBe('4:45 PM')
        ->and($order->fresh()->created_at->getTimezone()->getName())->toBe('Asia/Manila');
});
