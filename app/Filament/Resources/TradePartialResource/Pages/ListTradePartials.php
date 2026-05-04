<?php

namespace App\Filament\Resources\TradePartialResource\Pages;

use App\Filament\Resources\TradePartialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTradePartials extends ListRecords
{
    protected static string $resource = TradePartialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
