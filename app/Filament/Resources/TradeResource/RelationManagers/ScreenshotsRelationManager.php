<?php

namespace App\Filament\Resources\TradeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ScreenshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'screenshots';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('screenshot_type')
                    ->options([
                        'entry' => 'Entry',
                        'tp1_hit' => 'TP1 Hit',
                        'tp2_hit' => 'TP2 Hit',
                        'tp3_hit' => 'TP3 Hit',
                        'sl_hit' => 'SL Hit',
                        'reversal_exit' => 'Reversal Exit',
                        'time_exit' => 'Time Exit',
                        'manual_review' => 'Manual Review',
                    ])
                    ->required(),
                Forms\Components\FileUpload::make('file_path')
                    ->image()
                    ->disk('public')
                    ->directory('screenshots')
                    ->required(),
                Forms\Components\TextInput::make('file_size_kb')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('timeframe'),
                Forms\Components\TextInput::make('price_at_capture')
                    ->numeric()
                    ->required(),
                Forms\Components\Textarea::make('notes'),
                Forms\Components\DateTimePicker::make('captured_at')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('screenshot_type')
            ->columns([
                Tables\Columns\ImageColumn::make('file_path')
                    ->disk('public')
                    ->size(60),
                Tables\Columns\TextColumn::make('screenshot_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('timeframe'),
                Tables\Columns\TextColumn::make('price_at_capture')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('captured_at')
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
