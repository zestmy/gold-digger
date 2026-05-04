<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrokerAccountResource\Pages;
use App\Models\BrokerAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BrokerAccountResource extends Resource
{
    protected static ?string $model = BrokerAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Information')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('broker_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('server')
                            ->required()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('Account Details')
                    ->schema([
                        Forms\Components\TextInput::make('account_number')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('account_currency')
                            ->default('USD')
                            ->maxLength(10),
                        Forms\Components\TextInput::make('leverage')
                            ->numeric()
                            ->default(100),
                        Forms\Components\Toggle::make('is_demo')
                            ->label('Demo Account')
                            ->default(true),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Balance Information')
                    ->schema([
                        Forms\Components\TextInput::make('last_balance')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('last_equity')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\DateTimePicker::make('last_synced_at')
                            ->label('Last Synced'),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('broker_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('server')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_demo')
                    ->label('Demo')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('account_currency')
                    ->label('Currency'),
                Tables\Columns\TextColumn::make('leverage')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_balance')
                    ->money('USD')
                    ->label('Balance'),
                Tables\Columns\TextColumn::make('last_equity')
                    ->money('USD')
                    ->label('Equity')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_demo')
                    ->label('Demo/Live'),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrokerAccounts::route('/'),
            'create' => Pages\CreateBrokerAccount::route('/create'),
            'edit' => Pages\EditBrokerAccount::route('/{record}/edit'),
        ];
    }
}
