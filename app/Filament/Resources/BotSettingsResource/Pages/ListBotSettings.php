<?php

namespace App\Filament\Resources\BotSettingsResource\Pages;

use App\Filament\Resources\BotSettingsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotSettings extends ListRecords
{
    protected static string $resource = BotSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
