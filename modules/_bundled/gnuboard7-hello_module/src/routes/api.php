<?php

use Illuminate\Support\Facades\Route;
use Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController;

/*
|--------------------------------------------------------------------------
| Hello Module Public API Routes
|--------------------------------------------------------------------------
|
| 비로그인 사용자도 접근 가능한 공개 읽기 API.
|
*/

Route::prefix('memos')
    ->middleware(['optional.sanctum', 'throttle:600,1'])
    ->name('memos.')
    ->group(function () {
        Route::get('/', [MemoController::class, 'index'])->name('index');
        Route::get('/{id}', [MemoController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
    });
