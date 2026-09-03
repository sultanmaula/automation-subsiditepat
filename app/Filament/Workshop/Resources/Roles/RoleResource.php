<?php

namespace App\Filament\Workshop\Resources\Roles;

use App\Filament\Workshop\Resources\Roles\Pages\CreateRole;
use App\Filament\Workshop\Resources\Roles\Pages\EditRole;
use App\Filament\Workshop\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | \UnitEnum | null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('roles') ?? false;
    }

    public static function getModelLabel(): string { return 'Role'; }

    public static function getPluralModelLabel(): string { return 'Role'; }

    public static function getNavigationLabel(): string { return 'Role'; }

    /** Panel Bengkel hanya boleh melihat dan mengubah role miliknya sendiri. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('panel', 'workshop');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Role')
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Role')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('panel', 'workshop'))
                        ->helperText('Contoh: Pemilik, Kasir, Pegawai.'),
                ]),

            Section::make('Akses Menu')
                ->description('Centang menu yang boleh diakses oleh pemegang role ini.')
                ->schema([
                    CheckboxList::make('permissions')
                        ->label('')
                        ->options(Role::WORKSHOP_PERMISSIONS)
                        ->columns(2)
                        ->gridDirection('row')
                        ->bulkToggleable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('users_count')
                    ->label('Pengguna')
                    ->counts('users')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('permissions')
                    ->label('Akses')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::WORKSHOP_PERMISSIONS[$state] ?? $state)
                    ->color('primary')
                    ->wrap(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Menghapus role yang masih dipakai akan mengosongkan role_id
                    // penggunanya, dan mereka langsung kehilangan seluruh akses.
                    ->disabled(fn (Role $record): bool => $record->users()->exists())
                    ->tooltip(fn (Role $record): ?string => $record->users()->exists()
                        ? 'Masih dipakai ' . $record->users()->count() . ' pengguna.'
                        : null),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit'   => EditRole::route('/{record}/edit'),
        ];
    }
}
