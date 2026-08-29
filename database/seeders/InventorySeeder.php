<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Fills both branches with stock by booking real purchase orders, so the
 * movement log tells a coherent story from the very first day.
 */
class InventorySeeder extends Seeder
{
    public function run(InventoryService $inventory): void
    {
        $manager = User::where('email', 'manager@jgdrugmart.test')->first();

        if ($manager) {
            Auth::login($manager);
        }

        $suppliers = Supplier::pluck('id', 'name');
        $products = Product::with('medicine')->get();

        foreach (Branch::all() as $branchIndex => $branch) {
            foreach ($products->chunk(7) as $chunkIndex => $chunk) {
                $purchase = Purchase::create([
                    'branch_id' => $branch->id,
                    'supplier_id' => $suppliers->values()[$chunkIndex % $suppliers->count()],
                    'purchase_date' => now()->subDays(20 - $chunkIndex * 3),
                    'remarks' => 'Opening stock delivery',
                ]);

                foreach ($chunk as $product) {
                    // Delivered quantities are counted in whole packs; stock
                    // lands on the shelf in pieces. A little variety so some
                    // items fall into the low-stock and expiring-soon reports
                    // straight away — every figure here stays below the
                    // product's own reorder level to do it.
                    $quantity = match (true) {
                        $product->name === 'Azithromycin 500mg Tablet' => 3,
                        $product->name === 'Atorvastatin 20mg Tablet' => 8,
                        $product->name === 'Salbutamol Inhaler 100mcg' => 0,
                        $product->name === 'Metoprolol 50mg Tablet' => 15,
                        default => random_int(45, 120),
                    };

                    if ($quantity === 0) {
                        continue;
                    }

                    $expiry = $product->is_batch_tracked
                        ? match (true) {
                            $product->name === 'Amoxicillin 500mg Capsule' => now()->addDays(12),
                            $product->name === 'Metformin 500mg Tablet' => now()->addDays(28),
                            $product->name === 'Losartan 50mg Tablet' => now()->addDays(34),
                            default => now()->addMonths(random_int(8, 30)),
                        }
                        : null;

                    $purchase->items()->create([
                        'product_id' => $product->id,
                        'batch_number' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $product->name), 0, 3))
                            .'-'.now()->format('y').random_int(100, 999),
                        'expiration_date' => $expiry,
                        'quantity' => $branchIndex === 0 ? $quantity : (int) ceil($quantity / 2),
                        'cost_price' => $product->cost_price,
                    ]);
                }

                if ($purchase->items()->exists()) {
                    $inventory->receivePurchase($purchase->fresh(['items', 'supplier']));
                } else {
                    $purchase->delete();
                }
            }
        }

        Auth::forgetUser();
    }
}
