<?php

namespace App\Filament\Resources\TradeScreenshotResource\Pages;

use App\Filament\Resources\TradeScreenshotResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTradeScreenshot extends EditRecord
{
    protected static string $resource = TradeScreenshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
