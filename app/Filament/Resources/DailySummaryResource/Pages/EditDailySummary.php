<?php

namespace App\Filament\Resources\DailySummaryResource\Pages;

use App\Filament\Resources\DailySummaryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailySummary extends EditRecord
{
    protected static string $resource = DailySummaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
