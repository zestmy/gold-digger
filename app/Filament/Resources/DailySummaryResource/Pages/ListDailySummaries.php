<?php

namespace App\Filament\Resources\DailySummaryResource\Pages;

use App\Filament\Resources\DailySummaryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailySummaries extends ListRecords
{
    protected static string $resource = DailySummaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
