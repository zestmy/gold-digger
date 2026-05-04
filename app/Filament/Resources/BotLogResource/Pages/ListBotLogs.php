<?php

namespace App\Filament\Resources\BotLogResource\Pages;

use App\Filament\Resources\BotLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotLogs extends ListRecords
{
    protected static string $resource = BotLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
