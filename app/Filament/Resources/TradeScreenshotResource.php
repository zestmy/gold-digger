<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TradeScreenshotResource\Pages;
use App\Models\TradeScreenshot;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TradeScreenshotResource extends Resource
{
    protected static ?string $model = TradeScreenshot::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Media';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Screenshot Information')
                    ->schema([
                        Forms\Components\Select::make('trade_id')
                            ->relationship('trade', 'id')
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('screenshot_type')
                            ->options([
                                'entry' => 'Entry',
                                'exit' => 'Exit',
                                'tp1' => 'Take Profit 1',
                                'tp2' => 'Take Profit 2',
                                'tp3' => 'Take Profit 3',
                                'sl' => 'Stop Loss',
                                'analysis' => 'Analysis',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('timeframe')
                            ->maxLength(10),
                        Forms\Components\DateTimePicker::make('captured_at')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('File Upload')
                    ->schema([
                        Forms\Components\FileUpload::make('file_path')
                            ->label('Screenshot')
                            ->image()
                            ->disk('public')
                            ->directory('screenshots')
                            ->visibility('public')
                            ->imageEditor()
                            ->required(),
                        Forms\Components\TextInput::make('file_size_kb')
                            ->label('File Size (KB)')
                            ->numeric()
                            ->disabled(),
                    ])->columns(1),

                Forms\Components\Section::make('Additional Details')
                    ->schema([
                        Forms\Components\TextInput::make('price_at_capture')
                            ->numeric()
                            ->step(0.00001),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull()
                            ->rows(3),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\ImageColumn::make('file_path')
                    ->label('Preview')
                    ->disk('public')
                    ->square()
                    ->size(50),
                Tables\Columns\TextColumn::make('trade.id')
                    ->label('Trade ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('screenshot_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'entry' => 'info',
                        'exit' => 'warning',
                        'tp1', 'tp2', 'tp3' => 'success',
                        'sl' => 'danger',
                        'analysis' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('timeframe')
                    ->label('TF'),
                Tables\Columns\TextColumn::make('price_at_capture')
                    ->label('Price')
                    ->numeric(5)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('file_size_kb')
                    ->label('Size')
                    ->suffix(' KB')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('captured_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('screenshot_type')
                    ->options([
                        'entry' => 'Entry',
                        'exit' => 'Exit',
                        'tp1' => 'Take Profit 1',
                        'tp2' => 'Take Profit 2',
                        'tp3' => 'Take Profit 3',
                        'sl' => 'Stop Loss',
                        'analysis' => 'Analysis',
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
            ->defaultSort('captured_at', 'desc');
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
            'index' => Pages\ListTradeScreenshots::route('/'),
            'create' => Pages\CreateTradeScreenshot::route('/create'),
            'edit' => Pages\EditTradeScreenshot::route('/{record}/edit'),
        ];
    }
}
