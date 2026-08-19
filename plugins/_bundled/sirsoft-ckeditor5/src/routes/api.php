<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Ckeditor5\Http\Controllers\Admin\ImageUploadAdminController;
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

/*
| 업로드 이미지 관리 (관리자)
|
| 조회와 삭제를 별도 권한으로 분리한다 — 삭제는 파일을 실제로 파기하기 때문이다.
*/
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    Route::get('uploads', [ImageUploadAdminController::class, 'index'])
        ->middleware('permission:admin,sirsoft-ckeditor5.uploads.read')
        ->name('uploads.index');

    Route::post('uploads/bulk-delete', [ImageUploadAdminController::class, 'bulkDestroy'])
        ->middleware('permission:admin,sirsoft-ckeditor5.uploads.delete')
        ->name('uploads.bulk-delete');

    Route::delete('uploads/{id}', [ImageUploadAdminController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-ckeditor5.uploads.delete')
        ->name('uploads.destroy');
});
