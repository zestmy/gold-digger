<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotSettingsResource\Pages;
use App\Models\BotSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bot Settings Resource
 *
 * Every column on `bot_settings`, which is more than the tenant's own Settings page
 * shows. The difference is deliberate: `ai_daily_call_limit` is an entitlement the
 * platform grants, not a preference the tenant chooses, and until this form existed the
 * only way to set one was SQL.
 *
 * The sections follow the columns' own history - each group of settings arrived with a
 * migration that explains it, and the helper text here is a summary of those. Read the
 * migration before changing a default.
 */
class BotSettingsResource extends Resource
{
    protected static ?string $model = BotSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 2;

    protected static ?string $pluralModelLabel = 'Bot Settings';

    /**
     * Every tenant's settings. See TradeResource::getEloquentQuery() for why the console
     * has to say so explicitly.
     */
    public static function getEloquentQuery(): Builder
    {
        return static::getModel()::acrossTenants()->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Trading')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Bot active')
                            ->helperText('The master switch. Off, and nothing below matters.')
                            ->default(false),
                        Forms\Components\TextInput::make('max_concurrent_trades')
                            ->label('Max open trades')
                            ->numeric()
                            ->minValue(0)
                            ->default(3),
                        Forms\Components\TextInput::make('min_atr_threshold')
                            ->label('Minimum ATR')
                            ->helperText('Skip signals when volatility is below this. Blank means no floor.')
                            ->numeric()
                            ->step(0.01),
                        Forms\Components\TextInput::make('min_reward_ratio')
                            ->label('Minimum reward : risk')
                            ->helperText('Measured to the take-profit the order actually carries. 1.5 means make at least one and a half times what is risked. Blank uses the platform default.')
                            ->numeric()
                            ->step(0.01),
                        Forms\Components\TextInput::make('min_confluence')
                            ->label('Minimum confluence')
                            ->helperText('Weighted factors that must agree before an entry. Half-steps are real. Blank uses the platform default.')
                            ->numeric()
                            ->step(0.5),
                        Forms\Components\TextInput::make('min_directional')
                            ->label('Of which, directional')
                            ->helperText('How much of that agreement must be about direction rather than permission to trade. Blank uses the platform default.')
                            ->numeric()
                            ->step(0.5),
                    ])->columns(2),

                Forms\Components\Section::make('Risk')
                    ->schema([
                        Forms\Components\TextInput::make('risk_percentage')
                            ->label('Risk per trade')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('% of balance')
                            ->default(1.00),
                        Forms\Components\TextInput::make('max_daily_loss_percentage')
                            ->label('Max daily loss')
                            ->helperText('The bot stops for the day once losses reach this share of the starting balance.')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->default(3.00),
                    ])->columns(2),

                Forms\Components\Section::make('Sessions & news')
                    ->schema([
                        Forms\Components\CheckboxList::make('allowed_sessions')
                            ->helperText('Entries are only taken while one of these is open.')
                            ->options([
                                'sydney' => 'Sydney',
                                'tokyo' => 'Tokyo',
                                'london' => 'London',
                                'newyork' => 'New York',
                                'overlap' => 'London / New York overlap',
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('news_filter_enabled')
                            ->label('News filter')
                            ->helperText('Stand aside around high-impact releases.')
                            ->default(true)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('news_blackout_before_minutes')
                            ->label('Minutes before a release')
                            ->numeric()
                            ->minValue(0)
                            ->default(15),
                        Forms\Components\TextInput::make('news_blackout_after_minutes')
                            ->label('Minutes after a release')
                            ->numeric()
                            ->minValue(0)
                            ->default(15),
                    ])->columns(2),

                Forms\Components\Section::make('AI fund')
                    ->description('A separately capped pool the AI may trade. Enabling the fund and letting it trade on its own opinion are different permissions.')
                    ->schema([
                        Forms\Components\Toggle::make('ai_trading_enabled')
                            ->label('AI trading enabled')
                            ->helperText('Whether the fund may be spent at all.')
                            ->default(false),
                        Forms\Components\TextInput::make('ai_capital_cap')
                            ->label('Fund cap')
                            ->helperText('The most the fund may lose. Blank means nobody has decided yet, which is not the same as zero.')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('ai_risk_percentage')
                            ->label('Risk per AI trade')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('% of fund')
                            ->default(1.00),
                        Forms\Components\TextInput::make('ai_max_concurrent_trades')
                            ->label('Max open AI trades')
                            ->numeric()
                            ->minValue(0)
                            ->default(1),
                        Forms\Components\TextInput::make('ai_max_trades_per_day')
                            ->label('AI trades per day')
                            ->helperText('Blank means no ceiling.')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\Toggle::make('ai_autonomous')
                            ->label('Autonomous')
                            ->helperText('Trade on its own read of the chart rather than on somebody else\'s signal.')
                            ->default(false),
                        Forms\Components\TagsInput::make('ai_autonomous_symbols')
                            ->label('Instruments it may consider')
                            ->helperText('Listed, not inferred: a terminal pushing five symbols has not volunteered all five.')
                            ->placeholder('XAUUSD')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Copier')
                    ->description('How a signal copied from a Telegram provider reaches the broker, and how the position is protected once it is on.')
                    ->schema([
                        Forms\Components\Select::make('copier_levels')
                            ->label('Stop and targets')
                            ->helperText('"Provider" trades the levels as posted. "Strategy" keeps the provider\'s entry and applies this account\'s own stop and ladder.')
                            ->options([
                                'provider' => 'Provider\'s levels',
                                'strategy' => 'This account\'s strategy',
                            ])
                            ->default('provider')
                            ->required(),
                        Forms\Components\TextInput::make('copier_protect_at_r')
                            ->label('Protect at')
                            ->helperText('Profit, in R, at which the protection below kicks in. Blank switches it off.')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('R'),
                        Forms\Components\Toggle::make('copier_breakeven')
                            ->label('Move stop to break-even')
                            ->default(true),
                        Forms\Components\TextInput::make('copier_breakeven_offset_pips')
                            ->label('Break-even offset')
                            ->helperText('How far past entry the stop goes, to clear spread and commission.')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('pips'),
                        Forms\Components\TextInput::make('copier_profit_lock_pct')
                            ->label('Bank')
                            ->helperText('Share of the position closed at the trigger. Blank banks nothing.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        Forms\Components\TextInput::make('copier_trail_distance_r')
                            ->label('Trail at')
                            ->helperText('Once triggered, the stop follows the best price at this distance and never retreats. Blank does not trail.')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('R'),
                        Forms\Components\Toggle::make('copier_close_on_opposite')
                            ->label('Close on an opposite signal')
                            ->helperText('A provider posting SELL while their BUY is open has changed their mind. Off by default: it collapses a deliberate hedge.')
                            ->default(false),
                        Forms\Components\Toggle::make('copier_spread_buffer')
                            ->label('Size for the spread')
                            ->helperText('Add the spread to the stop distance used for sizing, so the realised loss is the one intended.')
                            ->default(false),
                    ])->columns(2),

                Forms\Components\Section::make('Entitlements')
                    ->description('What the platform grants this tenant, as opposed to what they choose for themselves.')
                    ->schema([
                        Forms\Components\TextInput::make('ai_daily_call_limit')
                            ->label('AI calls per day')
                            ->helperText('Blank follows the platform default. Zero is a real value: no AI at all.')
                            ->numeric()
                            ->minValue(0),
                    ])->columns(2),
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
                    ->label('Max loss %')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('max_concurrent_trades')
                    ->label('Max trades'),
                Tables\Columns\IconColumn::make('news_filter_enabled')
                    ->label('News filter')
                    ->boolean(),
                Tables\Columns\IconColumn::make('ai_trading_enabled')
                    ->label('AI fund')
                    ->boolean(),
                Tables\Columns\TextColumn::make('ai_daily_call_limit')
                    ->label('AI calls/day')
                    ->placeholder('default')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('ai_trading_enabled')
                    ->label('AI fund'),
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
