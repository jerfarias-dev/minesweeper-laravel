<?php

declare(strict_types=1);

namespace App\Game\ValueObjects;

/**
 * Celda inmutable del tablero.
 *
 * PHP 8.4/8.5 en acción:
 *  - `private(set)` impide que nadie fuera de la clase mute el estado.
 *  - `isEmpty` es una propiedad virtual (property hook), no ocupa memoria
 *    ni se serializa: se calcula al vuelo.
 *  - `#[\NoDiscard]` obliga a usar el valor devuelto por los `with*()`;
 *    si alguien olvida asignarlo, PHP emite un warning en tiempo de ejecución.
 *
 * Todas las transiciones devuelven una *nueva* celda: el tablero nunca se
 * modifica in-place, lo que hace triviales el deshacer, el testing y la
 * comparación de estados.
 */
final class Cell
{
    public function __construct(
        public private(set) bool $mine = false,
        public private(set) bool $revealed = false,
        public private(set) bool $flagged = false,
        public private(set) int $adjacent = 0,
        public private(set) bool $exploded = false,
    ) {}

    /**
     * Una celda vacía (sin mina y sin vecinas) es la que dispara la cascada.
     */
    public bool $isEmpty {
        get => ! $this->mine && $this->adjacent === 0;
    }

    public bool $isHidden {
        get => ! $this->revealed;
    }

    #[\NoDiscard]
    public function revealed(): self
    {
        $clone = clone $this;
        $clone->revealed = true;

        return $clone;
    }

    #[\NoDiscard]
    public function flagged(): self
    {
        $clone = clone $this;
        $clone->flagged = true;

        return $clone;
    }

    #[\NoDiscard]
    public function unflagged(): self
    {
        $clone = clone $this;
        $clone->flagged = false;

        return $clone;
    }

    #[\NoDiscard]
    public function withMine(bool $mine = true): self
    {
        $clone = clone $this;
        $clone->mine = $mine;

        return $clone;
    }

    #[\NoDiscard]
    public function withAdjacent(int $adjacent): self
    {
        $clone = clone $this;
        $clone->adjacent = $adjacent;

        return $clone;
    }

    #[\NoDiscard]
    public function exploded(): self
    {
        $clone = clone $this;
        $clone->revealed = true;
        $clone->exploded = true;

        return $clone;
    }

    /**
     * Serialización compacta para guardar la partida en sesión.
     *
     * @return array{m: bool, r: bool, f: bool, a: int, x: bool}
     */
    public function toArray(): array
    {
        return [
            'm' => $this->mine,
            'r' => $this->revealed,
            'f' => $this->flagged,
            'a' => $this->adjacent,
            'x' => $this->exploded,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mine: (bool) ($data['m'] ?? false),
            revealed: (bool) ($data['r'] ?? false),
            flagged: (bool) ($data['f'] ?? false),
            adjacent: (int) ($data['a'] ?? 0),
            exploded: (bool) ($data['x'] ?? false),
        );
    }
}
