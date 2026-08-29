@php
    use App\Support\Money;

    $totals = $this->totals;
    $manualDiscountAllowed = $this->discountType === 'NONE';
@endphp

<x-filament-panels::page>
    {{-- Styles live here rather than in Tailwind classes: Filament only ships
         the utilities its own components use, so a custom page cannot rely on
         arbitrary Tailwind unless the panel has a compiled custom theme. --}}
    <style>
        .pos-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: start; }
        @media (min-width: 1024px) { .pos-grid { grid-template-columns: 3fr 2fr; } }

        .pos-card {
            background: #fff;
            border: 1px solid rgb(228 228 231);
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
            overflow: hidden;
        }
        .dark .pos-card { background: rgb(24 24 27); border-color: rgb(63 63 70); }

        .pos-card-head {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgb(228 228 231);
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        .dark .pos-card-head { border-color: rgb(63 63 70); }

        .pos-card-body { padding: 1rem; }

        .pos-scan {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1.125rem;
            border: 1px solid rgb(212 212 216);
            border-radius: 0.5rem;
            background: #fff;
            color: inherit;
            outline: none;
        }
        .pos-scan:focus { border-color: rgb(245 158 11); box-shadow: 0 0 0 2px rgb(245 158 11 / 0.3); }
        .dark .pos-scan { background: rgb(39 39 42); border-color: rgb(63 63 70); }

        .pos-suggestions {
            margin-top: 0.5rem;
            border: 1px solid rgb(228 228 231);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .dark .pos-suggestions { border-color: rgb(63 63 70); }

        .pos-suggestion {
            width: 100%;
            text-align: left;
            padding: 0.5rem 0.75rem;
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            background: transparent;
            border: 0;
            border-bottom: 1px solid rgb(244 244 245);
            cursor: pointer;
            color: inherit;
            font: inherit;
        }
        .pos-suggestion:last-child { border-bottom: 0; }
        .pos-suggestion:hover { background: rgb(250 250 250); }
        .dark .pos-suggestion { border-color: rgb(39 39 42); }
        .dark .pos-suggestion:hover { background: rgb(39 39 42); }

        .pos-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .pos-table th {
            text-align: left;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(113 113 122);
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid rgb(228 228 231);
            font-weight: 600;
        }
        .pos-table td { padding: 0.625rem 0.75rem; border-bottom: 1px solid rgb(244 244 245); vertical-align: middle; }
        .dark .pos-table th { border-color: rgb(63 63 70); }
        .dark .pos-table td { border-color: rgb(39 39 42); }
        .pos-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

        .pos-qty { display: flex; align-items: center; gap: 0.25rem; justify-content: flex-end; }
        .pos-step {
            width: 1.75rem; height: 1.75rem;
            border: 1px solid rgb(212 212 216);
            border-radius: 0.375rem;
            background: #fff;
            cursor: pointer;
            line-height: 1;
            font-size: 1rem;
            color: inherit;
        }
        .pos-step:hover { background: rgb(244 244 245); }
        .dark .pos-step { background: rgb(39 39 42); border-color: rgb(63 63 70); }
        .dark .pos-step:hover { background: rgb(63 63 70); }
        .pos-qty-value { min-width: 2rem; text-align: center; font-variant-numeric: tabular-nums; }

        .pos-remove { background: none; border: 0; cursor: pointer; color: rgb(220 38 38); font-size: 0.75rem; }

        .pos-field { margin-bottom: 0.75rem; }
        .pos-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: rgb(82 82 91);
        }
        .dark .pos-label { color: rgb(161 161 170); }

        .pos-input, .pos-select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid rgb(212 212 216);
            border-radius: 0.5rem;
            background-color: #fff;
            color: inherit;
            font: inherit;
            font-size: 0.875rem;
        }
        .dark .pos-input, .dark .pos-select { background-color: rgb(39 39 42); border-color: rgb(63 63 70); }

        /* Exactly one chevron.

           Filament's base stylesheet paints a chevron on every <select> using
           background-image plus no-repeat/size/position. The `background`
           shorthand resets those companions to `repeat`, `auto` and `0 0`,
           which tiles that arrow across the whole field and prints one on top
           of the selected label. Every longhand is therefore declared here,
           and `appearance: none` drops the native arrow so only ours shows. */
        select.pos-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2371717a' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.625rem center;
            background-size: 1.125em 1.125em;
            padding-right: 2.25rem;
        }
        .dark select.pos-select { background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23a1a1aa' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E"); }

        .pos-cash { font-size: 1.25rem; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }

        .pos-totals { font-size: 0.875rem; }
        .pos-totals div {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0;
            font-variant-numeric: tabular-nums;
        }
        .pos-totals .pos-grand {
            font-size: 1.375rem;
            font-weight: 700;
            padding-top: 0.5rem;
            margin-top: 0.5rem;
            border-top: 1px solid rgb(228 228 231);
        }
        .dark .pos-totals .pos-grand { border-color: rgb(63 63 70); }
        .pos-muted { color: rgb(113 113 122); }

        .pos-change {
            margin-top: 0.75rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.5rem;
            background: rgb(240 253 244);
            color: rgb(21 128 61);
            display: flex;
            justify-content: space-between;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .dark .pos-change { background: rgb(20 83 45 / 0.3); color: rgb(134 239 172); }
        .pos-change.pos-short { background: rgb(254 242 242); color: rgb(185 28 28); }
        .dark .pos-change.pos-short { background: rgb(127 29 29 / 0.3); color: rgb(252 165 165); }

        .pos-empty { padding: 2.5rem 1rem; text-align: center; color: rgb(113 113 122); font-size: 0.875rem; }

        .pos-pill {
            display: inline-block;
            padding: 0.0625rem 0.375rem;
            border-radius: 0.25rem;
            font-size: 0.625rem;
            font-weight: 600;
            background: rgb(254 226 226);
            color: rgb(153 27 27);
            margin-left: 0.25rem;
        }
        .pos-sub { font-size: 0.75rem; color: rgb(113 113 122); }
        .pos-actions { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1rem; }
        .pos-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
    </style>

    {{-- A barcode gun is just a very fast keyboard. This watches the rhythm of
         the keystrokes rather than waiting for the Enter key some guns are not
         configured to send, so a scanned code goes into the cart on its own —
         and does so wherever the cursor happens to be, because a cashier who
         last touched a quantity box should not have to click back first. --}}
    <script>
        if (! window.posScannerRegistered) {
            window.posScannerRegistered = true;

            document.addEventListener('alpine:init', () => {
                Alpine.data('posScanner', () => ({
                    /** Nobody types two characters this close together. */
                    fastMs: 40,

                    /** Quiet for this long and the code is finished. */
                    idleMs: 120,

                    /** Shorter than this is a slip of the hand, not a barcode. */
                    minLength: 4,

                    buffer: '',
                    fastRun: 0,
                    lastKeyAt: 0,
                    timer: null,

                    /** A field a scan started leaking into, with its text as it was. */
                    borrowed: null,

                    onKey(event) {
                        const box = this.$refs.scan;

                        if (! box || event.ctrlKey || event.metaKey || event.altKey || event.repeat) {
                            return;
                        }

                        const active = document.activeElement;
                        const inBox = active === box;

                        if (event.key === 'Enter') {
                            // Whatever we were collecting is finished — by the
                            // gun's own Enter, or the cashier's.
                            if (this.buffer.length >= this.minLength) {
                                event.preventDefault();
                                this.commit(this.buffer);
                            } else if (inBox) {
                                event.preventDefault();
                                this.commit(this.boxCode());
                            }

                            return;
                        }

                        if (event.key.length !== 1) {
                            return;
                        }

                        const now = performance.now();
                        const fast = (now - this.lastKeyAt) < this.fastMs;
                        this.lastKeyAt = now;
                        this.fastRun = fast ? this.fastRun + 1 : 0;

                        if (inBox) {
                            // The box types for itself; we only listen to the
                            // rhythm, so a gun with no Enter still finishes.
                            this.buffer = '';
                            this.borrowed = null;
                            this.wait(() => {
                                if (this.fastRun >= this.minLength - 1) {
                                    this.commit(this.boxCode());
                                }
                            });

                            return;
                        }

                        if (this.isTypingField(active)) {
                            if (this.fastRun < 2) {
                                // Could be a person, could be the first of a
                                // burst. Remember the field as it stands so it
                                // can be put back if this turns out to be a scan.
                                if (this.fastRun === 0) {
                                    this.buffer = event.key;
                                    this.borrowed = { el: active, value: active.value };
                                } else {
                                    this.buffer += event.key;
                                }

                                this.wait(() => this.forget());

                                return;
                            }

                            // Too fast to be a person. Take back the couple of
                            // characters that landed in their field first.
                            event.preventDefault();
                            this.giveBack();
                            this.buffer += event.key;
                        } else {
                            // Nothing is being typed into, so the whole code is ours.
                            event.preventDefault();
                            this.buffer += event.key;
                        }

                        this.wait(() => {
                            if (this.buffer.trim().length >= this.minLength) {
                                this.commit(this.buffer);
                            } else {
                                this.forget();
                            }
                        });
                    },

                    /**
                     * What the box is holding that came off the gun.
                     *
                     * Only the tail of a burst counts, so a code fired into a
                     * box the cashier had already half-typed a name into still
                     * reads as the code and not as the two of them run together.
                     * Nothing fast in progress means the whole box is theirs.
                     */
                    boxCode() {
                        const typed = this.$refs.scan?.value || '';

                        return this.fastRun >= this.minLength - 1
                            ? typed.slice(-(this.fastRun + 1))
                            : typed;
                    },

                    /** Hand the finished code straight to the server. */
                    commit(code) {
                        const value = (code || '').trim();

                        this.forget();

                        if (! value) {
                            return;
                        }

                        const box = this.$refs.scan;

                        if (box) {
                            // Clear the box and tell Livewire the box is empty,
                            // or the search update still in flight arrives after
                            // the item is in the cart and reopens the pick-list.
                            box.value = '';
                            box.dispatchEvent(new Event('input', { bubbles: true }));
                        }

                        this.$wire.submitScan(value);
                    },

                    /** Put the cursor back for the next item. */
                    resetBox() {
                        const box = this.$refs.scan;

                        this.forget();

                        if (box) {
                            box.value = '';
                            box.focus();
                        }
                    },

                    wait(then) {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(then, this.idleMs);
                    },

                    forget() {
                        clearTimeout(this.timer);
                        this.timer = null;
                        this.buffer = '';
                        this.fastRun = 0;
                        this.borrowed = null;
                    },

                    giveBack() {
                        if (! this.borrowed || ! this.borrowed.el) {
                            return;
                        }

                        const { el, value } = this.borrowed;
                        this.borrowed = null;

                        if (typeof el.value !== 'string') {
                            return;
                        }

                        el.value = value;
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    },

                    isTypingField(el) {
                        if (! el) {
                            return false;
                        }

                        return el.isContentEditable
                            || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
                    },
                }));
            });
        }
    </script>

    <div
        x-data="posScanner()"
        x-on:open-receipt.window="window.open($event.detail.url, '_blank')"
        x-on:keydown.window.capture="onKey($event)"
        x-on:pos-scan-handled.window="resetBox()"
        x-on:keydown.window.f2.prevent="$refs.cash?.focus()"
    >
        <div class="pos-grid">

            {{-- ------------------------------------------------ Scan + cart --}}
            <div>
                <div class="pos-card">
                    <div class="pos-card-body">
                        <form wire:submit.prevent="submitScan">
                            <input
                                type="text"
                                class="pos-scan"
                                placeholder="Scan barcode or type a product name…"
                                wire:model.live.debounce.400ms="scan"
                                x-ref="scan"
                                autofocus
                                autocomplete="off"
                            >
                        </form>

                        @if (filled($suggestions))
                            <div class="pos-suggestions">
                                @foreach ($suggestions as $suggestion)
                                    <button
                                        type="button"
                                        class="pos-suggestion"
                                        wire:key="sugg-{{ $suggestion['id'] }}"
                                        wire:click="addToCart({{ $suggestion['id'] }})"
                                    >
                                        <span>
                                            <strong>{{ $suggestion['name'] }}</strong>
                                            @if ($suggestion['generic'])
                                                <span class="pos-sub"> — {{ $suggestion['generic'] }}</span>
                                            @endif
                                        </span>
                                        <span class="pos-num">
                                            {{ Money::format($suggestion['price']) }}
                                            <span class="pos-sub">&middot; {{ $suggestion['stock_label'] }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pos-card" style="margin-top: 1rem;">
                    <div class="pos-card-head">
                        <span>Cart</span>
                        <span class="pos-sub">
                            {{ count($cart) }} line(s)
                            @if (! empty($cart))
                                <button
                                    type="button"
                                    class="pos-remove"
                                    style="margin-left: 0.75rem; font-weight: 600;"
                                    wire:click="voidAll"
                                    wire:confirm="Remove every item from this cart?"
                                >Remove all</button>
                            @endif
                        </span>
                    </div>

                    @if (empty($cart))
                        <div class="pos-empty">
                            Nothing scanned yet. Scan a barcode anywhere on this screen, or search above to begin.
                        </div>
                    @else
                        <table class="pos-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th style="width: 9rem;">Unit</th>
                                    <th class="pos-num">Price</th>
                                    <th class="pos-num" style="width: 8rem;">Qty</th>
                                    <th class="pos-num">Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cart as $key => $line)
                                    <tr wire:key="cart-{{ $key }}">
                                        <td>
                                            <div>
                                                {{ $line['name'] }}
                                                @if ($line['rx'] ?? false)
                                                    <span class="pos-pill">Rx</span>
                                                @endif
                                            </div>
                                            @if ($line['generic'])
                                                <div class="pos-sub">{{ $line['generic'] }}</div>
                                            @endif
                                            @if ($line['batch'])
                                                <div class="pos-sub">
                                                    Lot {{ $line['batch'] }}@if ($line['expiry']) &middot; exp {{ $line['expiry'] }}@endif
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if (count($line['unit_options']) > 1)
                                                <select
                                                    class="pos-select"
                                                    wire:change="changeUnit('{{ $key }}', $event.target.value)"
                                                >
                                                    @foreach ($line['unit_options'] as $value => $label)
                                                        <option value="{{ $value }}" @selected($value === $line['unit'])>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <span class="pos-sub">{{ $line['unit_label'] }}</span>
                                            @endif
                                        </td>
                                        <td class="pos-num">{{ Money::plain($line['unit_price']) }}</td>
                                        <td>
                                            <div class="pos-qty">
                                                <button type="button" class="pos-step" wire:click="decrement('{{ $key }}')">&minus;</button>
                                                <span class="pos-qty-value">{{ $line['quantity'] }}</span>
                                                <button type="button" class="pos-step" wire:click="increment('{{ $key }}')">+</button>
                                            </div>
                                            <div class="pos-sub" style="text-align: right;">{{ $line['stock_label'] ?? $line['stock'] }} on hand</div>
                                        </td>
                                        <td class="pos-num">{{ Money::plain($line['unit_price'] * $line['quantity']) }}</td>
                                        <td class="pos-num">
                                            <button type="button" class="pos-remove" wire:click="removeItem('{{ $key }}')">Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------- Payment --}}
            <div class="pos-card">
                <div class="pos-card-head">
                    <span>Payment</span>
                    @if ($lastInvoiceNumber)
                        <span class="pos-sub">Last: {{ $lastInvoiceNumber }}</span>
                    @endif
                </div>

                <div class="pos-card-body">
                    <div class="pos-totals">
                        <div>
                            <span class="pos-muted">Gross</span>
                            <span>{{ Money::plain($totals['gross']) }}</span>
                        </div>
                        @if (($totals['line_discount'] ?? 0) > 0)
                            <div>
                                <span class="pos-muted">Line discounts</span>
                                <span>-{{ Money::plain($totals['line_discount']) }}</span>
                            </div>
                        @endif
                        <div>
                            <span class="pos-muted">Subtotal</span>
                            <span>{{ Money::plain($totals['subtotal']) }}</span>
                        </div>
                        @if (($totals['discount'] ?? 0) > 0)
                            <div>
                                <span class="pos-muted">Discount</span>
                                <span>-{{ Money::plain($totals['discount']) }}</span>
                            </div>
                        @endif
                        <div class="pos-grand">
                            <span>Total</span>
                            <span>{{ Money::format($totals['total']) }}</span>
                        </div>
                    </div>

                    <hr style="margin: 1rem 0; border: 0; border-top: 1px solid rgb(228 228 231);">

                    <div class="pos-row2">
                        <div class="pos-field">
                            <label class="pos-label" for="pos-payment">Payment method</label>
                            <select id="pos-payment" class="pos-select" wire:model.live="paymentMethod">
                                @foreach ($this->getPaymentMethods() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="pos-field">
                            <label class="pos-label" for="pos-discount-type">Discount</label>
                            <select id="pos-discount-type" class="pos-select" wire:model.live="discountType">
                                @foreach ($this->getDiscountTypes() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if ($this->requiresPaymentReference())
                        <div class="pos-field">
                            <label class="pos-label" for="pos-reference">
                                {{ $this->paymentReferenceLabel() }} <span class="pos-muted">(required)</span>
                            </label>
                            <input id="pos-reference" type="text" inputmode="numeric"
                                autocomplete="off"
                                class="pos-input"
                                placeholder="Numbers only"
                                wire:model.live.debounce.400ms="paymentReference">
                        </div>
                    @endif

                    @if ($manualDiscountAllowed)
                        <div class="pos-field">
                            <label class="pos-label" for="pos-discount-percent">Manual discount (%)</label>
                            {{-- Committed on blur, not while typing: a number
                                 input reports a half-typed "12." as empty, so
                                 a live binding kept syncing the decimal point
                                 away before the cashier could type the digit
                                 after it. --}}
                            <input id="pos-discount-percent" type="number" min="0" max="100" step="0.01"
                                inputmode="decimal"
                                class="pos-input" wire:model.blur="discountPercent">
                        </div>
                    @else
                        <div class="pos-field">
                            <label class="pos-label" for="pos-discount-id">Discount ID number</label>
                            <input id="pos-discount-id" type="text" class="pos-input"
                                placeholder="Senior / PWD ID"
                                wire:model.blur="discountIdNumber">
                        </div>
                    @endif

                    <div class="pos-field">
                        <label class="pos-label" for="pos-customer">Customer name <span class="pos-muted">(optional)</span></label>
                        <input id="pos-customer" type="text" class="pos-input" wire:model.blur="customerName">
                    </div>

                    @if ($this->requiresCashTendered())
                        <div class="pos-field">
                            <label class="pos-label" for="pos-cash">
                                Cash received <span class="pos-muted">(F2, required)</span>
                            </label>
                            <input id="pos-cash" x-ref="cash" type="number" min="0" step="0.01"
                                class="pos-input pos-cash"
                                wire:model.live.debounce.400ms="amountPaid"
                                {{-- Enter opens the same confirmation the button does,
                                     so the receipt is still a choice. --}}
                                wire:keydown.enter="mountAction('completeSale')">
                        </div>
                    @else
                        <div class="pos-field">
                            <label class="pos-label">Amount paid</label>
                            <input class="pos-input pos-cash" type="text" readonly
                                value="{{ Money::plain($totals['total']) }}">
                            <span class="pos-sub">
                                {{ $this->getPaymentMethods()[$paymentMethod] ?? 'This method' }}
                                settles the exact total — nothing to tender.
                            </span>
                        </div>
                    @endif

                    @php
                        $paid = $this->tendered;
                        $short = $paid > 0 && $paid < (float) $totals['total'];
                    @endphp

                    <div class="pos-change @if ($short) pos-short @endif">
                        <span>{{ $short ? 'Short by' : 'Change due' }}</span>
                        <span>
                            {{ $short
                                ? Money::format((float) $totals['total'] - $paid)
                                : Money::format($this->changeDue) }}
                        </span>
                    </div>

                    @if (! empty($cart) && $this->requiresPaymentReference() && ! $this->referenceIsValid())
                        <div class="pos-sub" style="margin-top: 0.5rem; color: rgb(220 38 38);">
                            {{ $this->cleanReference === ''
                                ? 'Enter the '.$this->paymentReferenceLabel().' to complete this sale.'
                                : $this->paymentReferenceLabel().' must be numbers only.' }}
                        </div>
                    @elseif (! empty($cart) && $this->requiresCashTendered() && ! $this->canCheckout)
                        <div class="pos-sub" style="margin-top: 0.5rem; color: rgb(220 38 38);">
                            {{ $paid > 0
                                ? 'Cash received is short of the total.'
                                : 'Enter the cash received to complete this sale.' }}
                        </div>
                    @endif

                    <div class="pos-actions">
                        {{-- Asks about the receipt before it takes the money;
                             see completeSaleAction() on the page. --}}
                        {{ $this->completeSaleAction }}

                        <x-filament::button
                            wire:click="voidAll"
                            color="gray"
                            outlined
                            :disabled="empty($cart)"
                        >
                            Void cart
                        </x-filament::button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-filament-panels::page>
