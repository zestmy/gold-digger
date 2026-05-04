<?php

namespace App\Filament\Resources\BotLogResource\Pages;

use App\Filament\Resources\BotLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBotLog extends EditRecord
{
    protected static string $resource = BotLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
