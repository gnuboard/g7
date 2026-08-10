<?php

use Illuminate\Support\Facades\Route;
use Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController as AdminMemoController;
use Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController;

/*
|--------------------------------------------------------------------------
| Hello Module API Routes
|--------------------------------------------------------------------------
|
| ModuleRouteServiceProvider 가 자동으로 prefix 를 적용합니다.
| - URL prefix: 'api/modules/gnuboard7-hello_module'
| - Name prefix: 'api.modules.gnuboard7-hello_module.'
|
| 레이아웃 JSON 의 data_sources 가 소비하는 엔드포인트는 전부 이 파일에 둔다.
| routes/web.php 는 'modules/{id}' prefix + web 미들웨어라 JSON API 로는 도달하지 않는다.
|
*/

/*
| 공개 읽기 API — 비로그인 사용자도 접근 가능
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

/*
| 관리자 CRUD API — 인증 + 권한 미들웨어로 보호
*/
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
