<?php

namespace App\Filament\Resources\TradePartialResource\Pages;

use App\Filament\Resources\TradePartialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTradePartial extends EditRecord
{
    protected static string $resource = TradePartialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
