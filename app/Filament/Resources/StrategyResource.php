<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StrategyResource\Pages;
use App\Models\Strategy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StrategyResource extends Resource
{
    protected static ?string $model = Strategy::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Trading';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('symbol')
                            ->required()
                            ->default('XAUUSD')
                            ->maxLength(20),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Timeframes')
                    ->schema([
                        Forms\Components\TextInput::make('timeframe_entry')
                            ->required()
                            ->default('M15')
                            ->maxLength(10),
                        Forms\Components\TextInput::make('timeframe_trend')
                            ->required()
                            ->default('H1')
                            ->maxLength(10),
                    ])->columns(2),

                Forms\Components\Section::make('Indicator Settings')
                    ->schema([
                        Forms\Components\TextInput::make('ema_fast')
                            ->numeric()
                            ->required()
                            ->default(9),
                        Forms\Components\TextInput::make('ema_slow')
                            ->numeric()
                            ->required()
                            ->default(21),
                        Forms\Components\TextInput::make('adx_threshold')
                            ->numeric()
                            ->step(0.01)
                            ->default(25),
                        Forms\Components\TextInput::make('atr_period')
                            ->numeric()
                            ->required()
                            ->default(14),
                    ])->columns(2),

                Forms\Components\Section::make('Take Profit Settings')
                    ->schema([
                        Forms\Components\TextInput::make('tp1_pips')
                            ->label('TP1 Pips')
                            ->numeric()
                            ->step(0.01),
                        Forms\Components\TextInput::make('tp1_close_pct')
                            ->label('TP1 Close %')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%'),
                        Forms\Components\TextInput::make('tp2_pips')
                            ->label('TP2 Pips')
                            ->numeric()
                            ->step(0.01),
                        Forms\Components\TextInput::make('tp2_close_pct')
                            ->label('TP2 Close %')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%'),
                        Forms\Components\TextInput::make('tp3_pips')
                            ->label('TP3 Pips')
                            ->numeric()
                            ->step(0.01),
                        Forms\Components\TextInput::make('tp3_close_pct')
                            ->label('TP3 Close %')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%'),
                    ])->columns(3),

                Forms\Components\Section::make('Stop Loss & Exit Settings')
                    ->schema([
                        Forms\Components\TextInput::make('sl_atr_multiplier')
                            ->label('SL ATR Multiplier')
                            ->numeric()
                            ->step(0.01)
                            ->default(1.5),
                        Forms\Components\Toggle::make('exit_on_reversal')
                            ->default(true),
                        Forms\Components\TextInput::make('max_holding_bars')
                            ->numeric()
                            ->default(96),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('symbol')
                    ->searchable(),
                Tables\Columns\TextColumn::make('timeframe_entry')
                    ->label('Entry TF'),
                Tables\Columns\TextColumn::make('timeframe_trend')
                    ->label('Trend TF'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
            'index' => Pages\ListStrategies::route('/'),
            'create' => Pages\CreateStrategy::route('/create'),
            'edit' => Pages\EditStrategy::route('/{record}/edit'),
        ];
    }
}
