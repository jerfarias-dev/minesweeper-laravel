# Tecnologías del proyecto

Documento descriptivo del stack real del Buscaminas. Las versiones fueron verificadas
contra `composer.lock`, `package-lock.json` y el entorno local el **23 de noviembre de 2026**.

## 1. Resumen del stack

| Capa | Tecnología | Versión | Rol en el proyecto |
| --- | --- | --- | --- |
| Lenguaje | **PHP** | 8.5.12 | Dominio, aplicación y plantillas |
| Framework | **Laravel** | 13.43.0 | HTTP, sesión, Blade, contenedor, testing |
| Reactividad | **Livewire** | 4.4.9 | Componente interactivo sin recargar la página |
| Interactividad cliente | **Alpine.js** | 3.x (incluido por Livewire) | Reloj, selector de modo, pulsación larga y eventos |
| Estilos | **Tailwind CSS** | 4.3.5 | Layout y tema visual |
| Bundler | **Vite** | 8.3.8 | Compilación de CSS/JS y manifest |
| Integración Vite | **laravel-vite-plugin** | 3.2.1 | `@vite`, dev server y fuentes |
| Plugin Tailwind | **@tailwindcss/vite** | 4.3.5 | Procesado de Tailwind 4 |
| Plantillas | **Blade** | (Laravel 13) | HTML del tablero y del layout |
| Persistencia | **Sesión de Laravel** | driver `database` | Estado real de la partida |
| Base de datos | **SQLite** | incluida en PHP | Sesiones y caché (no se guardan partidas) |
| Tests | **PHPUnit** | 12.5.45 | Pruebas unitarias y de integración |
| Formato | **Laravel Pint** | 1.32.3 | Estilo de código PSR-12/Laravel |
| Utilidades | **Laravel Tinker** | 3.2.0 | Inspección manual del dominio |
| Soporte IA | **Laravel Boost** | 2.10.4 (dev) | Guías y skills para agentes |
| Dependencias | **Composer** | 2.11.2 | Backend |
| Dependencias | **Node.js / npm** | 24.22.1 | Frontend |

## 2. Backend

### 2.1 PHP 8.5

El proyecto exige PHP `^8.5` en `composer.json`. No es solo una versión nominal: se
usan características que no existen en versiones anteriores.

| Característica | Dónde se usa | Para qué |
| --- | --- | --- |
| `declare(strict_types=1)` | Todos los archivos PHP | Evitar coerciones silenciosas |
| Enums | `App\Game\Enums\Difficulty`, `GameStatus` | Niveles y estados con tipos cerrados |
| Clases `readonly` | `Board`, `GameState` | Inmutabilidad del estado de la partida |
| `private(set)` | `Cell` | Lectura pública, escritura solo dentro de la clase |
| Property hooks (8.4) | `Cell::$isEmpty`, `Cell::$isHidden` | Propiedades virtuales sin almacenamiento |
| `#[\NoDiscard]` | Métodos `with*()` y acciones del servicio | Obligar a usar el nuevo estado devuelto |
| Operador pipe `\|>` | `MinesweeperService::placeMines()` | Encadenar transformaciones sin variables temporales |
| `random_int()` | `MinesweeperService::shuffle()` | Aleatoriedad criptográficamente segura |
| Named arguments | `withCells($cells, minesPlaced: true)` | Claridad en llamadas booleanas |
| `match` | `Difficulty`, `GameStatus` | Sustituir cadenas de `switch` |
| Promoción de constructor | Todos los value objects | Menos código repetitivo |

Ejemplo real de `Cell` (asimetría + hook + no descarte):

```php
final class Cell
{
    public function __construct(
        public private(set) bool $mine = false,
        public private(set) bool $revealed = false,
        public private(set) bool $flagged = false,
        public private(set) int $adjacent = 0,
        public private(set) bool $exploded = false,
    ) {}

    public bool $isEmpty {
        get => ! $this->mine && $this->adjacent === 0;
    }

    #[\NoDiscard]
    public function revealed(): self
    {
        $clone = clone $this;
        $clone->revealed = true;

        return $clone;
    }
}
```

### 2.2 Laravel 13

Laravel aporta la infraestructura, no la lógica del juego:

- **Routing**: `Route::get('/', Minesweeper::class)` registra el componente Livewire
  como página completa (`routes/web.php`).
- **Layouts**: Livewire resuelve automáticamente `resources/views/layouts/app.blade.php`.
- **Sesión**: `SESSION_DRIVER=database`; el estado de cada partida se guarda bajo
  `minesweeper.game.{uuid}`.
- **Contenedor de servicios**: `app(MinesweeperService::class)` resuelve el servicio
  de dominio.
- **Blade**: escape automático de salida, `@class`, `@foreach`, `@if`.
- **Vite**: `@vite(['resources/css/app.css', 'resources/js/app.js'])`.
- **Testing**: `Tests\TestCase`, `Livewire::test()` y sesión `array` en `phpunit.xml`.
- **Base de datos**: SQLite, usada por las tablas `sessions` y `cache`. El juego en sí
  **no tiene modelos Eloquent ni migraciones propias**.

### 2.3 Livewire 4

Se eligió Livewire (frente a una API + SPA) porque el estado debe vivir en el servidor:
enviar el tablero al navegador y aceptar acciones del cliente abre la puerta a trampas.
Con Livewire, cada clic es una petición validada en PHP y la vista se actualiza por morph.

Elementos usados:

| API | Uso |
| --- | --- |
| Componente de clase | `app/Livewire/Minesweeper.php` |
| `mount()` | Crea el `gameId` y la partida inicial |
| Acciones públicas | `reveal()`, `toggleFlag()`, `newGame()`, `changeDifficulty()` |
| `#[Locked]` | Impide que el navegador modifique props derivadas |
| `wire:click` | Reinicio y cambio de nivel |
| `$wire.reveal()` / `$wire.toggleFlag()` | Invocación desde Alpine |
| `wire:key` | Identidad estable de cada una de las 480 celdas |
| `wire:loading.class` | Feedback visual durante la petición |
| `$this->dispatch()` | Evento `minesweeper-sync` con el tiempo oficial |
| Morph | Actualización del DOM sin recargar ni re-renderizar todo |
| `Livewire::test()` | Pruebas de integración del componente |

Livewire 4 incluye Alpine.js; por eso `resources/js/app.js` **no importa** otra copia.
El componente Alpine se registra en `alpine:init` sobre `window.Alpine`.

### 2.4 Persistencia

No hay base de datos de partidas. Cada `GameState` se serializa a un array compacto y
se guarda en la sesión del servidor:

```json
{
  "difficulty": "beginner",
  "status": "pending",
  "started_at": null,
  "finished_at": null,
  "board": {
    "difficulty": "beginner",
    "mines_placed": false,
    "cells": [
      { "m": false, "r": false, "f": false, "a": 0, "x": false }
    ]
  }
}
```

Claves de celda: `m` mina, `r` revelada, `f` bandera, `a` adyacentes, `x` detonada.

Tamaño real del payload JSON:

| Nivel | Celdas | Tamaño |
| --- | ---: | ---: |
| Principiante | 64 | ~3,2 KB |
| Intermedio | 256 | ~12,4 KB |
| Experto | 480 | ~23,0 KB |

Ventajas: cero migraciones, aislamiento por navegador y estado efímero. Limitación:
al refrescar se empieza una partida nueva (no hay reanudación).

## 3. Frontend

### 3.1 Blade

- `resources/views/livewire/minesweeper.blade.php`: HUD, niveles, progreso, tablero,
  banners de resultado y ayuda.
- `resources/views/layouts/app.blade.php`: documento HTML, metadatos, favicon emoji,
  Vite, `@livewireStyles` y `@livewireScripts`.
- No se usa `{!! !!}`: toda salida pasa por el escape de Blade.

### 3.2 Alpine.js

Se usa para lo que no necesita servidor:

- **Reloj**: interpola entre respuestas con `startedAt`/`finishedAt` en milisegundos.
  El valor oficial siempre lo calcula PHP; Alpine solo lo muestra avanzar.
- **Modo bandera**: selector "Descubrir / Bandera" para que el clic izquierdo coloque
  banderas, más pulsación larga (450 ms) como atajo táctil sin cambiar de modo.
- **Listener** `minesweeper-sync.window`: recibe el estado temporal tras cada jugada.

```js
document.addEventListener('alpine:init', () => {
    window.Alpine.data('minesweeperBoard', ({ startedAt = 0, finishedAt = 0 } = {}) => ({
        startedAt,
        finishedAt,
        now: Date.now(),
        flagMode: false,
        // ...
    }));
});
```

No se importa `alpinejs` desde npm: Livewire ya lo incluye y arranca. Importarlo
crearía una segunda instancia y los componentes no se inicializarían.

### 3.3 Tailwind CSS 4

Configuración *CSS-first*, sin `tailwind.config.js`:

- `@import 'tailwindcss';`
- `@source` para que el scanner incluya `app/Livewire` y `resources/views`.
- `@theme` para la fuente Instrument Sans.
- `@layer components` para `.mine-board`, `.mine-cell`, `.hud-led`, `.smiley-button`,
  `.difficulty-tab`.
- Variables CSS `--cols`, `--cell-size`, `--cell-gap` para escalar el tablero.
- `data-level="expert"` reduce el tamaño de celda en pantallas pequeñas.

Los colores de los números (1 azul, 2 verde, 3 rojo…) se definen con selectores
`[data-n='1']`…`[data-n='8']`.

### 3.4 Vite

- Entradas: `resources/css/app.css` y `resources/js/app.js`.
- `@vite` inyecta el manifest compilado en producción y HMR en desarrollo.
- `laravel-vite-plugin` genera además las fuentes Instrument Sans (woff/woff2).
- Salida en `public/build/` (no versionada como código fuente).

## 4. Testing y calidad

### 4.1 PHPUnit 12

- `tests/Unit/MinesweeperServiceTest.php`: dominio puro, sin arrancar Laravel.
- `tests/Feature/MinesweeperComponentTest.php`: componente Livewire, sesión,
  payload saneado, banners y evento de reloj.
- `tests/Feature/ExampleTest.php`: smoke test de la portada.

Total actual: **24 tests**. La sesión de test usa `array` y SQLite en memoria
(`phpunit.xml`), por lo que no toca la base de datos real.

### 4.2 Laravel Pint

Preset Laravel por defecto (`./vendor/bin/pint`). Se aplicó a `app/`, `routes/` y
`tests/`.

### 4.3 Laravel Boost

Dependencia de desarrollo que añade guías y skills para asistentes de código
(`CLAUDE.md`, `.claude/`, `.mcp.json`). No participa en runtime ni en producción.

## 5. Requisitos y verificación

```bash
php -v            # PHP 8.5.12
composer -V       # Composer 2.11.2
node -v           # v24.22.1

composer show laravel/framework livewire/livewire
npm list --depth=0
```

Requisitos mínimos: **PHP 8.5**, Composer 2, Node 20+ y una base SQLite (incluida en PHP).

## 6. Tecnologías deliberadamente ausentes

| No se usa | Motivo |
| --- | --- |
| React / Vue / Svelte | Livewire + Alpine cubren la reactividad sin duplicar estado |
| API REST / GraphQL | No hay clientes externos; el estado debe quedar en servidor |
| WebSockets | El juego es de un jugador y por turnos |
| Modelos Eloquent para partidas | La sesión es suficiente y evita esquema/migraciones |
| Autenticación | No hay cuentas ni datos personales |
| Librería de iconos | Emojis nativos (💣 🚩 ⏱️ 😎) |
| `tailwind.config.js` | Tailwind 4 se configura en CSS |
| Imágenes externas | Todo el arte son gradientes CSS y emojis |

## 7. Por qué este stack

1. **Laravel + Livewire** permiten mantener la verdad en el servidor sin renunciar a
   una experiencia interactiva: cada acción se valida en PHP.
2. **PHP 8.5** aporta inmutabilidad expresiva (`readonly`, `private(set)`), enums y
   hooks que reducen código defensivo.
3. **Tailwind 4 + Vite** dan un build pequeño (~63 KB CSS, ~0,6 KB JS propio) y un
   tema consistente sin dependencias de UI.
4. **PHPUnit + Pint** mantienen la lógica verificada y el estilo uniforme.
