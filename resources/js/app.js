/**
 * Reloj del Buscaminas.
 *
 * Livewire ya incluye y arranca Alpine, por lo que NO importamos otra copia:
 * registramos el componente sobre la instancia global dentro de `alpine:init`.
 *
 * El servidor es la única fuente de verdad para el tiempo: envía el
 * `startedAt`/`finishedAt` en milisegundos y Alpine interpola entre
 * respuestas. Al terminar la partida, el reloj se congela con el valor
 * oficial calculado en PHP.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('minesweeperBoard', ({ startedAt = 0, finishedAt = 0 } = {}) => ({
        startedAt,
        finishedAt,
        now: Date.now(),
        flagMode: false,
        ticker: null,
        pressTimer: null,
        lastLongPressAt: 0,

        init() {
            this.ticker = setInterval(() => {
                if (this.finishedAt === 0) {
                    this.now = Date.now();
                }
            }, 250);
        },

        destroy() {
            clearInterval(this.ticker);
            this.cancelPress();
        },

        /**
         * Clic normal: descubre o pone bandera según el selector de modo.
         */
        handleClick(wire, row, column) {
            if (Date.now() - this.lastLongPressAt < 800) {
                return;
            }

            if (this.flagMode) {
                wire.toggleFlag(row, column);
            } else {
                wire.reveal(row, column);
            }
        },

        /**
         * Clic derecho: siempre alterna bandera.
         */
        handleContextMenu(wire, row, column) {
            if (Date.now() - this.lastLongPressAt < 800) {
                return;
            }

            wire.toggleFlag(row, column);
        },

        /**
         * Pulsación larga en táctil/lápiz: bandera sin cambiar de modo.
         * En ratón se ignora para no interferir con el clic normal.
         */
        startPress(event, wire, row, column) {
            if (this.flagMode || event.pointerType === 'mouse') {
                return;
            }

            this.cancelPress();

            this.pressTimer = setTimeout(() => {
                this.pressTimer = null;
                this.lastLongPressAt = Date.now();
                wire.toggleFlag(row, column);
            }, 450);
        },

        cancelPress() {
            if (this.pressTimer) {
                clearTimeout(this.pressTimer);
                this.pressTimer = null;
            }
        },

        sync(detail = {}) {
            const state = Array.isArray(detail) ? (detail[0] ?? {}) : detail;

            this.startedAt = state.startedAt ?? this.startedAt;
            this.finishedAt = state.finishedAt ?? 0;
            this.now = Date.now();
        },

        get seconds() {
            if (!this.startedAt) {
                return 0;
            }

            const end = this.finishedAt || this.now;

            return Math.max(0, Math.floor((end - this.startedAt) / 1000));
        },

        get clock() {
            return String(this.seconds).padStart(3, '0');
        },
    }));
});
