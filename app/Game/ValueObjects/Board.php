<?php

declare(strict_types=1);

namespace App\Game\ValueObjects;

use App\Game\Enums\Difficulty;

/**
 * Tablero inmutable representado por una lista plana de celdas.
 *
 * Índice plano = fila * columnas + columna, lo que permite acceso O(1)
 * sin arrays anidados ni referencias compartidas.
 *
 * Es un `readonly class`: cualquier cambio produce una instancia nueva.
 * El tablero nunca conoce la sesión ni Livewire; es lógica pura.
 */
final readonly class Board
{
    /**
     * @param  list<Cell>  $cells
     */
    private function __construct(
        public Difficulty $difficulty,
        public array $cells,
        public bool $minesPlaced = false,
    ) {}

    public static function blank(Difficulty $difficulty): self
    {
        /** @var list<Cell> $cells */
        $cells = array_map(
            static fn (): Cell => new Cell,
            range(1, $difficulty->size()),
        );

        return new self($difficulty, $cells);
    }

    /**
     * @param  list<Cell>  $cells
     */
    public static function fromCells(Difficulty $difficulty, array $cells, bool $minesPlaced): self
    {
        return new self($difficulty, array_values($cells), $minesPlaced);
    }

    public function rows(): int
    {
        return $this->difficulty->rows();
    }

    public function columns(): int
    {
        return $this->difficulty->columns();
    }

    public function contains(int $row, int $column): bool
    {
        return $row >= 0 && $row < $this->rows()
            && $column >= 0 && $column < $this->columns();
    }

    public function index(int $row, int $column): int
    {
        return $row * $this->columns() + $column;
    }

    public function cell(int $row, int $column): Cell
    {
        return $this->cells[$this->index($row, $column)];
    }

    #[\NoDiscard]
    public function withCell(int $row, int $column, Cell $cell): self
    {
        $cells = $this->cells;
        $cells[$this->index($row, $column)] = $cell;

        return new self($this->difficulty, $cells, $this->minesPlaced);
    }

    /**
     * @param  list<Cell>  $cells
     */
    #[\NoDiscard]
    public function withCells(array $cells, ?bool $minesPlaced = null): self
    {
        return new self(
            $this->difficulty,
            array_values($cells),
            $minesPlaced ?? $this->minesPlaced,
        );
    }

    /**
     * Coordenadas de las 8 celdas vecinas (sin salir del tablero).
     *
     * @return list<array{int, int}>
     */
    public function neighbours(int $row, int $column): array
    {
        $offsets = [
            [-1, -1], [-1, 0], [-1, 1],
            [0, -1],           [0, 1],
            [1, -1],  [1, 0],  [1, 1],
        ];

        $neighbours = [];

        foreach ($offsets as [$rowOffset, $columnOffset]) {
            $neighbourRow = $row + $rowOffset;
            $neighbourColumn = $column + $columnOffset;

            if ($this->contains($neighbourRow, $neighbourColumn)) {
                $neighbours[] = [$neighbourRow, $neighbourColumn];
            }
        }

        return $neighbours;
    }

    public function minesPlacedCount(): int
    {
        $count = 0;

        foreach ($this->cells as $cell) {
            if ($cell->mine) {
                $count++;
            }
        }

        return $count;
    }

    public function revealedSafeCells(): int
    {
        $count = 0;

        foreach ($this->cells as $cell) {
            if ($cell->revealed && ! $cell->mine) {
                $count++;
            }
        }

        return $count;
    }

    public function safeCellsTotal(): int
    {
        return $this->difficulty->size() - $this->difficulty->mines();
    }

    public function flagsPlaced(): int
    {
        $count = 0;

        foreach ($this->cells as $cell) {
            if ($cell->flagged) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{difficulty: string, mines_placed: bool, cells: list<array{m: bool, r: bool, f: bool, a: int, x: bool}>}
     */
    public function toArray(): array
    {
        return [
            'difficulty' => $this->difficulty->value,
            'mines_placed' => $this->minesPlaced,
            'cells' => array_map(
                static fn (Cell $cell): array => $cell->toArray(),
                $this->cells,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(Difficulty $difficulty, array $data): self
    {
        $rawCells = array_filter(
            (array) ($data['cells'] ?? []),
            static fn (mixed $cell): bool => is_array($cell),
        );

        $cells = array_map(
            static fn (array $cell): Cell => Cell::fromArray($cell),
            array_values($rawCells),
        );

        $minesPlaced = (bool) ($data['mines_placed'] ?? false);

        // Estado corrupto o incompleto: se reinicia el tablero.
        if (count($cells) !== $difficulty->size()) {
            return self::blank($difficulty);
        }

        return new self($difficulty, array_values($cells), $minesPlaced);
    }
}
