<?php

use Illuminate\Support\Facades\Route;
use Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController;

/*
|--------------------------------------------------------------------------
| Hello Module Admin Routes
|--------------------------------------------------------------------------
|
| ModuleRouteServiceProvider 가 자동으로 prefix 를 적용합니다.
| - URL prefix: 'api/modules/gnuboard7-hello_module'
| - Name prefix: 'api.modules.gnuboard7-hello_module.'
|
*/

Route::prefix('admin/memos')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('admin.memos.')
    ->group(function () {
        Route::get('/', [MemoController::class, 'index'])
            ->middleware('permission:admin,gnuboard7-hello_module.memos.read')
            ->name('index');

        Route::post('/', [MemoController::class, 'store'])
            ->middleware('permission:admin,gnuboard7-hello_module.memos.create')
            ->name('store');

        Route::get('/{id}', [MemoController::class, 'show'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.read')
            ->name('show');

        Route::put('/{id}', [MemoController::class, 'update'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.update')
            ->name('update');

        Route::delete('/{id}', [MemoController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('permission:admin,gnuboard7-hello_module.memos.delete')
            ->name('destroy');
    });
