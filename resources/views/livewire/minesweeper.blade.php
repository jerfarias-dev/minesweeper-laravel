@php
    $face = match ($status) {
        'won' => '😎',
        'lost' => '💀',
        default => '🙂',
    };

    $finished = in_array($status, ['won', 'lost'], true);
@endphp

<div class="min-h-screen px-3 py-8 sm:py-12">
    <div
        class="mx-auto w-full max-w-5xl"
        x-data="minesweeperBoard({
            startedAt: @js($startedAtMs),
            finishedAt: @js($finishedAtMs),
        })"
        x-on:minesweeper-sync.window="sync($event.detail)"
    >
        {{-- Cabecera --}}
        <header class="mb-6 text-center">
            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.45em] text-cyan-400/80">
                Laravel 13 · Livewire 4 · PHP 8.5
            </p>

            <h1 class="mt-2 text-4xl font-black tracking-tight text-white sm:text-5xl">
                BUSCA<span class="text-cyan-400">MINAS</span>
            </h1>

            <p class="mt-2 text-sm text-slate-400">
                {{ $statusLabel }}
                <span class="mx-1 text-slate-600">·</span>
                Nivel {{ $difficultyLabel }}
                <span class="mx-1 text-slate-600">·</span>
                {{ $mines }} minas
            </p>
        </header>

        {{-- Panel del juego --}}
        <section class="game-panel rounded-2xl p-3 sm:p-5">
            <div class="grid grid-cols-3 items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-lg" aria-hidden="true">🚩</span>
                    <span class="hud-led" aria-label="Minas restantes">
                        {{ str_pad((string) $flags, 3, '0', STR_PAD_LEFT) }}
                    </span>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        wire:click="newGame"
                        class="smiley-button"
                        title="Nueva partida"
                        aria-label="Nueva partida"
                    >
                        {{ $face }}
                    </button>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <span class="text-lg" aria-hidden="true">⏱️</span>
                    <span class="hud-led" aria-label="Tiempo transcurrido" x-text="clock">
                        {{ str_pad((string) $elapsed, 3, '0', STR_PAD_LEFT) }}
                    </span>
                </div>
            </div>

            {{-- Niveles --}}
            <div class="mt-4 flex flex-wrap items-center justify-center gap-1 rounded-xl bg-black/30 p-1">
                @foreach ($difficulties as $level)
                    <button
                        type="button"
                        wire:click="changeDifficulty('{{ $level->value }}')"
                        @class([
                            'difficulty-tab',
                            'difficulty-tab--active' => $difficulty === $level->value,
                        ])
                        title="{{ $level->rows() }}x{{ $level->columns() }} · {{ $level->mines() }} minas"
                    >
                        {{ $level->label() }}
                        <span class="ml-1 opacity-60">{{ $level->rows() }}×{{ $level->columns() }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Modo de interacción --}}
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/5 bg-black/20 px-2 py-2">
                <p class="px-1 text-[0.7rem] leading-tight text-slate-400">
                    <span class="hidden sm:inline" x-show="! flagMode">
                        <span class="font-semibold text-slate-200">Clic derecho</span> o selector para poner bandera
                    </span>
                    <span class="hidden sm:inline" x-show="flagMode">
                        <span class="font-semibold text-amber-200">Modo bandera:</span> el clic izquierdo pone banderas
                    </span>
                    <span class="sm:hidden" x-show="! flagMode">
                        Toca para descubrir · <span class="font-semibold text-amber-200">mantén pulsado</span> para bandera
                    </span>
                    <span class="sm:hidden" x-show="flagMode">
                        Toca para poner bandera · cambia a
                        <span class="font-semibold text-cyan-200">Descubrir</span> para revelar
                    </span>
                </p>

                <div class="flex items-center gap-1 rounded-lg bg-black/40 p-1" role="group" aria-label="Acción del clic">
                    <button
                        type="button"
                        x-on:click="flagMode = false"
                        x-bind:class="! flagMode
                            ? 'bg-cyan-400/20 text-cyan-200 ring-1 ring-cyan-400/40'
                            : 'text-slate-400 hover:text-slate-200'"
                        x-bind:aria-pressed="! flagMode"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                    >
                        ⛏️ Descubrir
                    </button>
                    <button
                        type="button"
                        x-on:click="flagMode = true"
                        x-bind:class="flagMode
                            ? 'bg-amber-400/20 text-amber-200 ring-1 ring-amber-400/40'
                            : 'text-slate-400 hover:text-slate-200'"
                        x-bind:aria-pressed="flagMode"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                    >
                        🚩 Bandera
                    </button>
                </div>
            </div>

            {{-- Barra de progreso --}}
            <div class="mt-3 h-1 overflow-hidden rounded-full bg-white/5" role="progressbar" aria-label="Progreso de la partida" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-cyan-400 to-emerald-400 transition-[width] duration-300"
                    style="width: {{ $progress }}%"
                ></div>
            </div>

            {{-- Tablero --}}
            <div class="mt-3 overflow-x-auto pb-1">
                <div
                    class="mine-board"
                    x-bind:class="flagMode ? 'mine-board--flag' : ''"
                    data-level="{{ $difficulty }}"
                    style="--cols: {{ $columns }}"
                    role="group"
                    aria-label="Tablero de buscaminas"
                    wire:loading.class="opacity-60"
                >
                    @foreach ($cells as $cell)
                        <button
                            type="button"
                            wire:key="cell-{{ $cell['row'] }}-{{ $cell['col'] }}"
                            @class([
                                'mine-cell',
                                'mine-cell--hidden' => ! $cell['revealed'] && ! $cell['flagged'],
                                'mine-cell--revealed' => $cell['revealed'],
                                'mine-cell--flagged' => $cell['flagged'] && ! $cell['revealed'],
                                'mine-cell--mine' => $cell['mine'] && ! $cell['exploded'],
                                'mine-cell--exploded' => $cell['exploded'],
                                'mine-cell--wrong' => $cell['wrongFlag'],
                            ])
                            data-n="{{ $cell['revealed'] ? $cell['adjacent'] : '' }}"
                            x-on:pointerdown="startPress($event, $wire, {{ $cell['row'] }}, {{ $cell['col'] }})"
                            x-on:pointerup="cancelPress()"
                            x-on:pointerleave="cancelPress()"
                            x-on:pointercancel="cancelPress()"
                            x-on:click="handleClick($wire, {{ $cell['row'] }}, {{ $cell['col'] }})"
                            x-on:contextmenu.prevent="handleContextMenu($wire, {{ $cell['row'] }}, {{ $cell['col'] }})"
                            aria-label="Fila {{ $cell['row'] + 1 }}, columna {{ $cell['col'] + 1 }}"
                        >
                            @if ($cell['flagged'])
                                {{ $cell['wrongFlag'] ? '❌' : '🚩' }}
                            @elseif ($cell['exploded'])
                                💥
                            @elseif ($cell['mine'])
                                💣
                            @elseif ($cell['revealed'] && $cell['adjacent'] > 0)
                                {{ $cell['adjacent'] }}
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Resultado --}}
            @if ($finished)
                <div
                    @class([
                        'mt-4 flex flex-col items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm sm:flex-row',
                        'border-emerald-400/30 bg-emerald-400/10 text-emerald-200' => $status === 'won',
                        'border-rose-400/30 bg-rose-400/10 text-rose-200' => $status === 'lost',
                    ])
                >
                    <p class="font-semibold">
                        @if ($status === 'won')
                            🏆 ¡Tablero despejado en {{ $elapsed }} s!
                        @else
                            💥 Pisaste una mina. Otra vez será.
                        @endif
                    </p>

                    <button
                        type="button"
                        wire:click="newGame"
                        class="rounded-lg bg-white/10 px-3 py-1.5 font-semibold text-white transition hover:bg-white/20"
                    >
                        Jugar otra vez
                    </button>
                </div>
            @endif
        </section>

        {{-- Ayuda --}}
        <footer class="mt-4 text-center text-xs text-slate-500 sm:text-left">
            <p>
                <span class="text-slate-300">Clic izquierdo</span>
                <span x-text="flagMode ? 'pone bandera' : 'descubre'">descubre</span> ·
                <span class="text-slate-300">Clic derecho</span> pone bandera ·
                <span class="text-slate-300">Mantén pulsado</span> en móvil
            </p>
        </footer>
    </div>
</div>
