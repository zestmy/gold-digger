<?php

namespace App\Filament\Resources\BrokerAccountResource\Pages;

use App\Filament\Resources\BrokerAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBrokerAccount extends EditRecord
{
    protected static string $resource = BrokerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
