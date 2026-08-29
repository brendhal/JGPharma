<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers\BatchesRelationManager;
use App\Filament\Resources\ProductResource\RelationManagers\StockRelationManager;
use App\Models\Product;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Products & Medicines';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Product details')
                ->description('What the item is called, how it is classified, and how it scans.')
                ->icon('heroicon-o-cube')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Product name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state, string $operation) {
                            if ($operation === 'create' && filled($state)) {
                                $set('sku', Str::upper(Str::slug(Str::limit($state, 20, ''), '-')).'-'.random_int(100, 999));
                            }
                        })
                        ->columnSpan(2),

                    Forms\Components\Select::make('category_id')
                        ->label('Category')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\Select::make('parent_id')
                                ->label('Parent category')
                                ->relationship('parent', 'name')
                                ->searchable(),
                        ]),

                    Forms\Components\Select::make('brand_id')
                        ->label('Brand')
                        ->relationship('brand', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                        ]),

                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(60)
                        ->helperText('Internal code. Printed on the QR sticker when no barcode exists.'),

                    Forms\Components\TextInput::make('barcode')
                        ->label('Barcode / QR value')
                        ->unique(ignoreRecord: true)
                        ->maxLength(60)
                        ->helperText('Scan the manufacturer barcode straight into this field.'),

                    Forms\Components\FileUpload::make('image_path')
                        ->label('Photo')
                        ->image()
                        ->imageEditor()
                        ->directory('products')
                        ->visibility('public')
                        ->columnSpan(2),
                ])
                ->columns(3),

            Forms\Components\Section::make('How it is packed and sold')
                ->description('Stock is counted in the smallest piece, so a blister sold and a loose tablet sold come off the very same shelf count.')
                ->icon('heroicon-o-squares-2x2')
                ->schema([
                    Forms\Components\TextInput::make('unit')
                        ->label('Whole unit')
                        ->required()
                        ->live(onBlur: true)
                        ->datalist(['Piece', 'Box', 'Case', 'Strip', 'Bottle', 'Sachet', 'Vial', 'Inhaler', 'Tube'])
                        ->maxLength(50)
                        ->helperText('The biggest way you sell it — the box or case as it sits on the shelf.'),

                    Forms\Components\TextInput::make('piece_unit')
                        ->label('One piece is called')
                        ->live(onBlur: true)
                        ->datalist(['Tablet', 'Capsule', 'pc', 'ml', 'Sachet'])
                        ->maxLength(30)
                        ->helperText('Leave blank if the pack is never broken open.'),

                    Forms\Components\TextInput::make('pieces_per_box')
                        ->label('Pieces in one whole unit')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        ->live(onBlur: true)
                        ->helperText('500 for a box of 50 blisters of 10. Leave at 1 if it is only ever sold whole.'),

                    Forms\Components\Repeater::make('packs')
                        ->relationship()
                        ->label('In-between pack sizes')
                        ->helperText('Optional. Add every size a customer can ask for between one piece and the whole unit — a blister of 10, a pack of 6, a pack of 12.')
                        ->schema([
                            Forms\Components\TextInput::make('label')
                                ->label('Called')
                                ->required()
                                ->maxLength(40)
                                ->live(onBlur: true)
                                ->datalist(['Blister', 'Strip', 'Pack of 6', 'Pack of 12', 'Bundle'])
                                ->placeholder('Blister'),

                            Forms\Components\TextInput::make('pieces')
                                ->label('Pieces in it')
                                ->numeric()
                                ->required()
                                ->minValue(2)
                                ->live(onBlur: true)
                                // Filament's lt() cannot reach out of a
                                // repeater row — it hands the "../../" path to
                                // the validator as a literal field name, which
                                // never matches, so every row fails. Comparing
                                // by hand is the only way up a level.
                                ->rule(static fn (Forms\Get $get): Closure => static function (
                                    string $attribute,
                                    mixed $value,
                                    Closure $fail
                                ) use ($get): void {
                                    $total = (int) $get('../../pieces_per_box');

                                    if ($total > 1 && (int) $value >= $total) {
                                        $fail("A pack has to be smaller than the whole unit ({$total} pieces).");

                                        return;
                                    }

                                    // The table refuses two tiers of the same
                                    // size; catching it here beats letting the
                                    // save blow up on a unique index.
                                    $sameSize = collect($get('../../packs') ?? [])
                                        ->filter(fn ($pack): bool => (int) ($pack['pieces'] ?? 0) === (int) $value)
                                        ->count();

                                    if ($sameSize > 1) {
                                        $fail("There is already a pack of {$value} on this product.");
                                    }
                                })
                                ->helperText('Must be smaller than the whole unit.'),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add a pack size')
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                            ? $state['label'].' — '.($state['pieces'] ?? '?').' pcs'
                            : null)
                        ->visible(fn (Forms\Get $get): bool => (int) $get('pieces_per_box') > 1)
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('pack_summary')
                        ->label('What the cashier will see')
                        ->content(fn (Forms\Get $get): HtmlString => static::packSummary([
                            'unit' => $get('unit'),
                            'piece_unit' => $get('piece_unit'),
                            'pieces_per_box' => $get('pieces_per_box'),
                            'packs' => $get('packs'),
                        ]))
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Forms\Components\Section::make('Pricing & stock control')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Forms\Components\TextInput::make('cost_price')
                        ->label('Cost price')
                        ->numeric()
                        ->prefix('₱')
                        ->default(0)
                        ->required()
                        ->live(onBlur: true),

                    Forms\Components\TextInput::make('selling_price')
                        ->label('Selling price')
                        ->numeric()
                        ->prefix('₱')
                        ->default(0)
                        ->required()
                        ->live(onBlur: true),

                    Forms\Components\Placeholder::make('margin')
                        ->label('Margin')
                        ->content(function (Forms\Get $get): string {
                            $cost = (float) $get('cost_price');
                            $price = (float) $get('selling_price');

                            if ($price <= 0) {
                                return '—';
                            }

                            $margin = (($price - $cost) / $price) * 100;

                            return number_format($margin, 1).'%  (₱'.number_format($price - $cost, 2).' per unit)';
                        }),

                    Forms\Components\TextInput::make('minimum_stock')
                        ->label(fn (Forms\Get $get): string => (int) $get('pieces_per_box') > 1
                            ? 'Reorder level (in '.(trim((string) $get('unit')) ?: 'whole units').')'
                            : 'Reorder level')
                        ->numeric()
                        ->minValue(0)
                        ->default(1)
                        ->required()
                        ->live(onBlur: true)
                        // Held in pieces, because that is what the shelf count
                        // is compared against. Nobody reorders by the tablet
                        // though, so the field takes whole units and converts.
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $state, ?Product $record): void {
                            $perUnit = max(1, (int) ($record?->pieces_per_box ?? 1));

                            // Round up: a threshold landing mid-box should
                            // raise the alert on the earlier box, not the later.
                            $component->state($perUnit > 1
                                ? (int) ceil(max(0, (int) $state) / $perUnit)
                                : $state);
                        })
                        ->dehydrateStateUsing(fn (mixed $state, Forms\Get $get): int => max(0, (int) $state)
                            * max(1, (int) $get('pieces_per_box')))
                        ->helperText(function (Forms\Get $get): string {
                            $perUnit = max(1, (int) $get('pieces_per_box'));

                            if ($perUnit <= 1) {
                                return 'The dashboard raises a low-stock alert at or below this.';
                            }

                            $level = max(0, (int) $get('minimum_stock'));
                            $unit = trim((string) $get('unit')) ?: 'unit';
                            $piece = trim((string) $get('piece_unit')) ?: 'piece';

                            return 'Alert at or below '.$level.' '.Str::plural($unit, $level)
                                .' — '.number_format($level * $perUnit).' '.Str::plural($piece).'.';
                        }),

                    Forms\Components\Toggle::make('is_batch_tracked')
                        ->label('Track batches & expiry')
                        ->default(true)
                        ->helperText('On for medicines. Off for merchandise that never expires.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])
                ->columns(3),

            Forms\Components\Section::make('Medicine information')
                ->description('Fill this in only for drugs. Leave the toggle off for general merchandise.')
                ->icon('heroicon-o-beaker')
                ->schema([
                    Forms\Components\Toggle::make('is_medicine')
                        ->label('This product is a medicine')
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Product $record) {
                            $component->state((bool) $record?->medicine);
                        }),

                    Forms\Components\Group::make()
                        ->relationship('medicine')
                        ->schema([
                            Forms\Components\TextInput::make('generic_name')
                                ->label('Generic name')
                                ->required()
                                ->maxLength(255),

                            Forms\Components\TextInput::make('strength')
                                ->label('Strength')
                                ->placeholder('500mg')
                                ->maxLength(100),

                            Forms\Components\Select::make('dosage_form')
                                ->label('Dosage form')
                                ->options([
                                    'Tablet' => 'Tablet',
                                    'Capsule' => 'Capsule',
                                    'Syrup' => 'Syrup',
                                    'Suspension' => 'Suspension',
                                    'Injection' => 'Injection',
                                    'Inhaler' => 'Inhaler',
                                    'Ointment' => 'Ointment',
                                    'Drops' => 'Drops',
                                    'Suppository' => 'Suppository',
                                ])
                                ->searchable(),

                            Forms\Components\TextInput::make('manufacturer')
                                ->maxLength(255),

                            Forms\Components\TextInput::make('registration_number')
                                ->label('FDA registration no.')
                                ->maxLength(100),

                            Forms\Components\Toggle::make('requires_prescription')
                                ->label('Prescription required (Rx)')
                                ->helperText('The POS will ask the cashier to confirm a prescription.'),
                        ])
                        ->columns(3)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('is_medicine')),
                ]),
        ]);
    }

    /**
     * The pack structure read back as sentences, live as the form is typed.
     *
     * A count of pieces is easy to mistype by a zero, and 500 against 50 is
     * the difference between a box and a blister. Spelling the ladder out —
     * and totalling it — is what catches that before it reaches the till.
     */
    public static function packSummary(array $state): HtmlString
    {
        $whole = trim((string) ($state['unit'] ?? '')) ?: 'Whole unit';
        $piece = trim((string) ($state['piece_unit'] ?? '')) ?: 'piece';
        $total = max(1, (int) ($state['pieces_per_box'] ?? 1));

        if ($total < 2) {
            return new HtmlString(
                '<span class="text-sm text-gray-500 dark:text-gray-400">Sold whole only — one '
                .e($whole).' is one item, and stock counts '.e($whole).'es.</span>'
            );
        }

        $rows = [['Unit', e($piece)]];

        // Tiers largest first, matching the order the till will show them.
        $packs = collect($state['packs'] ?? [])
            ->map(fn ($pack): array => [
                'label' => trim((string) ($pack['label'] ?? '')) ?: 'Pack',
                'pieces' => (int) ($pack['pieces'] ?? 0),
            ])
            ->filter(fn (array $pack): bool => $pack['pieces'] > 1 && $pack['pieces'] < $total)
            ->sortByDesc('pieces');

        foreach ($packs as $pack) {
            $rows[] = [
                'Pack size',
                e($pack['pieces'].' '.$piece.' / '.$pack['label']),
            ];
        }

        // How many of the smallest tier make up the whole unit — the sum a
        // pharmacist actually does when a box arrives: 50 blisters to a box.
        $smallest = $packs->last();

        if ($smallest && $total % $smallest['pieces'] === 0) {
            $rows[] = [
                ucfirst($whole).' size',
                e(intdiv($total, $smallest['pieces']).' '.Str::plural($smallest['label']).' / '.$whole),
            ];
        }

        $rows[] = ['Total per '.$whole, '<strong>'.e($total.' '.Str::plural($piece)).'</strong>'];

        $html = collect($rows)
            ->map(fn (array $row): string => '<div class="flex gap-x-2"><span class="text-gray-500 dark:text-gray-400">'
                .e($row[0]).':</span><span class="text-gray-950 dark:text-white">'.$row[1].'</span></div>')
            ->implode('');

        return new HtmlString('<div class="text-sm space-y-1">'.$html.'</div>');
    }

    public static function table(Table $table): Table
    {
        $branchId = auth()->user()?->branch_id;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['category', 'brand', 'medicine', 'packs'])
                ->withSum(
                    ['inventories as stock_on_hand' => fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q],
                    'quantity'
                ))
            ->columns([
                Tables\Columns\TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable()
                    ->copyable()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('medicine.generic_name')
                    ->label('Generic name')
                    ->searchable()
                    ->description(fn (Product $record): ?string => $record->medicine?->strength)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Brand name')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (Product $record): ?string => $record->category?->name),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Unit')
                    ->formatStateUsing(fn (Product $record): string => $record->isDivisible()
                        ? "{$record->unit} ({$record->pieces_per_box} {$record->piece_unit})"
                        : (string) $record->unit)
                    ->description(fn (Product $record): ?string => $record->packs->isNotEmpty()
                        ? 'Also sold as '.$record->packs->map(fn ($pack) => $pack->label)->join(', ')
                        : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost')
                    ->money('PHP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('Price')
                    ->money('PHP')
                    ->alignEnd()
                    ->color('primary')
                    ->weight('semibold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_on_hand')
                    ->label('Stock')
                    ->badge()
                    ->alignCenter()
                    ->default(0)
                    ->formatStateUsing(function ($state, Product $record): string {
                        $qty = (int) $state;

                        if ($qty <= 0) {
                            return 'Out of stock';
                        }

                        $reads = $record->formatPieces($qty);

                        return $qty <= $record->minimum_stock ? "{$reads} · low" : $reads;
                    })
                    ->color(function ($state, Product $record): string {
                        $qty = (int) $state;

                        return match (true) {
                            $qty <= 0 => 'danger',
                            $qty <= $record->minimum_stock => 'warning',
                            default => 'success',
                        };
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('medicine.requires_prescription')
                    ->label('Rx')
                    ->boolean()
                    ->trueIcon('heroicon-o-clipboard-document-check')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('medicine')
                    ->label('Medicines only')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('medicine'),
                        false: fn (Builder $q) => $q->whereDoesntHave('medicine'),
                        blank: fn (Builder $q) => $q,
                    ),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Low stock only')
                    ->query(fn (Builder $query) => $query->whereHas('inventories', fn (Builder $i) => $i
                        ->whereRaw('branch_inventories.quantity <= coalesce(branch_inventories.minimum_stock, products.minimum_stock)')))
                    ->toggle(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('labels')
                        ->label('Print QR stickers')
                        ->icon('heroicon-o-qr-code')
                        ->color('gray')
                        ->url(fn (Product $record): string => route('labels.sheet', $record))
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [
            StockRelationManager::class,
            BatchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'barcode', 'medicine.generic_name'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Generic' => $record->medicine?->generic_name ?? '—',
            'Price' => '₱'.number_format((float) $record->selling_price, 2),
        ];
    }
}
