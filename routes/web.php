<?php

declare(strict_types=1);

use App\Livewire\Minesweeper;
use Illuminate\Support\Facades\Route;

Route::get('/', Minesweeper::class)->name('minesweeper');
