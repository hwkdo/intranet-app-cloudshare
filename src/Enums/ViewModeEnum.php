<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Enums;

enum ViewModeEnum: string
{
    case Grid = 'grid';
    case List = 'list';
    case Table = 'table';

    public static function options(): array
    {
        return [
            self::Grid->value => 'Raster-Ansicht',
            self::List->value => 'Listen-Ansicht',
            self::Table->value => 'Tabellen-Ansicht',
        ];
    }
}
