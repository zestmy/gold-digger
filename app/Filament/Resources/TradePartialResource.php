<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TradePartialResource\Pages;
use App\Models\TradePartial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TradePartialResource extends Resource
{
    protected static ?string $model = TradePartial::class;

    protected static ?string $navigationIcon = 'heroicon-o-scissors';

    protected static ?string $navigationGroup = 'Media';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Partial Close Information')
                    ->schema([
                        Forms\Components\Select::make('trade_id')
                            ->relationship('trade', 'id')
                            ->required()
                            ->searchable(),
                        Forms\Components\TextInput::make('mt5_deal_ticket')
                            ->label('MT5 Deal Ticket')
                            ->numeric(),
                        Forms\Components\Select::make('close_reason')
                            ->options([
                                'tp1' => 'Take Profit 1',
                                'tp2' => 'Take Profit 2',
                                'tp3' => 'Take Profit 3',
                                'sl' => 'Stop Loss',
                                'manual' => 'Manual Close',
                                'reversal' => 'Signal Reversal',
                                'max_bars' => 'Max Holding Bars',
                            ])
                            ->required(),
                        Forms\Components\DateTimePicker::make('closed_at')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Position Details')
                    ->schema([
                        Forms\Components\TextInput::make('closed_lot_size')
                            ->label('Closed Lot Size')
                            ->numeric()
                            ->step(0.0001)
                            ->required(),
                        Forms\Components\TextInput::make('close_price')
                            ->numeric()
                            ->step(0.00001)
                            ->required(),
                        Forms\Components\TextInput::make('pips_profit')
                            ->label('Pips Profit')
                            ->numeric()
                            ->step(0.01),
                    ])->columns(3),

                Forms\Components\Section::make('Money Values')
                    ->schema([
                        Forms\Components\TextInput::make('gross_money_profit')
                            ->label('Gross Profit')
                            ->numeric()
                            ->step(0.0001)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('commission_money')
                            ->label('Commission')
                            ->numeric()
                            ->step(0.0001)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('swap_money')
                            ->label('Swap')
                            ->numeric()
                            ->step(0.0001)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('net_money_profit')
                            ->label('Net Profit')
                            ->numeric()
                            ->step(0.0001)
                            ->prefix('$'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('trade.id')
                    ->label('Trade ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('mt5_deal_ticket')
                    ->label('MT5 Ticket')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('close_reason')
                    ->label('Reason')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'tp1', 'tp2', 'tp3' => 'success',
                        'sl' => 'danger',
                        'manual' => 'warning',
                        'reversal' => 'info',
                        'max_bars' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('closed_lot_size')
                    ->label('Lots')
                    ->numeric(4),
                Tables\Columns\TextColumn::make('close_price')
                    ->label('Price')
                    ->numeric(5),
                Tables\Columns\TextColumn::make('pips_profit')
                    ->label('Pips')
                    ->numeric(2)
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('net_money_profit')
                    ->label('Net P&L')
                    ->money('USD')
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('closed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('close_reason')
                    ->options([
                        'tp1' => 'Take Profit 1',
                        'tp2' => 'Take Profit 2',
                        'tp3' => 'Take Profit 3',
                        'sl' => 'Stop Loss',
                        'manual' => 'Manual Close',
                        'reversal' => 'Signal Reversal',
                        'max_bars' => 'Max Holding Bars',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('closed_at', 'desc');
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
            'index' => Pages\ListTradePartials::route('/'),
            'create' => Pages\CreateTradePartial::route('/create'),
            'edit' => Pages\EditTradePartial::route('/{record}/edit'),
        ];
    }
}
