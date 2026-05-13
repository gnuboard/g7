<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Page\Http\Controllers\Admin\PageAttachmentController;
use Modules\Sirsoft\Page\Http\Controllers\Admin\PageController;
use Modules\Sirsoft\Page\Http\Controllers\User\PublicPageAttachmentController;
use Modules\Sirsoft\Page\Http\Controllers\User\PublicPageController;

/*
|--------------------------------------------------------------------------
| Sirsoft Page Module API Routes
|--------------------------------------------------------------------------
|
| 페이지 모듈의 API 라우트입니다.
|
| 주의: ModuleRouteServiceProvider가 자동으로 prefix를 적용합니다.
| - URL prefix: 'api/modules/sirsoft-page'
| - Name prefix: 'api.modules.sirsoft-page.'
| 최종 URL 예시: /api/modules/sirsoft-page/admin/pages
| 최종 Name 예시: api.modules.sirsoft-page.admin.pages.index
|
*/

/*
|--------------------------------------------------------------------------
| Admin Page Routes (페이지 관리)
|--------------------------------------------------------------------------
|
| 관리자만 접근 가능한 페이지 관리 API입니다.
|
*/
Route::prefix('admin/pages')->middleware(['auth:sanctum', 'throttle:600,1'])->name('admin.pages.')->group(function () {
    // 슬러그 중복 확인 (/{page} 보다 먼저 등록)
    Route::post('/check-slug', [PageController::class, 'checkSlug'])
        ->middleware('permission:admin,sirsoft-page.pages.create')
        ->name('check-slug');

    // 일괄 발행/미발행 (/{page} 보다 먼저 등록)
    Route::patch('/bulk-publish', [PageController::class, 'bulkPublish'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('bulk-publish');

    // 페이지 목록 조회
    Route::get('/', [PageController::class, 'index'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->name('index');

    // 페이지 생성
    Route::post('/', [PageController::class, 'store'])
        ->middleware('permission:admin,sirsoft-page.pages.create')
        ->name('store');

    // 버전 이력 조회 (/{page} 보다 먼저 등록 불가하나, /versions 패턴이므로 정상 동작)
    Route::get('/{page}/versions', [PageController::class, 'versions'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->name('versions.index');

    // 버전 상세 조회
    Route::get('/{page}/versions/{versionId}', [PageController::class, 'showVersion'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->name('versions.show');

    // 버전 복원
    Route::post('/{page}/versions/{versionId}/restore', [PageController::class, 'restoreVersion'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('versions.restore');

    // 발행/미발행 토글
    Route::patch('/{page}/publish', [PageController::class, 'publish'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('publish');

    // 페이지 상세 조회
    Route::get('/{page}', [PageController::class, 'show'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->name('show');

    // 페이지 수정
    Route::put('/{page}', [PageController::class, 'update'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('update');

    // 페이지 삭제
    Route::delete('/{page}', [PageController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-page.pages.delete')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Attachment Routes (첨부파일 관리)
|--------------------------------------------------------------------------
|
| 관리자만 접근 가능한 첨부파일 관리 API입니다.
|
*/
Route::prefix('admin/attachments')->middleware(['auth:sanctum', 'throttle:600,1'])->name('admin.attachments.')->group(function () {
    // 첨부파일 업로드
    Route::post('/', [PageAttachmentController::class, 'upload'])
        ->middleware('permission:admin,sirsoft-page.pages.create')
        ->name('upload');

    // 첨부파일 순서 변경
    Route::patch('/reorder', [PageAttachmentController::class, 'reorder'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('reorder');

    // 첨부파일 다운로드 (해시 기반)
    Route::get('/download/{hash}', [PageAttachmentController::class, 'download'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('download');

    // 첨부파일 이미지 미리보기 (해시 기반, inline)
    Route::get('/preview/{hash}', [PageAttachmentController::class, 'preview'])
        ->middleware('permission:admin,sirsoft-page.pages.read')
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('preview');

    // 첨부파일 삭제
    Route::delete('/{id}', [PageAttachmentController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-page.pages.update')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Public Page Routes (공개 페이지 조회)
|--------------------------------------------------------------------------
|
| 비로그인 사용자도 접근 가능한 공개 API입니다.
| - 발행된 페이지를 슬러그로 조회합니다.
|
*/
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('pages')->middleware(['optional.sanctum', 'throttle:600,1'])->name('pages.')->group(function () {
    // 공개 첨부파일 다운로드 (해시 기반) - /{slug} 보다 먼저 등록
    Route::get('/attachment/{hash}', [PublicPageAttachmentController::class, 'download'])
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('attachment.download');

    // 공개 첨부파일 이미지 미리보기 (해시 기반, inline)
    Route::get('/attachment/{hash}/preview', [PublicPageAttachmentController::class, 'preview'])
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('attachment.preview');

    // 발행된 페이지 조회 (슬러그 기반)
    Route::get('/{slug}', [PublicPageController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('show');
});
