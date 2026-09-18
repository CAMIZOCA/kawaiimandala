<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Done = 'done';
    case Failed = 'failed';
    case Timeout = 'timeout';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Requested => 'Solicitado',
            self::Done => 'Listo',
            self::Failed => 'Error',
            self::Timeout => 'Sin respuesta',
        };
    }

    public function isError(): bool
    {
        return $this === self::Failed || $this === self::Timeout;
    }
}
