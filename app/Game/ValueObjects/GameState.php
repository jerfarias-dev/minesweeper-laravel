<?php

declare(strict_types=1);

namespace App\Game\ValueObjects;

use App\Game\Enums\Difficulty;
use App\Game\Enums\GameStatus;

/**
 * Estado completo e inmutable de una partida.
 *
 * Es el único objeto que se persiste en sesión. El navegador nunca recibe
 * las posiciones de las minas: el componente Livewire sanea el tablero antes
 * de enviarlo al frontend.
 */
final readonly class GameState
{
    public function __construct(
        public Difficulty $difficulty,
        public Board $board,
        public GameStatus $status = GameStatus::Pending,
        public ?float $startedAt = null,
        public ?float $finishedAt = null,
    ) {}

    public static function pending(Difficulty $difficulty): self
    {
        return new self($difficulty, Board::blank($difficulty));
    }

    #[\NoDiscard]
    public function started(?float $now = null): self
    {
        return new self(
            $this->difficulty,
            $this->board,
            GameStatus::Playing,
            $this->startedAt ?? ($now ?? microtime(true)),
            null,
        );
    }

    #[\NoDiscard]
    public function withBoard(Board $board): self
    {
        return new self(
            $this->difficulty,
            $board,
            $this->status,
            $this->startedAt,
            $this->finishedAt,
        );
    }

    #[\NoDiscard]
    public function withStatus(GameStatus $status): self
    {
        return new self(
            $this->difficulty,
            $this->board,
            $status,
            $this->startedAt,
            $this->finishedAt,
        );
    }

    #[\NoDiscard]
    public function finished(GameStatus $status, ?float $now = null): self
    {
        return new self(
            $this->difficulty,
            $this->board,
            $status,
            $this->startedAt,
            $this->finishedAt ?? ($now ?? microtime(true)),
        );
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * Segundos transcurridos. Se congela al terminar la partida.
     */
    public function elapsedSeconds(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        $end = $this->finishedAt ?? microtime(true);

        return max(0, (int) floor($end - $this->startedAt));
    }

    public function startedAtMs(): int
    {
        return $this->startedAt === null ? 0 : (int) round($this->startedAt * 1000);
    }

    public function finishedAtMs(): int
    {
        return $this->finishedAt === null ? 0 : (int) round($this->finishedAt * 1000);
    }

    public function flagsUsed(): int
    {
        return $this->board->flagsPlaced();
    }

    public function minesRemaining(): int
    {
        return max(0, $this->difficulty->mines() - $this->flagsUsed());
    }

    /**
     * Progreso 0..100 sobre las celdas seguras.
     */
    public function progress(): int
    {
        $safeTotal = $this->board->safeCellsTotal();

        if ($safeTotal === 0) {
            return 0;
        }

        return (int) floor(($this->board->revealedSafeCells() / $safeTotal) * 100);
    }

    /**
     * @return array{
     *     difficulty: string,
     *     status: string,
     *     started_at: float|null,
     *     finished_at: float|null,
     *     board: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'difficulty' => $this->difficulty->value,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'board' => $this->board->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $difficulty = Difficulty::tryFrom((string) ($data['difficulty'] ?? ''))
            ?? Difficulty::Beginner;

        return new self(
            $difficulty,
            Board::fromArray($difficulty, (array) ($data['board'] ?? [])),
            GameStatus::tryFrom((string) ($data['status'] ?? '')) ?? GameStatus::Pending,
            self::nullableFloat($data['started_at'] ?? null),
            self::nullableFloat($data['finished_at'] ?? null),
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
