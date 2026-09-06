<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotLogResource\Pages;
use App\Models\BotLog;
use App\Models\Scopes\TenantScope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class BotLogResource extends Resource
{
    protected static ?string $model = BotLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    /**
     * Every tenant's logs. See TradeResource::getEloquentQuery() for why the console has
     * to say so explicitly, and why the scoped relation is declared here.
     */
    public static function getEloquentQuery(): Builder
    {
        return static::getModel()::acrossTenants()->with([
            'signal',
            'trade' => fn (Relation $query) => $query->withoutGlobalScope(TenantScope::class),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Log Entry')
                    ->schema([
                        Forms\Components\Select::make('level')
                            ->options([
                                'debug' => 'Debug',
                                'info' => 'Info',
                                'warning' => 'Warning',
                                'error' => 'Error',
                                'critical' => 'Critical',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('source')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('message')
                            ->required()
                            ->columnSpanFull()
                            ->rows(3),
                    ])->columns(2),

                Forms\Components\Section::make('Context & Relations')
                    ->schema([
                        Forms\Components\KeyValue::make('context')
                            ->label('Context Data'),
                        Forms\Components\Select::make('related_trade_id')
                            ->relationship('trade', 'id', modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScope(TenantScope::class))
                            ->label('Related Trade')
                            ->searchable(),
                        Forms\Components\Select::make('related_signal_id')
                            ->relationship('signal', 'id')
                            ->label('Related Signal')
                            ->searchable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'debug' => 'gray',
                        'info' => 'info',
                        'warning' => 'warning',
                        'error' => 'danger',
                        'critical' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('message')
                    ->searchable()
                    ->limit(50)
                    ->wrap(),
                Tables\Columns\TextColumn::make('trade.id')
                    ->label('Trade')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('signal.id')
                    ->label('Signal')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->options([
                        'debug' => 'Debug',
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'error' => 'Error',
                        'critical' => 'Critical',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn () => BotLog::acrossTenants()->distinct()->pluck('source', 'source')->toArray()),
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
            'index' => Pages\ListBotLogs::route('/'),
            'create' => Pages\CreateBotLog::route('/create'),
            'edit' => Pages\EditBotLog::route('/{record}/edit'),
        ];
    }
}
