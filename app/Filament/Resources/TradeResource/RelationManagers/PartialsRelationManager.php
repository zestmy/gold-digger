<?php

namespace App\Filament\Resources\TradeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PartialsRelationManager extends RelationManager
{
    protected static string $relationship = 'partials';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('mt5_deal_ticket')
                    ->numeric(),
                Forms\Components\TextInput::make('closed_lot_size')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('close_price')
                    ->numeric()
                    ->required(),
                Forms\Components\Select::make('close_reason')
                    ->options([
                        'tp1' => 'TP1',
                        'tp2' => 'TP2',
                        'tp3' => 'TP3',
                        'sl' => 'Stop Loss',
                        'reversal_exit' => 'Reversal Exit',
                        'time_exit' => 'Time Exit',
                        'manual' => 'Manual',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('pips_profit')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('gross_money_profit')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('commission_money')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('swap_money')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('net_money_profit')
                    ->numeric()
                    ->required(),
                Forms\Components\DateTimePicker::make('closed_at')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('close_reason')
            ->columns([
                Tables\Columns\TextColumn::make('close_reason')
                    ->badge(),
                Tables\Columns\TextColumn::make('closed_lot_size')
                    ->numeric(4),
                Tables\Columns\TextColumn::make('close_price')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('pips_profit')
                    ->numeric(1)
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('net_money_profit')
                    ->money('USD')
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('closed_at')
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
