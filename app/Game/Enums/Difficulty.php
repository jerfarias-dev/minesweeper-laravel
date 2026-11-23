<?php

declare(strict_types=1);

namespace App\Game\Enums;

/**
 * Define los tres niveles clásicos del Buscaminas.
 *
 * Todo el dimensionamiento del tablero vive aquí: así el servicio, el
 * componente Livewire y la vista nunca duplican "números mágicos".
 */
enum Difficulty: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Expert = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Principiante',
            self::Intermediate => 'Intermedio',
            self::Expert => 'Experto',
        };
    }

    public function rows(): int
    {
        return match ($this) {
            self::Beginner => 8,
            self::Intermediate => 16,
            self::Expert => 16,
        };
    }

    public function columns(): int
    {
        return match ($this) {
            self::Beginner => 8,
            self::Intermediate => 16,
            self::Expert => 30,
        };
    }

    public function mines(): int
    {
        return match ($this) {
            self::Beginner => 10,
            self::Intermediate => 40,
            self::Expert => 99,
        };
    }

    public function size(): int
    {
        return $this->rows() * $this->columns();
    }

    /**
     * Porcentaje de celdas minadas. Útil para mostrar información del nivel.
     */
    public function mineRatio(): float
    {
        return round(($this->mines() / $this->size()) * 100, 1);
    }
}
