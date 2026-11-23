<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Enums\Difficulty;
use App\Game\Enums\GameStatus;
use App\Game\Services\MinesweeperService;
use App\Game\ValueObjects\Board;
use App\Game\ValueObjects\Cell;
use App\Game\ValueObjects\GameState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MinesweeperServiceTest extends TestCase
{
    private MinesweeperService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MinesweeperService;
    }

    #[Test]
    public function a_new_game_is_pending_and_has_no_mines(): void
    {
        $game = $this->service->newGame(Difficulty::Beginner);

        $this->assertSame(GameStatus::Pending, $game->status);
        $this->assertFalse($game->board->minesPlaced);
        $this->assertSame(0, $game->board->minesPlacedCount());
        $this->assertNull($game->startedAt);
        $this->assertSame(0, $game->elapsedSeconds());
        $this->assertSame(10, $game->minesRemaining());
        $this->assertCount(64, $game->board->cells);
    }

    /**
     * @return array<string, array{Difficulty}>
     */
    public static function difficulties(): array
    {
        return [
            'principiante' => [Difficulty::Beginner],
            'intermedio' => [Difficulty::Intermediate],
            'experto' => [Difficulty::Expert],
        ];
    }

    #[Test]
    #[DataProvider('difficulties')]
    public function the_first_click_is_always_safe_and_places_every_mine(Difficulty $difficulty): void
    {
        $row = intdiv($difficulty->rows(), 2);
        $column = intdiv($difficulty->columns(), 2);

        $game = $this->service->reveal(
            $this->service->newGame($difficulty),
            $row,
            $column,
        );

        $this->assertSame(GameStatus::Playing, $game->status);
        $this->assertTrue($game->board->minesPlaced);
        $this->assertSame($difficulty->mines(), $game->board->minesPlacedCount());
        $this->assertNotNull($game->startedAt);

        // La celda pulsada y sus 8 vecinas están libres de minas.
        $this->assertFalse($game->board->cell($row, $column)->mine);

        foreach ($game->board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
            $this->assertFalse($game->board->cell($neighbourRow, $neighbourColumn)->mine);
        }

        // La cascada abre al menos el área 3x3 (o lo que quepa en el borde).
        $this->assertGreaterThanOrEqual(9, $game->board->revealedSafeCells());

        // Jamás se revela una mina en el primer clic.
        foreach ($game->board->cells as $cell) {
            $this->assertFalse($cell->revealed && $cell->mine);
        }
    }

    #[Test]
    public function adjacency_counters_match_the_real_neighbourhood(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Intermediate),
            8,
            8,
        );

        $board = $game->board;

        for ($row = 0; $row < $board->rows(); $row++) {
            for ($column = 0; $column < $board->columns(); $column++) {
                $cell = $board->cell($row, $column);

                if ($cell->mine) {
                    continue;
                }

                $expected = 0;

                foreach ($board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
                    if ($board->cell($neighbourRow, $neighbourColumn)->mine) {
                        $expected++;
                    }
                }

                $this->assertSame(
                    $expected,
                    $cell->adjacent,
                    "Contador incorrecto en ({$row}, {$column}).",
                );
            }
        }
    }

    #[Test]
    public function the_cascade_always_expands_every_adjacent_cell_of_an_empty_cell(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Expert),
            7,
            15,
        );

        $board = $game->board;

        for ($row = 0; $row < $board->rows(); $row++) {
            for ($column = 0; $column < $board->columns(); $column++) {
                $cell = $board->cell($row, $column);

                if (! $cell->revealed || ! $cell->isEmpty) {
                    continue;
                }

                foreach ($board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
                    $neighbour = $board->cell($neighbourRow, $neighbourColumn);

                    $this->assertTrue(
                        $neighbour->revealed || $neighbour->mine,
                        "La cascada dejó una celda segura sin abrir en ({$neighbourRow}, {$neighbourColumn}).",
                    );
                }
            }
        }
    }

    /**
     * Escenario determinista: 10 minas pegadas al borde inferior/derecho.
     * El desborde desde (0,0) debe revelar exactamente la región conectada
     * de celdas seguras, ni una mina más, ni una celda segura de menos.
     */
    #[Test]
    public function el_desborde_revela_exactamente_la_region_conectada_sin_minas(): void
    {
        $difficulty = Difficulty::Beginner;
        $columns = $difficulty->columns();

        $minePositions = [
            [7, 0], [7, 1], [7, 2], [7, 3], [7, 4],
            [7, 5], [7, 6], [7, 7], [6, 7], [5, 7],
        ];

        /** @var list<Cell> $cells */
        $cells = array_map(
            static fn (): Cell => new Cell,
            range(1, $difficulty->size()),
        );

        foreach ($minePositions as [$row, $column]) {
            $index = $row * $columns + $column;
            $cells[$index] = $cells[$index]->withMine();
        }

        $board = Board::fromCells($difficulty, $cells, true);

        for ($row = 0; $row < $difficulty->rows(); $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $index = $row * $columns + $column;

                if ($board->cell($row, $column)->mine) {
                    continue;
                }

                $adjacent = 0;

                foreach ($board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
                    if ($board->cell($neighbourRow, $neighbourColumn)->mine) {
                        $adjacent++;
                    }
                }

                $cells[$index] = $cells[$index]->withAdjacent($adjacent);
            }
        }

        $board = Board::fromCells($difficulty, $cells, true);
        $game = new GameState($difficulty, $board, GameStatus::Playing, microtime(true));

        $result = $this->service->reveal($game, 0, 0);

        // Referencia independiente: BFS de desborde esperado desde (0,0).
        $expected = [];
        $queue = [[0, 0]];
        $pointer = 0;

        while ($pointer < count($queue)) {
            [$row, $column] = $queue[$pointer++];
            $index = $row * $columns + $column;

            if (isset($expected[$index])) {
                continue;
            }

            $cell = $board->cell($row, $column);

            if ($cell->mine) {
                continue;
            }

            $expected[$index] = true;

            if ($cell->adjacent !== 0) {
                continue;
            }

            foreach ($board->neighbours($row, $column) as [$neighbourRow, $neighbourColumn]) {
                if (! $board->cell($neighbourRow, $neighbourColumn)->mine) {
                    $queue[] = [$neighbourRow, $neighbourColumn];
                }
            }
        }

        $revealed = [];

        for ($row = 0; $row < $difficulty->rows(); $row++) {
            for ($column = 0; $column < $columns; $column++) {
                if ($result->board->cell($row, $column)->revealed) {
                    $revealed[$row * $columns + $column] = true;
                }
            }
        }

        $expectedIndexes = array_keys($expected);
        $revealedIndexes = array_keys($revealed);
        sort($expectedIndexes);
        sort($revealedIndexes);

        $this->assertSame($expectedIndexes, $revealedIndexes);
        $this->assertCount($result->board->safeCellsTotal(), $revealed);

        foreach ($minePositions as [$row, $column]) {
            $this->assertFalse($result->board->cell($row, $column)->revealed);
        }
    }

    #[Test]
    public function flags_can_be_toggled_and_are_capped_at_the_mine_count(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Beginner),
            0,
            0,
        );

        $target = null;

        for ($row = 0; $row < 8 && $target === null; $row++) {
            for ($column = 0; $column < 8; $column++) {
                if (! $game->board->cell($row, $column)->revealed) {
                    $target = [$row, $column];
                    break;
                }
            }
        }

        $this->assertNotNull($target);
        [$flagRow, $flagColumn] = $target;

        $game = $this->service->toggleFlag($game, $flagRow, $flagColumn);
        $this->assertTrue($game->board->cell($flagRow, $flagColumn)->flagged);
        $this->assertSame(9, $game->minesRemaining());

        $game = $this->service->toggleFlag($game, $flagRow, $flagColumn);
        $this->assertFalse($game->board->cell($flagRow, $flagColumn)->flagged);
        $this->assertSame(10, $game->minesRemaining());

        // Intenta poner más banderas que minas: el exceso se ignora.
        for ($row = 0; $row < 8 && $game->flagsUsed() < 10; $row++) {
            for ($column = 0; $column < 8 && $game->flagsUsed() < 10; $column++) {
                if (! $game->board->cell($row, $column)->revealed) {
                    $game = $this->service->toggleFlag($game, $row, $column);
                }
            }
        }

        $this->assertSame(10, $game->flagsUsed());

        // La bandera 11 nunca se coloca.
        for ($row = 0; $row < 8; $row++) {
            for ($column = 0; $column < 8; $column++) {
                $cell = $game->board->cell($row, $column);

                if (! $cell->revealed && ! $cell->flagged) {
                    $game = $this->service->toggleFlag($game, $row, $column);
                }
            }
        }

        $this->assertSame(10, $game->flagsUsed());
    }

    #[Test]
    public function a_flagged_cell_cannot_be_revealed(): void
    {
        $game = $this->service->newGame(Difficulty::Beginner);
        $game = $this->service->toggleFlag($game, 2, 2);
        $game = $this->service->reveal($game, 2, 2);

        $this->assertFalse($game->board->cell(2, 2)->revealed);
        $this->assertSame(GameStatus::Pending, $game->status);
    }

    #[Test]
    public function out_of_bounds_actions_are_ignored(): void
    {
        $game = $this->service->newGame(Difficulty::Beginner);
        $snapshot = $game->toArray();

        $this->assertSame($snapshot, $this->service->reveal($game, -1, 0)->toArray());
        $this->assertSame($snapshot, $this->service->reveal($game, 0, 8)->toArray());
        $this->assertSame($snapshot, $this->service->toggleFlag($game, 99, 99)->toArray());
    }

    #[Test]
    public function revealing_every_safe_cell_wins_the_game(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Beginner),
            0,
            0,
        );

        for ($row = 0; $row < 8; $row++) {
            for ($column = 0; $column < 8; $column++) {
                if (! $game->board->cell($row, $column)->mine) {
                    $game = $this->service->reveal($game, $row, $column);
                }
            }
        }

        $this->assertSame(GameStatus::Won, $game->status);
        $this->assertTrue($game->isFinished());
        $this->assertNotNull($game->finishedAt);
        $this->assertSame(100, $game->progress());
        $this->assertSame(10, $game->flagsUsed());

        foreach ($game->board->cells as $cell) {
            if ($cell->mine) {
                $this->assertTrue($cell->flagged);
            }
        }

        // Una partida terminada no admite más movimientos.
        $snapshot = $game->toArray();
        $this->assertSame($snapshot, $this->service->reveal($game, 1, 1)->toArray());
        $this->assertSame($snapshot, $this->service->toggleFlag($game, 1, 1)->toArray());
    }

    #[Test]
    public function revealing_a_mine_loses_and_reveals_all_mines(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Beginner),
            4,
            4,
        );

        $mine = null;

        foreach ($game->board->cells as $index => $cell) {
            if ($cell->mine) {
                $mine = $index;
                break;
            }
        }

        $this->assertNotNull($mine);

        $row = intdiv($mine, 8);
        $column = $mine % 8;
        $lost = $this->service->reveal($game, $row, $column);

        $this->assertSame(GameStatus::Lost, $lost->status);
        $this->assertNotNull($lost->finishedAt);
        $this->assertTrue($lost->board->cell($row, $column)->exploded);

        foreach ($lost->board->cells as $cell) {
            if ($cell->mine) {
                $this->assertTrue($cell->revealed);
            }
        }

        // El estado terminado es inmutable ante nuevas acciones.
        $snapshot = $lost->toArray();
        $this->assertSame($snapshot, $this->service->reveal($lost, 0, 0)->toArray());
    }

    #[Test]
    public function the_state_survives_a_serialization_round_trip(): void
    {
        $game = $this->service->reveal(
            $this->service->newGame(Difficulty::Expert),
            5,
            7,
        );

        $restored = GameState::fromArray(
            json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame($game->status, $restored->status);
        $this->assertSame($game->difficulty, $restored->difficulty);
        $this->assertSame($game->board->toArray(), $restored->board->toArray());
        $this->assertSame($game->startedAtMs(), $restored->startedAtMs());
    }

    #[Test]
    public function cells_are_immutable(): void
    {
        $cell = $this->service->newGame(Difficulty::Beginner)->board->cell(0, 0);

        $revealed = $cell->revealed();
        $flagged = $cell->flagged();

        $this->assertFalse($cell->revealed);
        $this->assertFalse($cell->flagged);
        $this->assertTrue($revealed->revealed);
        $this->assertTrue($flagged->flagged);
        $this->assertNotSame($cell, $revealed);
        $this->assertTrue($revealed->isHidden === false);
    }
}
