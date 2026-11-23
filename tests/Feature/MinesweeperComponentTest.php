<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\ValueObjects\GameState;
use App\Livewire\Minesweeper;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MinesweeperComponentTest extends TestCase
{
    #[Test]
    public function it_renders_a_fresh_beginner_game(): void
    {
        Livewire::test(Minesweeper::class)
            ->assertSet('status', 'pending')
            ->assertSet('difficulty', 'beginner')
            ->assertSet('rows', 8)
            ->assertSet('columns', 8)
            ->assertSet('mines', 10)
            ->assertSet('flags', 10)
            ->assertSet('elapsed', 0)
            ->assertSet('startedAtMs', 0)
            ->assertSee('Principiante')
            ->assertSee('BUSCA', escape: false);
    }

    #[Test]
    public function the_first_reveal_starts_the_game_without_leaking_mines(): void
    {
        $component = Livewire::test(Minesweeper::class)
            ->call('reveal', 4, 4)
            ->assertSet('status', 'playing');

        $this->assertGreaterThan(0, $component->get('startedAtMs'));
        $this->assertSame(0, $component->get('finishedAtMs'));

        /** @var list<array{row: int, col: int, revealed: bool, mine: bool, adjacent: int}> $cells */
        $cells = $component->get('cells');

        $this->assertCount(64, $cells);

        foreach ($cells as $cell) {
            // Mientras se juega, el payload NUNCA contiene minas.
            $this->assertFalse($cell['mine']);

            if ($cell['revealed']) {
                $this->assertGreaterThanOrEqual(0, $cell['adjacent']);
            }
        }
    }

    #[Test]
    public function flags_update_the_remaining_mine_counter(): void
    {
        Livewire::test(Minesweeper::class)
            ->call('toggleFlag', 0, 0)
            ->assertSet('flags', 9)
            ->call('toggleFlag', 0, 0)
            ->assertSet('flags', 10);
    }

    #[Test]
    public function changing_difficulty_resets_the_board(): void
    {
        $component = Livewire::test(Minesweeper::class)
            ->call('reveal', 0, 0)
            ->assertSet('status', 'playing')
            ->call('changeDifficulty', 'expert')
            ->assertSet('difficulty', 'expert')
            ->assertSet('status', 'pending')
            ->assertSet('rows', 16)
            ->assertSet('columns', 30)
            ->assertSet('mines', 99)
            ->assertSet('flags', 99);

        $this->assertCount(480, $component->get('cells'));
    }

    #[Test]
    public function invalid_difficulties_are_ignored(): void
    {
        Livewire::test(Minesweeper::class)
            ->call('changeDifficulty', 'imposible')
            ->assertSet('difficulty', 'beginner')
            ->assertSet('status', 'pending');
    }

    #[Test]
    public function out_of_bounds_moves_do_not_break_the_game(): void
    {
        Livewire::test(Minesweeper::class)
            ->call('reveal', -5, 2)
            ->call('reveal', 2, 999)
            ->call('toggleFlag', 100, 100)
            ->assertSet('status', 'pending')
            ->assertSet('elapsed', 0)
            ->assertCount('cells', 64);
    }

    #[Test]
    public function the_component_emits_the_timer_sync_event(): void
    {
        Livewire::test(Minesweeper::class)
            ->call('reveal', 3, 3)
            ->assertDispatched('minesweeper-sync');
    }

    #[Test]
    public function winning_shows_the_victory_banner(): void
    {
        $component = Livewire::test(Minesweeper::class)->call('reveal', 0, 0);

        $game = $this->serverGame($component->get('gameId'));

        for ($row = 0; $row < $game->difficulty->rows(); $row++) {
            for ($column = 0; $column < $game->difficulty->columns(); $column++) {
                if (! $game->board->cell($row, $column)->mine) {
                    $component->call('reveal', $row, $column);
                }
            }
        }

        $component
            ->assertSet('status', 'won')
            ->assertSet('flags', 0)
            ->assertSee('Tablero despejado');
    }

    #[Test]
    public function losing_reveals_the_mines_and_the_defeat_banner(): void
    {
        $component = Livewire::test(Minesweeper::class)->call('reveal', 0, 0);

        $game = $this->serverGame($component->get('gameId'));
        $mine = null;

        for ($row = 0; $row < 8 && $mine === null; $row++) {
            for ($column = 0; $column < 8; $column++) {
                if ($game->board->cell($row, $column)->mine) {
                    $mine = [$row, $column];
                    break;
                }
            }
        }

        $this->assertNotNull($mine);

        $component
            ->call('reveal', $mine[0], $mine[1])
            ->assertSet('status', 'lost')
            ->assertSee('Pisaste una mina');

        $visibleMines = array_filter(
            $component->get('cells'),
            static fn (array $cell): bool => $cell['mine'],
        );

        $this->assertCount(10, $visibleMines);
    }

    /**
     * La mina solo se conoce en el servidor; el test la lee de la sesión.
     */
    private function serverGame(string $gameId): GameState
    {
        return GameState::fromArray(
            (array) session('minesweeper.game.'.$gameId),
        );
    }
}
