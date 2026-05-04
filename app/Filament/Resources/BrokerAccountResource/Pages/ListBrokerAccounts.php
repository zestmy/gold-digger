<?php

namespace App\Filament\Resources\BrokerAccountResource\Pages;

use App\Filament\Resources\BrokerAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBrokerAccounts extends ListRecords
{
    protected static string $resource = BrokerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
