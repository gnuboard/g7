<?php

use Illuminate\Support\Facades\Route;
use Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController as AdminMemoController;
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

Route::prefix('admin/memos')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('admin.memos.')
    ->group(function () {
        Route::get('/', [AdminMemoController::class, 'index'])
            ->middleware('permission:admin,gnuboard7-hello_module.memos.read')
            ->name('index');

        Route::post('/', [AdminMemoController::class, 'store'])
            ->middleware('permission:admin,gnuboard7-hello_module.memos.create')
            ->name('store');

        Route::get('/{id}', [AdminMemoController::class, 'show'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.read')
            ->name('show');

        Route::put('/{id}', [AdminMemoController::class, 'update'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.update')
            ->name('update');

        Route::delete('/{id}', [AdminMemoController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.delete')
            ->name('destroy');
    });
