<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Staff accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Leave blank when editing to keep the current password.')
                        ->maxLength(255),

                    Forms\Components\FileUpload::make('avatar_path')
                        ->label('Photo')
                        ->avatar()
                        ->directory('avatars')
                        ->visibility('public'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Access')
                ->description('A sales lady only sees her own branch. Administrators see every branch.')
                ->schema([
                    Forms\Components\Select::make('role')
                        ->options(UserRole::class)
                        ->default(UserRole::CASHIER)
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('branch_id')
                        ->label('Assigned branch')
                        ->relationship('branch', 'name')
                        ->searchable()
                        ->preload()
                        ->required(fn (Forms\Get $get): bool => $get('role') !== UserRole::ADMIN->value),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Can sign in')
                        ->default(true),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&background=0B5F8A&color=fff'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (User $record): string => $record->email),

                Tables\Columns\TextColumn::make('role')
                    ->badge(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray')
                    ->placeholder('All branches'),

                Tables\Columns\TextColumn::make('sales_count')
                    ->label('Sales rung up')
                    ->counts('sales')
                    ->badge()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(UserRole::class),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->id !== auth()->id()),
            ])
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // A branch manager administers only her own staff.
        if (! auth()->user()?->seesAllBranches() && auth()->user()?->branch_id) {
            $query->where('branch_id', auth()->user()->branch_id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
