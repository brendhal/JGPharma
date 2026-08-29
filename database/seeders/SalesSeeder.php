<?php

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Models\BranchInventory;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Rings up a week of realistic sales so the dashboard charts have shape.
 */
class SalesSeeder extends Seeder
{
    public function run(PosService $pos): void
    {
        $cashiers = User::whereIn('email', [
            'sales@jgdrugmart.test',
            'cashier@jgdrugmart.test',
            'manager@jgdrugmart.test',
        ])->get();

        if ($cashiers->isEmpty()) {
            return;
        }

        foreach (range(6, 0) as $daysAgo) {
            $ticketCount = $daysAgo === 0 ? random_int(4, 8) : random_int(6, 14);

            for ($i = 0; $i < $ticketCount; $i++) {
                $cashier = $cashiers->random();

                if (! $cashier->branch_id) {
                    continue;
                }

                Auth::login($cashier);

                $available = BranchInventory::withoutGlobalScopes()
                    ->where('branch_id', $cashier->branch_id)
                    ->where('quantity', '>', 5)
                    ->with('product')
                    ->inRandomOrder()
                    ->limit(random_int(1, 4))
                    ->get();

                if ($available->isEmpty()) {
                    Auth::forgetUser();

                    continue;
                }

                $cart = $available->map(fn (BranchInventory $row) => [
                    'product_id' => $row->product_id,
                    'quantity' => random_int(1, 3),
                    'unit_price' => (float) $row->product->selling_price,
                    'discount_percent' => 0,
                ])->all();

                $discountType = random_int(1, 10) === 1 ? DiscountType::SENIOR : DiscountType::NONE;
                $totals = $pos->totals($cart, $discountType);

                try {
                    $sale = $pos->checkout(
                        cart: $cart,
                        branchId: $cashier->branch_id,
                        amountPaid: ceil($totals['total'] / 50) * 50,
                        paymentMethod: random_int(1, 6) === 1 ? PaymentMethod::CARD : PaymentMethod::CASH,
                        discountType: $discountType,
                        discountPercent: 0,
                        customerName: null,
                        discountIdNumber: $discountType === DiscountType::SENIOR ? 'SC-'.random_int(10000, 99999) : null,
                    );

                    // Backdate so the 7-day chart is populated.
                    $timestamp = now()->subDays($daysAgo)
                        ->setTime(random_int(8, 19), random_int(0, 59));

                    Sale::withoutGlobalScopes()
                        ->whereKey($sale->id)
                        ->update(['sale_date' => $timestamp, 'created_at' => $timestamp]);
                } catch (\Throwable $e) {
                    // Not enough stock for this random basket — skip the ticket.
                }

                Auth::forgetUser();
            }
        }
    }
}
