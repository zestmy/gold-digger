<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailySummaryResource\Pages;
use App\Models\DailySummary;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DailySummaryResource extends Resource
{
    protected static ?string $model = DailySummary::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $pluralModelLabel = 'Daily Summaries';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Summary Information')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\Select::make('broker_account_id')
                            ->relationship('brokerAccount', 'label')
                            ->required(),
                        Forms\Components\DatePicker::make('date')
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Trade Counts')
                    ->schema([
                        Forms\Components\TextInput::make('trades_count')
                            ->label('Total Trades')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('wins_count')
                            ->label('Wins')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('losses_count')
                            ->label('Losses')
                            ->numeric()
                            ->default(0),
                    ])->columns(3),

                Forms\Components\Section::make('Profit & Loss')
                    ->schema([
                        Forms\Components\TextInput::make('gross_pnl_money')
                            ->label('Gross P&L')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('total_costs_money')
                            ->label('Total Costs')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('net_pnl_money')
                            ->label('Net P&L')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('max_drawdown_money')
                            ->label('Max Drawdown')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                    ])->columns(2),

                Forms\Components\Section::make('Balance')
                    ->schema([
                        Forms\Components\TextInput::make('starting_balance')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('ending_balance')
                            ->numeric()
                            ->step(0.01)
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
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('brokerAccount.label')
                    ->label('Account')
                    ->sortable(),
                Tables\Columns\TextColumn::make('trades_count')
                    ->label('Trades')
                    ->sortable(),
                Tables\Columns\TextColumn::make('wins_count')
                    ->label('Wins'),
                Tables\Columns\TextColumn::make('losses_count')
                    ->label('Losses'),
                Tables\Columns\TextColumn::make('gross_pnl_money')
                    ->label('Gross P&L')
                    ->money('USD')
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_pnl_money')
                    ->label('Net P&L')
                    ->money('USD')
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_drawdown_money')
                    ->label('Max DD')
                    ->money('USD')
                    ->color('danger')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('starting_balance')
                    ->label('Start Bal')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ending_balance')
                    ->label('End Bal')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('broker_account')
                    ->relationship('brokerAccount', 'label'),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name'),
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
            ->defaultSort('date', 'desc');
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
            'index' => Pages\ListDailySummaries::route('/'),
            'create' => Pages\CreateDailySummary::route('/create'),
            'edit' => Pages\EditDailySummary::route('/{record}/edit'),
        ];
    }
}
