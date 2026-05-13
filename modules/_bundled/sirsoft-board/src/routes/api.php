<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController as AdminAttachmentController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController as AdminCommentController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\PostController as AdminPostController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController as AdminReportController;
use Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController as UserAttachmentController;
use Modules\Sirsoft\Board\Http\Controllers\User\BoardController as UserBoardController;
use Modules\Sirsoft\Board\Http\Controllers\User\CommentController as UserCommentController;
use Modules\Sirsoft\Board\Http\Controllers\User\PostController as UserPostController;
use Modules\Sirsoft\Board\Http\Controllers\User\ReportController as UserReportController;
use Modules\Sirsoft\Board\Http\Controllers\User\UserActivityController;

/*
|--------------------------------------------------------------------------
| Sirsoft Board Module API Routes
|--------------------------------------------------------------------------
|
| 게시판 모듈의 API 라우트입니다.
| 게시판 관리 API를 제공합니다.
|
| 주의: ModuleRouteServiceProvider가 자동으로 prefix를 적용합니다.
| - URL prefix: 'api/modules/sirsoft-board'
| - Name prefix: 'api.modules.sirsoft-board.'
| 최종 URL 예시: /api/modules/sirsoft-board/admin/boards
| 최종 Name 예시: api.modules.sirsoft-board.admin.boards.index
|
*/

/*
|--------------------------------------------------------------------------
| Public API Routes (인증 불필요)
|--------------------------------------------------------------------------
|
| 비로그인 사용자도 접근 가능한 공개 API입니다.
| - 게시판 목록/상세/통계
| - 첨부파일 다운로드 (공개)
|
*/
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('boards')->middleware(['optional.sanctum', 'throttle:600,1'])->name('boards.')->group(function () {
    // 네비게이션 메뉴용 경량 게시판 목록 (id, name, slug만 반환)
    Route::get('/board-menu', [UserBoardController::class, 'boardMenu'])
        ->name('board-menu');

    // 게시판 통계 API (홈 페이지용)
    Route::get('/stats', [UserBoardController::class, 'stats'])
        ->name('stats');

    // 최근 게시글 API (홈 페이지용)
    Route::get('/posts/recent', [UserBoardController::class, 'recentPosts'])
        ->name('posts.recent');

    // 인기 게시판 목록 API (홈 페이지용)
    Route::get('/popular-boards', [UserBoardController::class, 'popularBoards'])
        ->name('popular-boards');

    // 인기 게시판 API (홈 페이지용)
    Route::get('/popular', [UserBoardController::class, 'popular'])
        ->name('popular');

    // 활성화된 게시판 목록 조회
    Route::get('/', [UserBoardController::class, 'index'])
        ->name('index');

    // 게시판 상세 정보 조회
    Route::get('/{slug}', [UserBoardController::class, 'show'])
        ->name('show');

    // 첨부파일 다운로드 (해시 기반, 권한 체크)
    Route::get('/{slug}/attachment/{hash}', [UserAttachmentController::class, 'download'])
        ->middleware('permission:user,sirsoft-board.{slug}.attachments.download')
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('attachment.download');

});

// 첨부파일 이미지 미리보기 (공개 - 권한 체크 없이 이미지만 제공)
// 갤러리 게시판 등에서 다수의 썸네일을 동시 로드하므로 별도 throttle 적용 (300/분)
Route::prefix('boards')->middleware(['optional.sanctum', 'throttle:600,1'])->name('boards.')->group(function () {
    Route::get('/{slug}/attachment/{hash}/preview', [UserAttachmentController::class, 'preview'])
        ->where('hash', '[a-zA-Z0-9]{12}')
        ->name('attachment.preview');
});

/*
|--------------------------------------------------------------------------
| Admin Settings Routes (환경설정 관리)
|--------------------------------------------------------------------------
|
| 게시판 모듈 환경설정 API입니다.
| - 환경설정 조회/저장/일괄적용/캐시초기화
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/settings
| 최종 Name 예시: api.modules.sirsoft-board.admin.settings.index
|
*/
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 환경설정 전체 조회
    Route::get('settings', [BoardSettingsController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.settings.read')
        ->name('admin.settings.index');

    // 환경설정 저장
    Route::put('settings', [BoardSettingsController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.settings.store');

    // 환경설정 기본값 일괄 적용 (와일드카드 라우트보다 먼저 등록)
    Route::post('settings/bulk-apply', [BoardSettingsController::class, 'bulkApply'])
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.settings.bulk-apply');

    // 설정 캐시 초기화 (와일드카드 라우트보다 먼저 등록)
    Route::post('settings/clear-cache', [BoardSettingsController::class, 'clearCache'])
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.settings.clear-cache');

    // 환경설정 카테고리별 조회 (와일드카드 라우트 - 마지막에 배치)
    Route::get('settings/{category}', [BoardSettingsController::class, 'show'])
        ->middleware('permission:admin,sirsoft-board.settings.read')
        ->name('admin.settings.show');

});

/*
|--------------------------------------------------------------------------
| Admin Board Type Routes (게시판 유형 관리)
|--------------------------------------------------------------------------
|
| 게시판 유형 CRUD API입니다.
| 게시판 관리 권한(boards.create)을 재사용합니다.
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/board-types
| 최종 Name 예시: api.modules.sirsoft-board.admin.board-types.index
|
*/
Route::prefix('admin/board-types')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.board-types.')->group(function () {
    Route::get('/', [BoardTypeController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.boards.create')
        ->name('index');

    Route::post('/', [BoardTypeController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.boards.create')
        ->name('store');

    Route::put('/{id}', [BoardTypeController::class, 'update'])
        ->middleware('permission:admin,sirsoft-board.boards.create')
        ->name('update');

    Route::delete('/{id}', [BoardTypeController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.boards.create')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Board Routes (게시판 관리)
|--------------------------------------------------------------------------
*/
Route::prefix('admin/boards')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.boards.')->group(function () {
    // 게시판 폼 데이터 통합 조회
    Route::get('/form-data', [BoardController::class, 'getFormData'])
        ->middleware('permission:admin,sirsoft-board.boards.read')
        ->name('form-data');

    // 게시판 목록 조회
    Route::get('/', [BoardController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.boards.read')
        ->name('index');

    // 슬러그로 게시판 조회
    Route::get('/slug/{slug}', [BoardController::class, 'showBySlug'])
        ->middleware('permission:admin,sirsoft-board.boards.read')
        ->name('show-by-slug');

    // 게시판 단건 조회 (ID)
    Route::get('/{board}', [BoardController::class, 'show'])
        ->middleware('permission:admin,sirsoft-board.boards.read')
        ->name('show');

    // 게시판 생성
    Route::post('/', [BoardController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.boards.create')
        ->name('store');

    // 게시판 수정
    Route::put('/{board}', [BoardController::class, 'update'])
        ->middleware('permission:admin,sirsoft-board.boards.update')
        ->name('update');

    // 게시판 삭제
    Route::delete('/{board}', [BoardController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.boards.delete')
        ->name('destroy');

    // 게시판을 관리자 메뉴에 추가
    Route::post('/{board}/add-to-menu', [BoardController::class, 'addToAdminMenu'])
        ->middleware('permission:admin,sirsoft-board.boards.update')
        ->name('add-to-menu');
});

/*
|--------------------------------------------------------------------------
| Admin Report Routes (신고 관리)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/reports
| 최종 Name 예시: api.modules.sirsoft-board.admin.reports.index
|
*/
Route::prefix('admin/reports')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.reports.')->group(function () {
    // 선택된 신고들의 상태별 건수 조회 (모달 열기 전 사용)
    Route::post('/status-counts', [AdminReportController::class, 'getStatusCounts'])
        ->middleware('permission:admin,sirsoft-board.reports.view')
        ->name('status-counts');

    // 대량 상태 변경 (개별 라우트보다 먼저 등록)
    Route::patch('/bulk-status', [AdminReportController::class, 'bulkUpdateStatus'])
        ->middleware('permission:admin,sirsoft-board.reports.manage')
        ->name('bulk-status');

    // 신고 목록 조회
    Route::get('/', [AdminReportController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.reports.view')
        ->name('index');

    // 신고 상세 조회
    Route::get('/{report}', [AdminReportController::class, 'show'])
        ->middleware('permission:admin,sirsoft-board.reports.view')
        ->name('show');

    // 신고자 목록 페이지네이션 조회
    Route::get('/{report}/reporters', [AdminReportController::class, 'reporters'])
        ->middleware('permission:admin,sirsoft-board.reports.view')
        ->name('reporters');

    // 신고 상태 변경
    Route::patch('/{report}/status', [AdminReportController::class, 'updateStatus'])
        ->middleware('permission:admin,sirsoft-board.reports.manage')
        ->name('update-status');

    // 신고 삭제
    Route::delete('/{report}', [AdminReportController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.reports.manage')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Post Routes (게시글 관리)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/board/{slug}/posts
| 최종 Name 예시: api.modules.sirsoft-board.admin.board.posts.index
|
*/
Route::prefix('admin/board/{slug}/posts')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.board.posts.')->group(function () {
    // 게시글 폼 입력 데이터 조회 (API 전송용)
    Route::get('/form-data', [AdminPostController::class, 'getFormData'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.write')
        ->name('form-data');

    // 게시글 폼 메타 데이터 조회 (화면 표시용)
    Route::get('/form-meta', [AdminPostController::class, 'getFormMeta'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.write')
        ->name('form-meta');

    // 게시글 목록 조회
    Route::get('/', [AdminPostController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.read')
        ->name('index');

    // 게시글 상세 조회
    Route::get('/{id}', [AdminPostController::class, 'show'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.read')
        ->name('show');

    // 게시글 생성
    Route::post('/', [AdminPostController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.write')
        ->name('store');

    // 게시글 수정
    Route::put('/{id}', [AdminPostController::class, 'update'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.write')
        ->name('update');

    // 게시글 삭제
    Route::delete('/{id}', [AdminPostController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.posts.write|sirsoft-board.{slug}.admin.manage,false')
        ->name('destroy');

    // 게시글 블라인드 처리
    Route::patch('/{id}/blind', [AdminPostController::class, 'blind'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('blind');

    // 게시글 복원
    Route::patch('/{id}/restore', [AdminPostController::class, 'restore'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('restore');
});

/*
|--------------------------------------------------------------------------
| Admin Attachment Routes (관리자 첨부파일)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/board/{slug}/attachments
| 최종 Name 예시: api.modules.sirsoft-board.admin.board.attachments.upload
|
*/
Route::prefix('admin/board/{slug}/attachments')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.board.attachments.')->group(function () {
    // 첨부파일 업로드
    Route::post('/', [AdminAttachmentController::class, 'upload'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.attachments.upload')
        ->name('upload');

    // 첨부파일 삭제
    Route::delete('/{id}', [AdminAttachmentController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.attachments.upload')
        ->name('destroy');

    // 첨부파일 순서 변경 (FileUploader 컴포넌트가 PATCH 메서드 사용)
    Route::patch('/reorder', [AdminAttachmentController::class, 'reorder'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.attachments.upload')
        ->name('reorder');

    // 첨부파일 다운로드 (해시 기반)
    Route::get('/download/{hash}', [AdminAttachmentController::class, 'download'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.attachments.download')
        ->name('download');
});

/*
|--------------------------------------------------------------------------
| Admin Comment Routes (관리자 댓글)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments
| 최종 Name 예시: api.modules.sirsoft-board.admin.board.posts.comments.store
|
*/
Route::prefix('admin/board/{slug}/posts/{postId}/comments')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.board.posts.comments.')->group(function () {
    // 댓글 생성
    Route::post('/', [AdminCommentController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.comments.write')
        ->name('store');

    // 댓글 수정
    Route::put('/{id}', [AdminCommentController::class, 'update'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.comments.write')
        ->name('update');

    // 댓글 삭제
    Route::delete('/{id}', [AdminCommentController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.comments.write|sirsoft-board.{slug}.admin.manage,false')
        ->name('destroy');

    // 댓글 블라인드 처리
    Route::patch('/{id}/blind', [AdminCommentController::class, 'blind'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('blind');

    // 댓글 복원
    Route::patch('/{id}/restore', [AdminCommentController::class, 'restore'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('restore');
});

/*
|--------------------------------------------------------------------------
| User Post Routes (사용자 게시글) - 회원/비회원 모두 접근 가능
|--------------------------------------------------------------------------
|
| 게시글 조회는 비회원도 가능, 작성/수정/삭제는 회원만 가능합니다.
| - permission 미들웨어가 토큰 체크 및 권한 확인을 모두 처리
| - 토큰 있음: 회원 권한 체크 / 토큰 없음: 비회원(guest) 권한 체크
|
| 최종 URL 예시: /api/modules/sirsoft-board/boards/{slug}/posts
| 최종 Name 예시: api.modules.sirsoft-board.boards.posts.index
|
*/
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('boards/{slug}/posts')->middleware(['optional.sanctum', 'throttle:600,1'])->name('boards.posts.')->group(function () {
    // 게시글 폼 데이터 조회 (생성/수정/답글 모드)
    Route::get('/form-data', [UserPostController::class, 'getFormData'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write')
        ->name('form-data');

    // 게시글 폼 메타 데이터 조회
    Route::get('/form-meta', [UserPostController::class, 'getFormMeta'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write')
        ->name('form-meta');

    // 게시글 목록 조회
    Route::get('/', [UserPostController::class, 'index'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('index');

    // 게시글 상세 조회
    Route::get('/{id}', [UserPostController::class, 'show'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('show');

    // 게시글 이전/다음 네비게이션 조회 (비동기 로딩용)
    Route::get('/{id}/navigation', [UserPostController::class, 'navigation'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('navigation');

    // 게시글 생성
    Route::post('/', [UserPostController::class, 'store'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write')
        ->name('store');

    // 게시글 수정
    Route::put('/{id}', [UserPostController::class, 'update'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write')
        ->name('update');

    // 게시글 삭제
    Route::delete('/{id}', [UserPostController::class, 'destroy'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write|sirsoft-board.{slug}.manager,false')
        ->name('destroy');

    // 비밀글 조회용 비밀번호 검증 (posts.read 권한 필요)
    Route::post('/{id}/verify-password', [UserPostController::class, 'verifyPassword'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('verify-password');

    // 수정/삭제용 비밀번호 검증 (posts.write 권한 필요)
    Route::post('/{id}/verify-password-for-modify', [UserPostController::class, 'verifyPasswordForModify'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write')
        ->name('verify-password-for-modify');
});

/*
|--------------------------------------------------------------------------
| User Comment Routes (사용자 댓글) - 회원/비회원 모두 접근 가능
|--------------------------------------------------------------------------
|
| 댓글 조회는 비회원도 가능, 작성/수정/삭제는 회원만 가능합니다.
| - permission 미들웨어가 토큰 체크 및 권한 확인을 모두 처리
|
| 최종 URL 예시: /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments
| 최종 Name 예시: api.modules.sirsoft-board.boards.posts.comments.index
|
*/
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('boards/{slug}/posts/{postId}/comments')->middleware(['optional.sanctum', 'throttle:600,1'])->name('boards.posts.comments.')->group(function () {
    // 댓글 목록 조회
    Route::get('/', [UserCommentController::class, 'index'])
        ->middleware('permission:user,sirsoft-board.{slug}.comments.read')
        ->name('index');

    // 댓글 생성
    Route::post('/', [UserCommentController::class, 'store'])
        ->middleware('permission:user,sirsoft-board.{slug}.comments.write')
        ->name('store');

    // 댓글 수정
    Route::put('/{commentId}', [UserCommentController::class, 'update'])
        ->middleware('permission:user,sirsoft-board.{slug}.comments.write')
        ->name('update');

    // 댓글 삭제
    Route::delete('/{commentId}', [UserCommentController::class, 'destroy'])
        ->middleware('permission:user,sirsoft-board.{slug}.comments.write|sirsoft-board.{slug}.manager,false')
        ->name('destroy');
});

// 비회원 댓글 비밀번호 검증
Route::post('boards/{slug}/comments/{commentId}/verify-password', [UserCommentController::class, 'verifyPassword'])
    ->middleware(['optional.sanctum', 'permission:user,sirsoft-board.{slug}.comments.write'])
    ->name('boards.comments.verify-password');

/*
|--------------------------------------------------------------------------
| User Activity Routes (마이페이지 게시글 활동) - 회원 전용
|--------------------------------------------------------------------------
|
| 로그인한 회원의 게시글 활동(작성, 댓글, 신고)을 조회하는 API입니다.
| - 회원만 접근 가능하므로 auth:sanctum 미들웨어 필요
|
| 최종 URL 예시: /api/me/board-activities
| 최종 Name 예시: api.me.board-activities.index
|
*/
Route::get('/me/board-activities', [UserActivityController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.board-activities.index');

Route::get('/me/activity-stats', [UserActivityController::class, 'stats'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.activity-stats');

Route::get('/me/my-comments', [UserActivityController::class, 'myComments'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.my-comments.index');

/*
|--------------------------------------------------------------------------
| User Report Routes (사용자 신고) - 회원 전용
|--------------------------------------------------------------------------
|
| 로그인한 회원만 접근 가능한 신고 API입니다.
| - 신고는 회원만 가능하므로 auth:sanctum 미들웨어 유지
|
| 최종 URL 예시: /api/modules/sirsoft-board/boards/{slug}/posts/{id}/reports
| 최종 Name 예시: api.modules.sirsoft-board.boards.posts.reports.store
|
*/
Route::prefix('boards/{slug}')->middleware(['throttle:600,1', 'auth:sanctum'])->name('boards.')->group(function () {
    // 게시글 신고
    Route::post('/posts/{postId}/reports', [UserReportController::class, 'storePostReport'])
        ->name('posts.reports.store');

    // 댓글 신고
    Route::post('/comments/{commentId}/reports', [UserReportController::class, 'storeCommentReport'])
        ->name('comments.reports.store');
});

/*
|--------------------------------------------------------------------------
| User Attachment Routes (사용자 첨부파일) - 회원/비회원 모두 접근 가능
|--------------------------------------------------------------------------
|
| 첨부파일 업로드/삭제 권한은 permission 미들웨어에서 체크합니다.
| 다운로드는 공개 API 섹션 참조.
|
| 최종 URL 예시: /api/modules/sirsoft-board/boards/{slug}/attachments
| 최종 Name 예시: api.modules.sirsoft-board.boards.attachments.upload
|
*/
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('boards/{slug}/attachments')->middleware(['optional.sanctum', 'throttle:600,1'])->name('boards.attachments.')->group(function () {
    // 첨부파일 업로드 (임시 업로드 포함)
    Route::post('/', [UserAttachmentController::class, 'upload'])
        ->middleware('permission:user,sirsoft-board.{slug}.attachments.upload')
        ->name('upload');

    // 첨부파일 순서 변경 (FileUploader 컴포넌트가 PATCH 메서드 사용)
    Route::patch('/reorder', [UserAttachmentController::class, 'reorder'])
        ->middleware('permission:user,sirsoft-board.{slug}.attachments.upload')
        ->name('reorder');

    // 첨부파일 삭제
    Route::delete('/{id}', [UserAttachmentController::class, 'destroy'])
        ->middleware('permission:user,sirsoft-board.{slug}.attachments.upload')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| User Public Posts Routes (사용자 공개 게시글) - 인증 불필요
|--------------------------------------------------------------------------
|
| 특정 사용자의 공개 게시글을 조회하는 API입니다.
| 인증 없이 접근 가능합니다. 공개 프로필 페이지에서 사용됩니다.
|
| 최종 URL 예시: /api/modules/sirsoft-board/users/{user:uuid}/posts
| 최종 Name 예시: api.modules.sirsoft-board.users.posts.index
|
*/
Route::prefix('users/{user:uuid}')->middleware(['optional.sanctum', 'throttle:600,1'])->name('users.')->group(function () {
    // 사용자의 공개 게시글 목록
    Route::get('/posts', [UserPostController::class, 'userPosts'])
        ->name('posts.index');

    // 사용자의 게시글 통계 (프로필 페이지용)
    Route::get('/posts/stats', [UserPostController::class, 'userPostsStats'])
        ->name('posts.stats');
});
