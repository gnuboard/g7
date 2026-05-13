<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageServeController;
use Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageUploadController;

/*
 * sirsoft-ckeditor5 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/sirsoft-ckeditor5 (PluginRouteServiceProvider 자동 적용)
 *
 * 인증 라우트: AdminBaseController에서 auth:sanctum + admin 미들웨어 적용
 * 공개 라우트: PublicBaseController 사용, 인증 불필요
 */

// 이미지 업로드 (관리자 인증 필요)
Route::post('upload', [ImageUploadController::class, 'upload'])
    ->name('api.sirsoft-ckeditor5.upload');

// 이미지 서빙 (공개 접근 — CKEditor 에디터 내 <img src> 직접 접근)
Route::get('images/{hash}', [ImageServeController::class, 'serve'])
    ->where('hash', '[a-f0-9]{12}')
    ->name('api.sirsoft-ckeditor5.images.serve');
