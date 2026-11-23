<?php

declare(strict_types=1);

namespace App\Game\Enums;

/**
 * Ciclo de vida de una partida.
 *
 * Pending: el tablero aún no tiene minas. Se generan en el primer clic,
 *          lo que garantiza un primer movimiento siempre seguro.
 */
enum GameStatus: string
{
    case Pending = 'pending';
    case Playing = 'playing';
    case Won = 'won';
    case Lost = 'lost';

    public function isFinished(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    public function isActive(): bool
    {
        return $this === self::Playing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Haz tu primer clic',
            self::Playing => 'Partida en curso',
            self::Won => '¡Ganaste!',
            self::Lost => '¡Boom! Has perdido',
        };
    }
}
