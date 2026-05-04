<?php

namespace App\Filament\Resources\BotSettingsResource\Pages;

use App\Filament\Resources\BotSettingsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBotSettings extends EditRecord
{
    protected static string $resource = BotSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
