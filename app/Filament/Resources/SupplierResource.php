<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Supplier name')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('contact_person')
                ->maxLength(255),

            Forms\Components\TextInput::make('contact_number')
                ->tel()
                ->maxLength(50),

            Forms\Components\TextInput::make('email')
                ->email()
                ->maxLength(255),

            Forms\Components\Toggle::make('is_active')
                ->default(true),

            Forms\Components\Textarea::make('address')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (Supplier $record): ?string => $record->address),

                Tables\Columns\TextColumn::make('contact_person')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('contact_number')
                    ->icon('heroicon-m-phone')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('purchases_count')
                    ->label('Purchase orders')
                    ->counts('purchases')
                    ->badge()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSuppliers::route('/'),
        ];
    }
}
