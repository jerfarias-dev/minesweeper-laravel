<?php

declare(strict_types=1);

namespace App\Game\Services;

use App\Game\Enums\Difficulty;
use App\Game\Enums\GameStatus;
use App\Game\ValueObjects\Board;
use App\Game\ValueObjects\Cell;
use App\Game\ValueObjects\GameState;

/**
 * Lógica de dominio del Buscaminas.
 *
 * Reglas de oro:
 *  1. No conoce HTTP, sesión, Livewire ni base de datos. Es 100% testeable.
 *  2. No expone las minas: el llamador decide qué mostrar.
 *  3. Todas las operaciones son puras; devuelven un GameState nuevo.
 *
 * El tablero se genera en el PRIMER clic, excluyendo la celda pulsada y sus
 * 8 vecinas. Así el primer movimiento siempre abre una zona segura (nunca
 * puede ser una mina) sin necesidad de recolocar minas a posteriori.
 */
final class MinesweeperService
{
    public function newGame(Difficulty $difficulty): GameState
    {
        return GameState::pending($difficulty);
    }

    /**
     * Revela una celda aplicando: primer clic seguro, cascada y fin de partida.
     */
    #[\NoDiscard]
    public function reveal(GameState $game, int $row, int $column): GameState
    {
        if ($game->isFinished() || ! $game->board->contains($row, $column)) {
            return $game;
        }

        $cell = $game->board->cell($row, $column);

        // Una celda ya revelada o con bandera no se puede volver a abrir.
        if ($cell->revealed || $cell->flagged) {
            return $game;
        }

        // Primer clic: se colocan las minas lejos de la celda pulsada.
        if ($game->status === GameStatus::Pending) {
            $game = $game->started()->withBoard(
                $this->placeMines($game->board, $row, $column),
            );
        }

        if ($game->board->cell($row, $column)->mine) {
            return $this->detonate($game, $row, $column);
        }

        $board = $this->cascade($game->board, $row, $column);

        return $this->settle($game->withBoard($board));
    }

    /**
     * Coloca o retira una bandera. El número de banderas no puede superar
     * el de minas, y una celda revelada no admite bandera.
     */
    #[\NoDiscard]
    public function toggleFlag(GameState $game, int $row, int $column): GameState
    {
        if ($game->isFinished() || ! $game->board->contains($row, $column)) {
            return $game;
        }

        $cell = $game->board->cell($row, $column);

        if ($cell->revealed) {
            return $game;
        }

        if (! $cell->flagged && $game->flagsUsed() >= $game->difficulty->mines()) {
            return $game;
        }

        $cell = $cell->flagged ? $cell->unflagged() : $cell->flagged();

        return $game->withBoard($game->board->withCell($row, $column, $cell));
    }

    /**
     * Genera las minas evitando la celda pulsada y su vecindario (3x3),
     * y calcula los contadores de adyacencia de todas las celdas.
     */
    public function placeMines(Board $board, int $safeRow, int $safeColumn): Board
    {
        $difficulty = $board->difficulty;

        // Índices prohibidos: celda pulsada + 8 vecinas.
        $forbidden = [$board->index($safeRow, $safeColumn) => true];

        foreach ($board->neighbours($safeRow, $safeColumn) as [$row, $column]) {
            $forbidden[$board->index($row, $column)] = true;
        }

        // PHP 8.5: el operador pipe encadena transformaciones de izquierda
        // a derecha, sin variables intermedias.
        $positions = range(0, $difficulty->size() - 1)
            |> (fn (array $indexes): array => array_values(array_diff($indexes, array_keys($forbidden))))
            |> (static fn (array $candidates): array => self::shuffle($candidates));

        $minePositions = array_slice($positions, 0, $difficulty->mines());

        /** @var list<Cell> $cells */
        $cells = $board->cells;

        foreach ($minePositions as $index) {
            $cells[$index] = $cells[$index]->withMine();
        }

        // Contadores de minas adyacentes (solo para celdas seguras).
        for ($row = 0; $row < $board->rows(); $row++) {
            for ($column = 0; $column < $board->columns(); $column++) {
                $index = $board->index($row, $column);

                if ($cells[$index]->mine) {
                    continue;
                }

                $adjacent = 0;

                foreach ($board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
                    if ($cells[$board->index($neighbourRow, $neighbourColumn)]->mine) {
                        $adjacent++;
                    }
                }

                $cells[$index] = $cells[$index]->withAdjacent($adjacent);
            }
        }

        return $board->withCells($cells, minesPlaced: true);
    }

    /**
     * Algoritmo de desborde (flood fill) iterativo.
     *
     * 1. Revela la celda pulsada.
     * 2. Si tiene 0 minas adyacentes, "desborda" hacia sus 8 vecinas.
     * 3. Cada vecina segura se revela y, si también tiene 0 adyacentes,
     *    continúa el desborde. Las celdas con número frenan la expansión.
     *
     * Es iterativo (pila explícita) para no arriesgar la pila de llamadas en
     * el nivel Experto. Las banderas bloquean la expansión automática.
     */
    private function cascade(Board $board, int $row, int $column): Board
    {
        /** @var list<Cell> $cells */
        $cells = $board->cells;

        /** @var list<array{int, int}> $stack */
        $stack = [[$row, $column]];

        while ($stack !== []) {
            [$currentRow, $currentColumn] = array_pop($stack);
            $index = $board->index($currentRow, $currentColumn);
            $cell = $cells[$index];

            if ($cell->revealed || $cell->flagged || $cell->mine) {
                continue;
            }

            $cells[$index] = $cell->revealed();

            if ($cells[$index]->adjacent !== 0) {
                continue;
            }

            foreach ($board->neighbours($currentRow, $currentColumn) as [$neighbourRow, $neighbourColumn]) {
                $neighbourIndex = $board->index($neighbourRow, $neighbourColumn);

                if (! $cells[$neighbourIndex]->revealed && ! $cells[$neighbourIndex]->flagged) {
                    $stack[] = [$neighbourRow, $neighbourColumn];
                }
            }
        }

        return $board->withCells($cells);
    }

    /**
     * La partida se gana cuando se han revelado todas las celdas seguras.
     */
    private function settle(GameState $game): GameState
    {
        if ($game->board->revealedSafeCells() < $game->board->safeCellsTotal()) {
            return $game;
        }

        // Al ganar, se marcan automáticamente todas las minas restantes.
        /** @var list<Cell> $cells */
        $cells = $game->board->cells;

        foreach ($cells as $index => $cell) {
            if ($cell->mine && ! $cell->flagged) {
                $cells[$index] = $cell->flagged();
            }
        }

        return $game
            ->withBoard($game->board->withCells($cells))
            ->finished(GameStatus::Won);
    }

    /**
     * Derrota: se destapa el tablero completo y se marca la mina detonada.
     */
    private function detonate(GameState $game, int $row, int $column): GameState
    {
        /** @var list<Cell> $cells */
        $cells = $game->board->cells;

        foreach ($cells as $index => $cell) {
            if ($cell->mine) {
                $cells[$index] = $cell->revealed();
            }
        }

        $detonatedIndex = $game->board->index($row, $column);
        $cells[$detonatedIndex] = $cells[$detonatedIndex]->exploded();

        return $game
            ->withBoard($game->board->withCells($cells))
            ->finished(GameStatus::Lost);
    }

    /**
     * Fisher-Yates con `random_int()`: aleatoriedad criptográficamente segura,
     * a diferencia de `shuffle()` (basado en Mersenne Twister).
     *
     * @param  list<int>  $items
     * @return list<int>
     */
    private static function shuffle(array $items): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);

            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return $items;
    }
}
