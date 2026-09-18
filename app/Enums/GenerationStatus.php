<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Requested => 'Solicitado',
            self::Done => 'Listo',
            self::Failed => 'Error',
        };
    }
}
