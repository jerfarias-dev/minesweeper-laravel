# 💣 Buscaminas — Laravel 13 · Livewire 4 · PHP 8.5

[![Tests](https://github.com/jerfarias-dev/minesweeper-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/jerfarias-dev/minesweeper-laravel/actions/workflows/tests.yml)

Réplica funcional del clásico Buscaminas con lógica **100 % validada en el servidor**.
El navegador solo recibe celdas saneadas: inspeccionar el DOM o el payload de Livewire
nunca revela dónde están las minas.

## Requisitos

- PHP **8.5+** (se usan property hooks, `private(set)`, `#[\NoDiscard]` y el operador pipe `|>`)
- Composer 2
- Node 20+ (para compilar Tailwind/Vite)

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve
```

Abre <http://127.0.0.1:8000>.

## Cómo jugar

- **Clic izquierdo / toque**: descubre una celda.
- **Clic derecho**: coloca o quita una bandera.
- **Selector "Bandera"**: el clic izquierdo pasa a colocar banderas (cómodo en móvil).
- **Mantener pulsado** en móvil: bandera rápida sin cambiar de modo.
- Contador superior: minas restantes según tus banderas (tope = total de minas).

## Estructura

```
app/
├── Game/                          # Dominio puro: sin HTTP, sin Livewire, sin BD
│   ├── Enums/
│   │   ├── Difficulty.php         # 8x8/10 · 16x16/40 · 16x30/99
│   │   └── GameStatus.php         # pending → playing → won | lost
│   ├── ValueObjects/
│   │   ├── Cell.php               # inmutable, private(set), property hooks
│   │   ├── Board.php              # lista plana de celdas, vecinos, serialización
│   │   └── GameState.php          # tablero + estado + cronómetro
│   └── Services/
│       └── MinesweeperService.php # primer clic seguro, desborde, banderas, victoria
│
├── Livewire/
│   └── Minesweeper.php            # capa de aplicación: sesión, saneado, eventos
│
resources/
├── css/app.css                    # tema + celdas + números clásicos
├── js/app.js                      # reloj Alpine (interpola; PHP manda)
└── views/
    ├── layouts/app.blade.php
    └── livewire/minesweeper.blade.php

docs/
├── tecnologias.md                  # stack, versiones y uso de cada tecnología
└── arquitectura.md                 # capas, flujos, algoritmos y decisiones
```

## Reglas implementadas

| Regla | Dónde |
| --- | --- |
| Primer clic seguro | Las minas se colocan en el primer `reveal()`, excluyendo la celda y sus 8 vecinas |
| Desborde | Flood-fill iterativo desde celdas con 0 minas adyacentes (sin recursión) |
| Banderas | Clic derecho, selector Descubrir/Bandera o mantener pulsado en móvil; tope = minas |
| Cronómetro | `startedAt`/`finishedAt` en servidor; Alpine interpola entre respuestas |
| Victoria | Todas las celdas seguras reveladas → se abanderan las minas automáticamente |
| Derrota | Se destapan todas las minas y se marca la detonada |
| Anti-trampas | Estado real en sesión; `#[Locked]`; coordenadas revalidadas; minas ocultas hasta perder |

## Tests

```bash
php artisan test
```

24 tests cubren primer clic seguro en los tres niveles, adyacencia, desborde,
banderas, victoria, derrota, límites, inmutabilidad, serialización y el componente
Livewire.

## Documentación

- [Tecnologías](docs/tecnologias.md)
- [Arquitectura](docs/arquitectura.md)
