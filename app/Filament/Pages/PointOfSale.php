<?php

namespace App\Filament\Pages;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InventoryService;
use App\Services\PosService;
use App\Support\SellingOption;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The cashier's terminal.
 *
 * Scan (or type) a code, the item drops into the cart, F2 jumps to cash
 * received, Enter takes the payment. Stock is deducted first-expiring-first
 * the moment the sale is committed.
 */
class PointOfSale extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Point of Sale';

    protected static ?string $navigationLabel = 'New sale (POS)';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.point-of-sale';

    /** How far back the reprint list reaches. */
    private const REPRINTABLE = 12;

    /** The code typed or scanned into the search box. */
    public string $scan = '';

    /**
     * Cart lines, keyed "productId:UNIT" so the same product can sit in the
     * cart twice — a full box on one line, three loose tablets on another.
     *
     * @var array<string, array{product_id:int, name:string, generic:?string, unit:string, unit_label:string, unit_multiplier:int, unit_options:array<string, string>, batch:?string, expiry:?string, unit_price:float, quantity:int, discount_percent:float, stock:int}>
     */
    public array $cart = [];

    public string $paymentMethod = 'CASH';

    public string $discountType = 'NONE';

    /**
     * A manual discount, as a percentage, to two decimal places.
     *
     * Nullable because the cashier can empty the box. Livewire hands a cleared
     * number input over as null; a non-nullable float rejects that, and
     * Livewire answers the TypeError by unsetting the property — after which
     * every read of it is fatal, so clearing the field took the whole till
     * down rather than reading as no discount.
     */
    public ?float $discountPercent = 0;

    public ?float $amountPaid = null;

    /** The GCash / card transaction number, kept for reconciliation. */
    public ?string $paymentReference = null;

    public ?string $customerName = null;

    public ?string $discountIdNumber = null;

    /** Populated when a scan matches more than one product. */
    public array $suggestions = [];

    /** Set after a successful checkout so the receipt can be opened. */
    public ?int $lastSaleId = null;

    public ?string $lastInvoiceNumber = null;

    /* ========================================================= Parked cart */

    /**
     * Where an unpaid cart waits between page loads.
     *
     * A cashier who refreshes — or whose browser reloads mid-transaction —
     * gets the basket back instead of re-scanning it. Keyed per user so two
     * cashiers sharing a terminal never inherit each other's sale, and never
     * holding anything but an open cart: checkout and Remove all both clear it.
     */
    protected function cartSessionKey(): string
    {
        return 'pos.cart.'.(auth()->id() ?? 'guest');
    }

    public function mount(): void
    {
        $this->restoreParkedCart();
    }

    /** Runs at the end of every Livewire request, refresh included. */
    public function dehydrate(): void
    {
        session()->put($this->cartSessionKey(), $this->cart);
    }

    /**
     * Rebuild the parked cart against the shelf as it stands now.
     *
     * Stock moves while a cart sits parked — another till sells the last box,
     * a product is delisted — so every line is re-checked rather than trusted.
     * A line that no longer fits is trimmed, and one that cannot fit at all is
     * dropped, with a single notice rather than one per line.
     */
    protected function restoreParkedCart(): void
    {
        $parked = session($this->cartSessionKey());

        if (! is_array($parked) || $parked === [] || ! $this->branchId()) {
            return;
        }

        $products = Product::query()
            ->active()
            ->with(['medicine', 'packs'])
            ->findMany(collect($parked)->pluck('product_id')->filter()->unique()->all())
            ->keyBy('id');

        $cart = [];
        $reserved = [];
        $dropped = 0;
        $trimmed = 0;

        foreach ($parked as $key => $line) {
            $product = $products->get($line['product_id'] ?? null);

            if (! $product || ! is_array($line)) {
                $dropped++;

                continue;
            }

            $available = $this->inventoryService()->availableStock($this->branchId(), $product->id);
            $multiplier = max(1, (int) ($line['unit_multiplier'] ?? 1));
            $spare = $available - ($reserved[$product->id] ?? 0);
            $wanted = max(1, (int) ($line['quantity'] ?? 1));
            $quantity = min($wanted, intdiv(max(0, $spare), $multiplier));

            if ($quantity < 1) {
                $dropped++;

                continue;
            }

            if ($quantity < $wanted) {
                $trimmed++;
            }

            $reserved[$product->id] = ($reserved[$product->id] ?? 0) + ($quantity * $multiplier);

            $cart[$key] = [
                ...$line,
                'quantity' => $quantity,
                'stock' => $available,
                'stock_label' => $product->formatPieces($available),
            ];
        }

        $this->cart = $cart;

        if ($dropped > 0 || $trimmed > 0) {
            Notification::make()
                ->title('Cart restored with changes')
                ->body(collect([
                    $dropped > 0 ? "{$dropped} line(s) removed — out of stock or no longer sold." : null,
                    $trimmed > 0 ? "{$trimmed} line(s) reduced to what is on hand." : null,
                ])->filter()->join(' '))
                ->warning()
                ->send();
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Point of Sale';
    }

    public function getSubheading(): ?string
    {
        $branch = auth()->user()->branch?->name ?? 'No branch assigned';

        return $branch.' · '.now()->format('l, d F Y');
    }

    /* =========================================================== Reprinting */

    /**
     * A way back to a receipt without a sales history to browse.
     *
     * A customer returns holding nothing, or the printer chewed the paper, and
     * the cashier needs that ticket again. The full history is management's
     * now, so what the till offers instead is the short list that actually
     * answers the question: what this cashier has rung up today.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reprintReceipt')
                ->label('Reprint a receipt')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->modalHeading('Rung up at this till today')
                ->modalDescription('Opens the receipt in a new tab, ready to print again.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.pages.partials.todays-receipts', [
                    'sales' => $this->todaysSales(),
                ])),
        ];
    }

    /**
     * This cashier's completed sales today, newest first.
     *
     * Their own only, and only today: a reprint is a same-shift errand, and
     * anything older is a question for whoever holds the history.
     *
     * @return Collection<int, Sale>
     */
    public function todaysSales(): Collection
    {
        return Sale::query()
            ->completed()
            ->today()
            ->where('user_id', auth()->id())
            ->orderByDesc('sale_date')
            ->limit(self::REPRINTABLE)
            ->get();
    }

    /* ============================================================ Scanning */

    /**
     * A finished code: an exact barcode/SKU match goes straight into the
     * cart, anything else shows a short pick-list.
     *
     * The scanner hands its code over directly rather than through the search
     * box's bound property. A gun types a thirteen-digit barcode in about a
     * tenth of a second, which is faster than the box's debounce — waiting for
     * that round trip is how a scan ends up sitting in the box instead of in
     * the cart. The typed-and-Enter path passes nothing and still reads the
     * box, so nothing changes for a cashier searching by hand.
     */
    public function submitScan(?string $code = null): void
    {
        $term = trim($code ?? $this->scan);

        if ($term === '') {
            return;
        }

        $exact = Product::query()
            ->active()
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->first();

        if ($exact) {
            $this->addToCart($exact->id);

            return;
        }

        $matches = $this->posService()->search($term, $this->branchId());

        if ($matches->count() === 1) {
            $this->addToCart($matches->first()->id);

            return;
        }

        if ($matches->isEmpty()) {
            Notification::make()
                ->title('No product found')
                ->body("Nothing matches \"{$term}\". Check the barcode, or search by name.")
                ->warning()
                ->send();

            $this->clearScanBox();

            return;
        }

        // More than one match: leave the term in the box so the cashier can
        // narrow it, and let them pick.
        $this->scan = $term;
        $this->suggestions = $this->presentSuggestions($matches);
    }

    /** Live pick-list while typing a product name. */
    public function updatedScan(string $value): void
    {
        $value = trim($value);

        if (strlen($value) < 2) {
            $this->suggestions = [];

            return;
        }

        $this->suggestions = $this->presentSuggestions(
            $this->posService()->search($value, $this->branchId())
        );
    }

    protected function presentSuggestions(Collection $products): array
    {
        return $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'generic' => $product->medicine?->full_name,
            'price' => (float) $product->selling_price,
            'stock' => (int) ($product->stock_on_hand ?? 0),
            'unit' => $product->unit,
            'stock_label' => $product->formatPieces((int) ($product->stock_on_hand ?? 0)),
        ])->all();
    }

    /**
     * Empty the search box and put the cursor back in it.
     *
     * Every scan ends here, right or wrong, because the next one is already
     * coming: a cashier working a queue never touches the keyboard between
     * items, and a box still holding the last barcode swallows the next.
     */
    protected function clearScanBox(): void
    {
        $this->scan = '';
        $this->suggestions = [];
        $this->dispatch('pos-scan-handled');
    }

    /* ================================================================ Cart */

    public function addToCart(int $productId, ?string $unit = null): void
    {
        $product = Product::with(['medicine', 'packs'])->find($productId);

        if (! $product) {
            return;
        }

        $sellingUnit = $this->resolveUnit($product, $unit);
        $key = $this->cartKey($productId, $sellingUnit);

        if (isset($this->cart[$key])) {
            $this->increment($key);
            $this->clearScanBox();

            return;
        }

        $available = $this->inventoryService()->availableStock($this->branchId(), $product->id);
        $pieces = $sellingUnit->pieces;

        if ($available < $pieces + $this->piecesReserved($productId)) {
            $this->reportShortStock();
            $this->clearScanBox();

            return;
        }

        $nextBatch = $product->batches()
            ->inStock()
            ->where('branch_id', $this->branchId())
            ->fefo()
            ->first();

        $this->cart[$key] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'generic' => $product->medicine?->full_name,
            'unit' => $sellingUnit->key,
            'unit_label' => $sellingUnit->label,
            'unit_multiplier' => $pieces,
            'unit_options' => $product->sellingUnitOptions(),
            'batch' => $nextBatch?->batch_number,
            'expiry' => $nextBatch?->expiration_date?->format('m/Y'),
            'unit_price' => $product->priceFor($sellingUnit->key),
            'quantity' => 1,
            'discount_percent' => 0,
            'stock' => $available,
            'stock_label' => $product->formatPieces($available),
        ];

        $this->cart[$key]['rx'] = (bool) $product->medicine?->requires_prescription;

        $this->clearScanBox();
    }

    /**
     * Switch a line between the whole pack, a pack tier and a single piece.
     *
     * Quantity carries over, so a line of 2 boxes becomes 2 blisters rather
     * than resetting — but it is clamped to whatever the shelf can cover.
     */
    public function changeUnit(string $key, string $unit): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $line = $this->cart[$key];
        $product = Product::with(['medicine', 'packs'])->find($line['product_id']);

        if (! $product) {
            return;
        }

        $sellingUnit = $this->resolveUnit($product, $unit);
        $newKey = $this->cartKey($line['product_id'], $sellingUnit);
        $pieces = $sellingUnit->pieces;

        $spare = $line['stock'] - $this->piecesReserved($line['product_id'], skipKey: $key);
        $quantity = max(1, min((int) $line['quantity'], intdiv(max(0, $spare), max(1, $pieces))));

        if ($quantity < 1) {
            $this->reportShortStock();

            return;
        }

        unset($this->cart[$key]);

        // Moving onto a unit already in the cart merges the two lines.
        if (isset($this->cart[$newKey])) {
            $this->cart[$newKey]['quantity'] += $quantity;

            return;
        }

        $this->cart[$newKey] = [
            ...$line,
            'unit' => $sellingUnit->key,
            'unit_label' => $sellingUnit->label,
            'unit_multiplier' => $pieces,
            'unit_price' => $product->priceFor($sellingUnit->key),
            'quantity' => $quantity,
        ];
    }

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $line = $this->cart[$key];

        if ($this->piecesReserved($line['product_id']) + $line['unit_multiplier'] > $line['stock']) {
            $this->reportShortStock();

            return;
        }

        $this->cart[$key]['quantity']++;
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->cart[$key]['quantity'] <= 1) {
            $this->removeItem($key);

            return;
        }

        $this->cart[$key]['quantity']--;
    }

    public function removeItem(string $key): void
    {
        unset($this->cart[$key]);
    }

    /* ------------------------------------------------------- Cart helpers */

    protected function cartKey(int $productId, SellingOption $unit): string
    {
        return $productId.'@'.$unit->key;
    }

    /** Falls back to the whole pack when a unit is missing or no longer sold. */
    protected function resolveUnit(Product $product, ?string $unit): SellingOption
    {
        return $product->sellingOption($unit);
    }

    /**
     * Pieces of a product already spoken for across every line in the cart.
     *
     * A box on one line and four tablets on another draw on the same shelf, so
     * the stock check has to look at all of them together.
     */
    protected function piecesReserved(int $productId, ?string $skipKey = null): int
    {
        $reserved = 0;

        foreach ($this->cart as $key => $line) {
            if ($key === $skipKey || $line['product_id'] !== $productId) {
                continue;
            }

            $reserved += (int) $line['quantity'] * (int) $line['unit_multiplier'];
        }

        return $reserved;
    }

    /**
     * The cashier is holding the box; they can see what is on the shelf.
     *
     * The notice used to spell out the product and its count, which is the
     * screen telling someone what is already in their hand. Four words say
     * the same thing without the cashier having to read a sentence mid-queue.
     */
    protected function reportShortStock(): void
    {
        Notification::make()
            ->title('Not enough stock')
            ->warning()
            ->send();
    }

    /** Empties the cart — and the parked copy behind it. */
    public function voidAll(): void
    {
        $this->reset(['cart', 'scan', 'suggestions', 'amountPaid', 'paymentReference', 'customerName', 'discountIdNumber']);
        $this->discountType = 'NONE';
        $this->discountPercent = 0;

        $this->forgetTotals();

        session()->forget($this->cartSessionKey());
    }

    /**
     * Drop the cached totals and everything worked out from them.
     *
     * Livewire holds a computed property for the rest of the request once it
     * has been read, and checkout() reads the total, the tender and the
     * change before it empties the till. Clearing the state alone left those
     * cached figures to repaint the finished sale over an empty cart: the old
     * amount due, the old change, and a Complete sale button still lit.
     */
    protected function forgetTotals(): void
    {
        unset(
            $this->totals,
            $this->changeDue,
            $this->tendered,
            $this->canCheckout,
            $this->cleanReference,
        );
    }

    /* ============================================================== Totals */

    #[Computed]
    public function totals(): array
    {
        if (empty($this->cart)) {
            return [
                'gross' => 0.0, 'line_discount' => 0.0, 'subtotal' => 0.0,
                'discount' => 0.0, 'total' => 0.0,
            ];
        }

        return $this->posService()->totals(
            array_values($this->cart),
            DiscountType::from($this->discountType),
            (float) $this->discountPercent,
        );
    }

    #[Computed]
    public function changeDue(): float
    {
        return max(0, round($this->tendered - $this->totals['total'], 2));
    }

    /**
     * Only cash is tendered.
     *
     * A card or an e-wallet settles the exact total — there is no float in
     * the drawer to make change from — so there is nothing for the cashier
     * to type, and nothing to check.
     */
    public function requiresCashTendered(): bool
    {
        return $this->paymentMethod === PaymentMethod::CASH->value;
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::tryFrom($this->paymentMethod) ?? PaymentMethod::CASH;
    }

    /**
     * GCash and card have to be tied back to their own transaction number.
     *
     * At close the drawer is counted against cash sales; everything else is
     * matched line by line against the wallet or terminal report, and without
     * the reference there is nothing to match a ticket to.
     */
    public function requiresPaymentReference(): bool
    {
        return $this->method()->requiresReference();
    }

    public function paymentReferenceLabel(): string
    {
        return $this->method()->referenceLabel();
    }

    /**
     * The reference as it will be stored.
     *
     * Spaces and dashes are the cashier's own formatting — a number read off
     * a phone screen in groups of four, or copied out of the GCash app — so
     * they come out. Anything else is left alone rather than quietly deleted:
     * a letter that vanished would leave a shortened number on the ticket
     * that matches nothing on the statement.
     */
    #[Computed]
    public function cleanReference(): string
    {
        return preg_replace('/[\s-]+/', '', (string) $this->paymentReference) ?? '';
    }

    /** Blank and part-typed references both keep the sale from going through. */
    public function referenceIsValid(): bool
    {
        return $this->cleanReference !== '' && ctype_digit($this->cleanReference);
    }

    /** Switching methods drops a reference typed for the previous one. */
    /**
     * Pull a keyed discount back inside 0-100.
     *
     * The clamp in PosService::totals() is what guarantees the arithmetic, but
     * it corrects silently. Snapping the property itself means the cashier
     * sees the box change to 100 and knows the 500 they typed did not take.
     */
    public function updatedDiscountPercent(): void
    {
        if ($this->discountPercent === null) {
            return;
        }

        $this->discountPercent = min(100, max(0, $this->discountPercent));

        $this->forgetTotals();
    }

    public function updatedPaymentMethod(): void
    {
        $this->paymentReference = null;

        // Whether cash is tendered at all turns on the method, so the tender
        // and the change go stale with it, not just the reference.
        $this->forgetTotals();
    }

    /** What the customer has actually handed over. */
    #[Computed]
    public function tendered(): float
    {
        return $this->requiresCashTendered()
            ? (float) ($this->amountPaid ?? 0)
            : $this->totals['total'];
    }

    /**
     * Whether Complete sale is allowed to fire.
     *
     * An empty cash box is the case this guards: leaving the field blank used
     * to be read as "exact money", which quietly recorded a sale nobody paid
     * for. Blank, zero and short are all refused now, and the button reads
     * the same answer so it greys out instead of failing on click.
     */
    #[Computed]
    public function canCheckout(): bool
    {
        if (empty($this->cart)) {
            return false;
        }

        if ($this->requiresPaymentReference() && ! $this->referenceIsValid()) {
            return false;
        }

        if (! $this->requiresCashTendered()) {
            return true;
        }

        return $this->amountPaid !== null
            && (float) $this->amountPaid > 0
            && round((float) $this->amountPaid - $this->totals['total'], 2) >= 0;
    }

    /* ============================================================ Checkout */

    /**
     * Complete sale, with the receipt as a deliberate choice.
     *
     * The till used to throw a print preview up on every sale, which is a
     * window to close on the customers who do not want a ticket — most of
     * them, on a sachet of paracetamol. So the button asks first, and the
     * quiet option is the plain one: nothing prints unless it is asked for.
     * Either way the sale is recorded, and a ticket that turns out to be
     * wanted after all is a click away under Reprint a receipt.
     */
    public function completeSaleAction(): Actions\Action
    {
        return Actions\Action::make('completeSale')
            ->label('Complete sale')
            ->icon('heroicon-o-check-circle')
            ->size('lg')
            ->disabled(fn (): bool => ! $this->canCheckout)
            ->modalHeading('Complete this sale?')
            ->modalDescription(fn (): string => $this->checkoutSummary())
            ->modalIcon('heroicon-o-banknotes')
            ->modalSubmitActionLabel('Complete, no receipt')
            ->modalCancelActionLabel('Back to the cart')
            ->extraModalFooterActions([
                Actions\Action::make('completeAndPrint')
                    ->label('Complete & print receipt')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn () => $this->checkout(printReceipt: true))
                    ->cancelParentActions(),
            ])
            ->action(fn () => $this->checkout());
    }

    /** The figures the cashier confirms against the cash in their hand. */
    public function checkoutSummary(): string
    {
        $peso = fn (float $amount): string => '₱'.number_format($amount, 2);

        $parts = [
            'Total '.$peso($this->totals['total']),
            $this->method()->getLabel(),
        ];

        if ($this->requiresCashTendered()) {
            $parts[] = 'Cash '.$peso($this->tendered);
            $parts[] = 'Change '.$peso($this->changeDue);
        }

        if ($this->requiresPaymentReference() && $this->cleanReference !== '') {
            $parts[] = $this->paymentReferenceLabel().' '.$this->cleanReference;
        }

        return implode(' · ', $parts);
    }

    public function checkout(bool $printReceipt = false): void
    {
        if (empty($this->cart)) {
            Notification::make()->title('The cart is empty')->warning()->send();

            return;
        }

        // The button is disabled for this, but Enter in the cash box and a
        // stale page can both still get here.
        if ($this->requiresPaymentReference() && ! $this->referenceIsValid()) {
            $blank = $this->cleanReference === '';

            Notification::make()
                ->title($blank ? 'Reference number required' : 'Reference number must be numbers only')
                ->body($blank
                    ? 'Enter the '.$this->paymentReferenceLabel().' before completing the sale.'
                    : 'Check the '.$this->paymentReferenceLabel().' — it should contain digits only.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->canCheckout) {
            $short = round($this->totals['total'] - (float) ($this->amountPaid ?? 0), 2);

            Notification::make()
                ->title($this->amountPaid > 0 ? 'Not enough cash received' : 'No cash received')
                ->body($this->amountPaid > 0
                    ? 'Short by ₱'.number_format($short, 2).'. Enter the full amount handed over.'
                    : 'Enter the cash received before completing the sale.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->branchId()) {
            Notification::make()
                ->title('No branch assigned')
                ->body('Ask an administrator to assign your account to a branch.')
                ->danger()
                ->send();

            return;
        }

        try {
            $sale = $this->posService()->checkout(
                cart: array_values($this->cart),
                branchId: $this->branchId(),
                amountPaid: $this->tendered,
                paymentMethod: PaymentMethod::from($this->paymentMethod),
                discountType: DiscountType::from($this->discountType),
                discountPercent: (float) $this->discountPercent,
                customerName: $this->customerName,
                discountIdNumber: $this->discountIdNumber,
                paymentReference: $this->cleanReference ?: null,
            );

            $this->lastSaleId = $sale->id;
            $this->lastInvoiceNumber = $sale->invoice_number;

            Notification::make()
                ->title("Sale {$sale->invoice_number} completed")
                ->body('Change due: ₱'.number_format((float) $sale->change_amount, 2))
                ->success()
                ->send();

            $this->voidAll();

            // Only when the customer asked for one: an unwanted print preview
            // is a window the cashier has to close with a queue waiting.
            if ($printReceipt) {
                $this->dispatch('open-receipt', url: route('receipts.show', $sale));
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not complete the sale')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /* ============================================================= Helpers */

    public function branchId(): ?int
    {
        return auth()->user()?->branch_id;
    }

    protected function posService(): PosService
    {
        return app(PosService::class);
    }

    protected function inventoryService(): InventoryService
    {
        return app(InventoryService::class);
    }

    public function getPaymentMethods(): array
    {
        return collect(PaymentMethod::cases())
            ->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->getLabel()])
            ->all();
    }

    public function getDiscountTypes(): array
    {
        return collect(DiscountType::cases())
            ->mapWithKeys(fn (DiscountType $d) => [$d->value => $d->getLabel()])
            ->all();
    }
}
