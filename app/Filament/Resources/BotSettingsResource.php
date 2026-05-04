<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotSettingsResource\Pages;
use App\Models\BotSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BotSettingsResource extends Resource
{
    protected static ?string $model = BotSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 2;

    protected static ?string $pluralModelLabel = 'Bot Settings';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General Settings')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Bot Active')
                            ->default(false),
                    ])->columns(2),

                Forms\Components\Section::make('Risk Management')
                    ->schema([
                        Forms\Components\TextInput::make('risk_percentage')
                            ->label('Risk per Trade (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->default(1.00),
                        Forms\Components\TextInput::make('max_daily_loss_percentage')
                            ->label('Max Daily Loss (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->default(3.00),
                        Forms\Components\TextInput::make('max_concurrent_trades')
                            ->numeric()
                            ->default(3),
                        Forms\Components\TextInput::make('min_atr_threshold')
                            ->label('Min ATR Threshold')
                            ->numeric()
                            ->step(0.01),
                    ])->columns(2),

                Forms\Components\Section::make('Session Filters')
                    ->schema([
                        Forms\Components\CheckboxList::make('allowed_sessions')
                            ->options([
                                'sydney' => 'Sydney',
                                'tokyo' => 'Tokyo',
                                'london' => 'London',
                                'newyork' => 'New York',
                            ])
                            ->columns(4),
                        Forms\Components\Toggle::make('news_filter_enabled')
                            ->label('Enable News Filter')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Screenshot Settings')
                    ->schema([
                        Forms\Components\Toggle::make('capture_screenshots')
                            ->label('Capture Trade Screenshots')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('risk_percentage')
                    ->label('Risk %')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('max_daily_loss_percentage')
                    ->label('Max Loss %')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('max_concurrent_trades')
                    ->label('Max Trades'),
                Tables\Columns\IconColumn::make('news_filter_enabled')
                    ->label('News Filter')
                    ->boolean(),
                Tables\Columns\IconColumn::make('capture_screenshots')
                    ->label('Screenshots')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
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
            ]);
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
            'index' => Pages\ListBotSettings::route('/'),
            'create' => Pages\CreateBotSettings::route('/create'),
            'edit' => Pages\EditBotSettings::route('/{record}/edit'),
        ];
    }
}
