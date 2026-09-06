<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SignalResource\Pages;
use App\Models\Scopes\TenantScope;
use App\Models\Signal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class SignalResource extends Resource
{
    protected static ?string $model = Signal::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Trading';

    protected static ?int $navigationSort = 3;

    /**
     * A signal carries no `user_id` and is not tenant-scoped itself; it belongs to whoever
     * owns its strategy. The strategy is what the tenant filter would hide, so it is
     * loaded here without it - otherwise every signal but the administrator's own would
     * list with a blank strategy. See TradeResource::getEloquentQuery().
     */
    public static function getEloquentQuery(): Builder
    {
        return static::getModel()::query()->with([
            'strategy' => fn (Relation $query) => $query->withoutGlobalScope(TenantScope::class),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Signal Information')
                    ->schema([
                        // Strategies and trades are tenant-scoped; without the modifier
                        // these lists would offer only the administrator's own rows.
                        Forms\Components\Select::make('strategy_id')
                            ->relationship('strategy', 'name', modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScope(TenantScope::class))
                            ->required(),
                        Forms\Components\TextInput::make('symbol')
                            ->required()
                            ->default('XAUUSD')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('timeframe')
                            ->required()
                            ->maxLength(10),
                        Forms\Components\Select::make('direction')
                            ->options([
                                'buy' => 'Buy',
                                'sell' => 'Sell',
                            ])
                            ->required(),
                        Forms\Components\DateTimePicker::make('generated_at')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Price Levels')
                    ->schema([
                        Forms\Components\TextInput::make('entry_price')
                            ->numeric()
                            ->step(0.00001)
                            ->required(),
                        Forms\Components\TextInput::make('sl_price')
                            ->label('Stop Loss Price')
                            ->numeric()
                            ->step(0.00001)
                            ->required(),
                        Forms\Components\TextInput::make('tp1_price')
                            ->label('TP1 Price')
                            ->numeric()
                            ->step(0.00001),
                        Forms\Components\TextInput::make('tp2_price')
                            ->label('TP2 Price')
                            ->numeric()
                            ->step(0.00001),
                        Forms\Components\TextInput::make('tp3_price')
                            ->label('TP3 Price')
                            ->numeric()
                            ->step(0.00001),
                    ])->columns(3),

                Forms\Components\Section::make('Signal Quality')
                    ->schema([
                        Forms\Components\TextInput::make('suggested_lot_size')
                            ->numeric()
                            ->step(0.0001),
                        Forms\Components\TextInput::make('confidence_score')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(1),
                        Forms\Components\KeyValue::make('features')
                            ->label('Indicator Features'),
                    ])->columns(2),

                Forms\Components\Section::make('Execution Status')
                    ->schema([
                        Forms\Components\Toggle::make('was_executed')
                            ->default(false),
                        Forms\Components\TextInput::make('skip_reason')
                            ->maxLength(255),
                        Forms\Components\Select::make('resulting_trade_id')
                            ->relationship('resultingTrade', 'id', modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScope(TenantScope::class))
                            ->label('Resulting Trade'),
                    ])->columns(3),
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
                Tables\Columns\TextColumn::make('timeframe'),
                Tables\Columns\TextColumn::make('entry_price')
                    ->numeric(5),
                Tables\Columns\TextColumn::make('sl_price')
                    ->label('SL')
                    ->numeric(5)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tp1_price')
                    ->label('TP1')
                    ->numeric(5)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('was_executed')
                    ->label('Executed')
                    ->boolean(),
                Tables\Columns\TextColumn::make('skip_reason')
                    ->label('Skip Reason')
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->numeric(2)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('strategy.name')
                    ->label('Strategy')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        'buy' => 'Buy',
                        'sell' => 'Sell',
                    ]),
                Tables\Filters\TernaryFilter::make('was_executed')
                    ->label('Executed'),
                Tables\Filters\SelectFilter::make('strategy')
                    ->relationship('strategy', 'name', fn (Builder $query) => $query->withoutGlobalScope(TenantScope::class)),
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
            ->defaultSort('generated_at', 'desc');
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
            'index' => Pages\ListSignals::route('/'),
            'create' => Pages\CreateSignal::route('/create'),
            'edit' => Pages\EditSignal::route('/{record}/edit'),
        ];
    }
}
