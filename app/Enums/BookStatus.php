<?php

namespace App\Enums;

enum BookStatus: string
{
    case Draft = 'draft';
    case WaitingMandalas = 'waiting_mandalas';
    case Ready = 'ready';
    case Exported = 'exported';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::WaitingMandalas => 'Esperando mandalas',
            self::Ready => 'Listo',
            self::Exported => 'Exportado',
            self::Error => 'Error',
        };
    }
}
