<?php

namespace App\Filament\Workshop\Resources\Users;

use App\Filament\Workshop\Resources\Users\Pages\CreateUser;
use App\Filament\Workshop\Resources\Users\Pages\EditUser;
use App\Filament\Workshop\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | \UnitEnum | null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('users') ?? false;
    }

    public static function getModelLabel(): string { return 'Pengguna'; }

    public static function getPluralModelLabel(): string { return 'Pengguna'; }

    public static function getNavigationLabel(): string { return 'Pengguna'; }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('role', 'workshop');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Akun')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Lengkap')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('password')
                        ->label('Kata Sandi')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'edit' ? 'Kosongkan jika tidak ingin mengubah kata sandi.' : '')
                        ->maxLength(255),
                ]),

            Section::make('Role')
                ->description('Akses menu ditentukan oleh role. Ubah daftar izinnya di menu Role.')
                ->schema([
                    Select::make('role_id')
                        ->label('Role')
                        ->relationship(
                            name: 'accessRole',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->where('panel', 'workshop'),
                        )
                        ->required()
                        ->preload()
                        ->searchable()
                        ->helperText(fn ($state): string => filled($state)
                            ? 'Izin: ' . collect(\App\Models\Role::find($state)?->permissions ?? [])
                                ->map(fn (string $p): string => \App\Models\Role::WORKSHOP_PERMISSIONS[$p] ?? $p)
                                ->join(', ')
                            : 'Pilih role untuk melihat daftar izinnya.')
                        ->live(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('accessRole.name')
                    ->label('Role')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Ditambahkan')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }
}
