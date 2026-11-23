<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Game\Enums\Difficulty;
use App\Game\Enums\GameStatus;
use App\Game\Services\MinesweeperService;
use App\Game\ValueObjects\GameState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Capa de aplicación del Buscaminas.
 *
 * Seguridad:
 *  - El tablero real (con las minas) vive en la SESIÓN del servidor.
 *  - El cliente solo recibe celdas saneadas: mientras la partida está en
 *    curso, `mine` siempre es false. Inspeccionar el DOM no revela nada.
 *  - Toda acción se revalida en el servidor (límites, estado, banderas).
 *  - Las propiedades derivadas están marcadas #[Locked]: el navegador no
 *    puede alterarlas y el siguiente render las recalcula desde la sesión.
 */
final class Minesweeper extends Component
{
    #[Locked]
    public string $gameId = '';

    /**
     * Representación saneada del tablero para la vista.
     *
     * @var list<array{row: int, col: int, revealed: bool, flagged: bool, mine: bool, adjacent: int, exploded: bool, wrongFlag: bool}>
     */
    #[Locked]
    public array $cells = [];

    #[Locked]
    public string $difficulty = 'beginner';

    #[Locked]
    public string $status = 'pending';

    #[Locked]
    public int $rows = 8;

    #[Locked]
    public int $columns = 8;

    #[Locked]
    public int $mines = 10;

    /**
     * Minas restantes según las banderas colocadas.
     */
    #[Locked]
    public int $flags = 10;

    #[Locked]
    public int $elapsed = 0;

    #[Locked]
    public int $startedAtMs = 0;

    #[Locked]
    public int $finishedAtMs = 0;

    #[Locked]
    public int $progress = 0;

    public function mount(): void
    {
        $this->gameId = (string) Str::uuid();
        $this->start(Difficulty::Beginner);
    }

    public function reveal(int $row, int $column): void
    {
        $this->persist(
            $this->service()->reveal($this->game(), $row, $column),
        );
    }

    public function toggleFlag(int $row, int $column): void
    {
        $this->persist(
            $this->service()->toggleFlag($this->game(), $row, $column),
        );
    }

    public function newGame(): void
    {
        $this->start(Difficulty::from($this->difficulty));
    }

    public function changeDifficulty(string $difficulty): void
    {
        $level = Difficulty::tryFrom($difficulty);

        if ($level === null) {
            return;
        }

        $this->start($level);
    }

    public function render(): View
    {
        return view('livewire.minesweeper', [
            'difficulties' => Difficulty::cases(),
            'difficultyLabel' => Difficulty::from($this->difficulty)->label(),
            'statusLabel' => GameStatus::from($this->status)->label(),
        ]);
    }

    private function start(Difficulty $difficulty): void
    {
        // Libera el tablero anterior antes de crear otro: la sesión no crece
        // con cada revancha.
        if ($this->gameId !== '') {
            session()->forget($this->sessionKey());
        }

        // Un id nuevo por partida aísla el estado en sesión (varias pestañas,
        // revanchas y cambios de nivel no comparten tablero).
        $this->gameId = (string) Str::uuid();

        $this->persist($this->service()->newGame($difficulty));
    }

    private function game(): GameState
    {
        $payload = session()->get($this->sessionKey());

        if (is_array($payload)) {
            return GameState::fromArray($payload);
        }

        // Fallback defensivo: sesión caducada o manipulada.
        return $this->service()->newGame(Difficulty::from($this->difficulty));
    }

    private function persist(GameState $game): void
    {
        session()->put($this->sessionKey(), $game->toArray());

        $this->syncView($game);

        $this->dispatch(
            'minesweeper-sync',
            startedAt: $game->startedAtMs(),
            finishedAt: $game->finishedAtMs(),
            status: $game->status->value,
            elapsed: $game->elapsedSeconds(),
        );
    }

    /**
     * Convierte el estado real en datos seguros para el navegador.
     */
    private function syncView(GameState $game): void
    {
        $lost = $game->status === GameStatus::Lost;
        $cells = [];

        for ($row = 0; $row < $game->difficulty->rows(); $row++) {
            for ($column = 0; $column < $game->difficulty->columns(); $column++) {
                $cell = $game->board->cell($row, $column);

                $cells[] = [
                    'row' => $row,
                    'col' => $column,
                    'revealed' => $cell->revealed,
                    'flagged' => $cell->flagged,
                    // Solo se revelan las minas cuando la partida ha terminado.
                    'mine' => $lost && $cell->mine,
                    'adjacent' => $cell->revealed ? $cell->adjacent : 0,
                    'exploded' => $cell->exploded,
                    'wrongFlag' => $lost && $cell->flagged && ! $cell->mine,
                ];
            }
        }

        $this->cells = $cells;
        $this->difficulty = $game->difficulty->value;
        $this->status = $game->status->value;
        $this->rows = $game->difficulty->rows();
        $this->columns = $game->difficulty->columns();
        $this->mines = $game->difficulty->mines();
        $this->flags = $game->minesRemaining();
        $this->elapsed = $game->elapsedSeconds();
        $this->startedAtMs = $game->startedAtMs();
        $this->finishedAtMs = $game->finishedAtMs();
        $this->progress = $game->progress();
    }

    private function sessionKey(): string
    {
        return 'minesweeper.game.'.$this->gameId;
    }

    private function service(): MinesweeperService
    {
        return app(MinesweeperService::class);
    }
}
