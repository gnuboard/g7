<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin\RateRunController;

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::middleware('permission:admin,sirsoft-koreaexim.exchange_rate.view')->group(function () {
        Route::get('status', [RateRunController::class, 'status'])->name('admin.status');
        Route::get('runs', [RateRunController::class, 'index'])->name('admin.runs.index');
        Route::get('runs/{run}', [RateRunController::class, 'show'])->whereNumber('run')->name('admin.runs.show');
    });
    Route::post('runs', [RateRunController::class, 'store'])->middleware('permission:admin,sirsoft-koreaexim.exchange_rate.manage')->name('admin.runs.store');
});
