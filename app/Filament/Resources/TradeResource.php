<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TradeResource\Pages;
use App\Filament\Resources\TradeResource\RelationManagers;
use App\Models\Trade;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TradeResource extends Resource
{
    protected static ?string $model = Trade::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Trading';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Trade Details')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\Select::make('strategy_id')
                            ->relationship('strategy', 'name')
                            ->required(),
                        Forms\Components\Select::make('broker_account_id')
                            ->relationship('brokerAccount', 'label')
                            ->required(),
                        Forms\Components\TextInput::make('mt5_ticket')
                            ->numeric(),
                        Forms\Components\TextInput::make('magic_number')
                            ->numeric(),
                        Forms\Components\TextInput::make('symbol')
                            ->required()
                            ->default('XAUUSD'),
                        Forms\Components\Select::make('direction')
                            ->options([
                                'buy' => 'Buy',
                                'sell' => 'Sell',
                            ])
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Position Size & Prices')
                    ->schema([
                        Forms\Components\TextInput::make('initial_lot_size')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('remaining_lot_size')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('entry_price')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('sl_price')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('tp1_price')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('tp2_price')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('tp3_price')
                            ->numeric(),
                    ])->columns(3),

                Forms\Components\Section::make('Costs')
                    ->schema([
                        Forms\Components\TextInput::make('entry_spread_pips')
                            ->numeric(),
                        Forms\Components\TextInput::make('entry_spread_money')
                            ->numeric(),
                        Forms\Components\TextInput::make('commission_money')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('swap_money')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('slippage_pips')
                            ->numeric(),
                    ])->columns(3),

                Forms\Components\Section::make('P&L')
                    ->schema([
                        Forms\Components\TextInput::make('gross_pnl_pips')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('gross_pnl_money')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('net_pnl_money')
                            ->numeric()
                            ->default(0),
                    ])->columns(3),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'open' => 'Open',
                                'partially_closed' => 'Partially Closed',
                                'fully_closed' => 'Fully Closed',
                                'stopped_out' => 'Stopped Out',
                                'cancelled' => 'Cancelled',
                            ])
                            ->required()
                            ->default('pending'),
                        Forms\Components\TextInput::make('closure_reason'),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('opened_at'),
                        Forms\Components\DateTimePicker::make('closed_at'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('symbol')
                    ->searchable(),
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'buy' => 'success',
                        'sell' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('initial_lot_size')
                    ->numeric(4),
                Tables\Columns\TextColumn::make('entry_price')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'open' => 'info',
                        'partially_closed' => 'warning',
                        'fully_closed' => 'success',
                        'stopped_out' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('gross_pnl_pips')
                    ->numeric(1)
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('net_pnl_money')
                    ->money('USD')
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'open' => 'Open',
                        'partially_closed' => 'Partially Closed',
                        'fully_closed' => 'Fully Closed',
                        'stopped_out' => 'Stopped Out',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        'buy' => 'Buy',
                        'sell' => 'Sell',
                    ]),
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
            RelationManagers\PartialsRelationManager::class,
            RelationManagers\ScreenshotsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrades::route('/'),
            'create' => Pages\CreateTrade::route('/create'),
            'edit' => Pages\EditTrade::route('/{record}/edit'),
        ];
    }
}
