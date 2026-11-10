<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerImageController;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionSettingController;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionTargetController;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Public\BannerImageController as PublicBannerImageController;
use Plugins\Sirsoft\HeroSection\Http\Controllers\Public\HeroController;

/*
|--------------------------------------------------------------------------
| 히어로섹션 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| URL 프리픽스 자동 적용: /api/plugins/sirsoft-hero_section/
| 이름 프리픽스 자동 적용: api.plugins.sirsoft-hero_section.
|
| 권한: sirsoft-hero_section.banners.read(조회) · update(등록·수정·순서·일괄 노출/비노출·이미지·슬라이더 설정) · delete(삭제)
| 정적 경로(preview·order·bulk-status·bulk)는 {banner} 보다 먼저 선언한다.
|
*/

// 공개 — 사용자 템플릿 확장점(home_hero·shop_hero)이 부른다. 로그인했으면 사용자를 알아 수정 권한(abilities)만 붙인다
Route::get('/hero', [HeroController::class, 'show'])
    ->middleware(['optional.sanctum', 'throttle:600,1,sirsoft-hero_section.hero'])
    ->name('hero.show');
Route::get('/images/{hash}', [PublicBannerImageController::class, 'show'])
    ->where('hash', '[A-Za-z0-9]{64}')
    ->middleware('throttle:600,1,sirsoft-hero_section.images')
    ->name('images.show');

// 관리자
Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    $read = 'permission:admin,sirsoft-hero_section.banners.read';
    $update = 'permission:admin,sirsoft-hero_section.banners.update';
    $delete = 'permission:admin,sirsoft-hero_section.banners.delete';

    Route::get('/banners', [BannerController::class, 'index'])->middleware($read)->name('banners.index');
    Route::get('/banners/preview', [BannerController::class, 'preview'])->middleware($read)->name('banners.preview');
    Route::get('/banners/order', [BannerController::class, 'orderList'])->middleware($read)->name('banners.order');
    Route::put('/banners/order', [BannerController::class, 'reorder'])->middleware($update)->name('banners.reorder');
    Route::patch('/banners/bulk-status', [BannerController::class, 'bulkStatus'])->middleware($update)->name('banners.bulk-status');
    Route::delete('/banners/bulk', [BannerController::class, 'bulkDestroy'])->middleware($delete)->name('banners.bulk-destroy');
    Route::post('/banners', [BannerController::class, 'store'])->middleware($update)->name('banners.store');
    Route::get('/banners/{banner}', [BannerController::class, 'show'])->whereNumber('banner')->middleware($read)->name('banners.show');
    Route::put('/banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner')->middleware($update)->name('banners.update');
    Route::delete('/banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner')->middleware($delete)->name('banners.destroy');

    Route::post('/banner-images', [BannerImageController::class, 'store'])->middleware($update)->name('banner-images.store');
    Route::delete('/banner-images/{image}', [BannerImageController::class, 'destroy'])->whereNumber('image')->middleware($update)->name('banner-images.destroy');

    Route::get('/position-settings', [PositionSettingController::class, 'index'])->middleware($read)->name('position-settings.index');
    Route::put('/position-settings/{position}', [PositionSettingController::class, 'update'])->middleware($update)->name('position-settings.update');
    Route::get('/positions/{position}/targets', [PositionTargetController::class, 'index'])
        ->where('position', '[a-z][a-z0-9_]{1,49}')->middleware($read)->name('positions.targets');
    Route::get('/positions/{position}/placements', [PositionTargetController::class, 'placements'])
        ->where('position', '[a-z][a-z0-9_]{1,49}')->middleware($read)->name('positions.placements');
});
