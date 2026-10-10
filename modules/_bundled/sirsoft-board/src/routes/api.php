<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController as AdminAttachmentController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController as AdminCommentController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\DashboardController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\IdentityPolicyController as AdminIdentityPolicyController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\PostController as AdminPostController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\PostScrapController as AdminPostScrapController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionController as AdminReactionController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionTypeController as AdminReactionTypeController;
use Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController as AdminReportController;
use Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController as UserAttachmentController;
use Modules\Sirsoft\Board\Http\Controllers\User\BoardController as UserBoardController;
use Modules\Sirsoft\Board\Http\Controllers\User\CommentController as UserCommentController;
use Modules\Sirsoft\Board\Http\Controllers\User\PostController as UserPostController;
use Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController as UserPostScrapController;
use Modules\Sirsoft\Board\Http\Controllers\User\ReactionController as UserReactionController;
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
    // 기존 게시판 설정을 덮어쓰므로 환경설정 수정 + 게시판 수정 권한을 모두 요구한다(제품 결정 2026-10-09, 기본 AND)
    Route::post('settings/bulk-apply', [BoardSettingsController::class, 'bulkApply'])
        ->middleware('permission:admin,sirsoft-board.settings.update|sirsoft-board.boards.update')
        ->name('admin.settings.bulk-apply');

    // 설정 캐시 초기화 (와일드카드 라우트보다 먼저 등록)
    Route::post('settings/clear-cache', [BoardSettingsController::class, 'clearCache'])
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.settings.clear-cache');

    // 개인정보 보유기간 처리 대상 건수 미리보기 (와일드카드 라우트보다 먼저 등록, 이슈 #636)
    Route::get('settings/privacy-retention-preview', [BoardSettingsController::class, 'privacyRetentionPreview'])
        ->middleware('permission:admin,sirsoft-board.settings.read')
        ->name('admin.settings.privacy-retention-preview');

    // 환경설정 카테고리별 조회 (와일드카드 라우트 - 마지막에 배치)
    Route::get('settings/{category}', [BoardSettingsController::class, 'show'])
        ->middleware('permission:admin,sirsoft-board.settings.read')
        ->name('admin.settings.show');

    // 환경설정 「본인인증 정책」 탭 — 코어 정책 표에서 게시판 몫(source_identifier=sirsoft-board)만 다룬다.
    // 조회는 identity.policies.read, 추가·수정·삭제는 identity.policies.update(응답 abilities 와 같은 권한).
    // 게시판 몫이 아닌 정책 ID 는 404 로 답한다.
    Route::get('identity-policies', [AdminIdentityPolicyController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.identity.policies.read')
        ->name('admin.identity-policies.index');
    Route::post('identity-policies', [AdminIdentityPolicyController::class, 'store'])
        ->middleware('permission:admin,sirsoft-board.identity.policies.update')
        ->name('admin.identity-policies.store');
    Route::put('identity-policies/{id}', [AdminIdentityPolicyController::class, 'update'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-board.identity.policies.update')
        ->name('admin.identity-policies.update');
    Route::delete('identity-policies/{id}', [AdminIdentityPolicyController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-board.identity.policies.update')
        ->name('admin.identity-policies.destroy');

    // 대시보드 - 오늘 새 글/댓글 현황 (형제 settings 라우트와 동일하게 명시적 permission 가드)
    // 반응 유형 목록 (게시판 설정 체크박스 옵션 소스, 확정 02·03)
    // 게시판 폼의 「사용 반응 유형」 선택지로도 쓰이므로 게시판 조회 권한으로도 읽는다(OR) — 없으면 폼의 체크박스가 비었다
    Route::get('reaction-types', [AdminReactionTypeController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.settings.read|sirsoft-board.boards.read,false')
        ->name('admin.reaction-types.index');

    // 반응 유형 아이콘 이미지 업로드/삭제 (환경설정 인라인 편집기의 행별 업로더)
    Route::post('reaction-types/icons', [AdminReactionTypeController::class, 'uploadIcon'])
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.reaction-types.icons.upload');
    Route::delete('reaction-types/icons/{attachmentId}', [AdminReactionTypeController::class, 'destroyIcon'])
        ->whereNumber('attachmentId')
        ->middleware('permission:admin,sirsoft-board.settings.update')
        ->name('admin.reaction-types.icons.destroy');

    // 대시보드 - 오늘 새 글/댓글 현황 (진입 가드는 코어 core.dashboard.read + admin)
    Route::get('dashboard/overview', [DashboardController::class, 'overview'])
        ->middleware('permission:admin,sirsoft-board.dashboard.view')
        ->name('admin.dashboard.overview');

    // 대시보드 - 7일 추세 그래프 (막대 + 합계 + 변화율)
    Route::get('dashboard/post-graph', [DashboardController::class, 'postGraph'])
        ->middleware('permission:admin,sirsoft-board.dashboard.view')
        ->name('admin.dashboard.post-graph');

    // 대시보드 - 최신 게시글
    Route::get('dashboard/recent-posts', [DashboardController::class, 'recentPosts'])
        ->middleware('permission:admin,sirsoft-board.dashboard.view')
        ->name('admin.dashboard.recent-posts');

    // 대시보드 - 미처리 신고
    Route::get('dashboard/pending-reports', [DashboardController::class, 'pendingReports'])
        ->middleware('permission:admin,sirsoft-board.dashboard.view')
        ->name('admin.dashboard.pending-reports');

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
    // 목록은 환경설정 「기본 유형」 선택지로도 쓰여 환경설정 조회 권한으로도 읽는다(OR). 추가·수정·삭제는 그대로 등록 권한
    Route::get('/', [BoardTypeController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.boards.create|sirsoft-board.settings.read,false')
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

    // 게시판 관리자·스텝 일괄 지정 (와일드카드 라우트보다 먼저 등록)
    Route::post('/bulk-members', [BoardController::class, 'bulkMembers'])
        ->middleware('permission:admin,sirsoft-board.boards.update')
        ->name('bulk-members');

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
| Admin Scrap Routes (스크랩 관리)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/scraps
| 최종 Name 예시: api.modules.sirsoft-board.admin.scraps.index
|
| 스크랩은 회원 소유 데이터라 게시판 경계를 넘어 조회되므로 게시판별 권한이 아니라
| 모듈 레벨 권한(sirsoft-board.scraps.*)을 쓴다 (신고 관리와 동형).
|
*/
Route::prefix('admin/scraps')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.scraps.')->group(function () {
    // 일괄 삭제 — /{id} 보다 먼저 선언해야 'bulk' 가 id 로 잡히지 않는다
    Route::delete('/bulk', [AdminPostScrapController::class, 'bulkDestroy'])
        ->middleware('permission:admin,sirsoft-board.scraps.manage')
        ->name('bulk-destroy');

    // 전 회원 스크랩 목록
    Route::get('/', [AdminPostScrapController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.scraps.view')
        ->name('index');

    // 스크랩 개별 삭제 (소유자 무관)
    Route::delete('/{id}', [AdminPostScrapController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-board.scraps.manage')
        ->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Reaction Routes (반응 관리)
|--------------------------------------------------------------------------
|
| 최종 URL 예시: /api/modules/sirsoft-board/admin/reactions
| 최종 Name 예시: api.modules.sirsoft-board.admin.reactions.index
|
| 반응은 회원 소유 데이터라 게시판 경계를 넘어 조회되므로 게시판별 권한이 아니라
| 모듈 레벨 권한(sirsoft-board.reactions.*)을 쓴다 (스크랩 관리와 동형).
|
*/
Route::prefix('admin/reactions')->middleware(['auth:sanctum', 'admin', 'throttle:600,1'])->name('admin.reactions.')->group(function () {
    // 일괄 삭제 — /{id} 보다 먼저 선언해야 'bulk' 가 id 로 잡히지 않는다
    Route::delete('/bulk', [AdminReactionController::class, 'bulkDestroy'])
        ->middleware('permission:admin,sirsoft-board.reactions.manage')
        ->name('bulk-destroy');

    // 전 회원 반응 목록
    Route::get('/', [AdminReactionController::class, 'index'])
        ->middleware('permission:admin,sirsoft-board.reactions.view')
        ->name('index');

    // 반응 개별 삭제 (소유자 무관)
    Route::delete('/{id}', [AdminReactionController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-board.reactions.manage')
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

    // 일괄 처리 3종 — /{id} 라우트들보다 **먼저** 선언해야 'bulk' 가 id 로 잡히지 않는다.
    // 셋 다 admin.manage 를 요구한다(단건 삭제의 「본인 글이면 posts.write」 완화를 일괄에 적용하면
    // 한 요청으로 남의 글까지 섞여 들어갈 수 있다).
    Route::delete('/bulk', [AdminPostController::class, 'bulkDestroy'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('bulk-destroy');

    Route::patch('/bulk-blind', [AdminPostController::class, 'bulkBlind'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('bulk-blind');

    Route::patch('/bulk-restore', [AdminPostController::class, 'bulkRestore'])
        ->middleware('permission:admin,sirsoft-board.{slug}.admin.manage')
        ->name('bulk-restore');

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

    // 게시글 수정 (작성자 posts.write 또는 게시판 관리 manager)
    Route::put('/{id}', [UserPostController::class, 'update'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.write|sirsoft-board.{slug}.manager,false')
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

    // 댓글 수정 (작성자 comments.write 또는 게시판 관리 manager)
    Route::put('/{commentId}', [UserCommentController::class, 'update'])
        ->middleware('permission:user,sirsoft-board.{slug}.comments.write|sirsoft-board.{slug}.manager,false')
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
| 최종 URL 예시: /api/modules/sirsoft-board/me/board-activities
| 최종 Name 예시: api.modules.sirsoft-board.me.board-activities.index
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

// 마이페이지 스크랩 목록 (최근 스크랩순)
Route::get('/me/scraps', [UserPostScrapController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.scraps.index');

// 마이페이지 스크랩 선택 해제 (본문 JSON 의 ids[], 본인 소유만) — /{id} 보다 먼저 선언한다
Route::delete('/me/scraps', [UserPostScrapController::class, 'bulkDestroy'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.scraps.bulk-destroy');

// 마이페이지 스크랩 개별 삭제 (레코드 ID 기준, 본인 소유만)
Route::delete('/me/scraps/{id}', [UserPostScrapController::class, 'destroy'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.scraps.destroy');

// 마이페이지 「내 반응」 목록 (최근 반응순)
Route::get('/me/reactions', [UserReactionController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.reactions.index');

// 마이페이지 반응 선택 해제 (본문 JSON 의 ids[], 본인 소유만) — /{id} 보다 먼저 선언한다
Route::delete('/me/reactions', [UserReactionController::class, 'bulkDestroy'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.reactions.bulk-destroy');

// 마이페이지 반응 개별 해제 (반응 ID 기준, 본인 소유만)
Route::delete('/me/reactions/{id}', [UserReactionController::class, 'destroy'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.reactions.destroy');

// 마이페이지 「내 신고」 목록 (본인이 남긴 신고 로그)
Route::get('/me/reports', [UserReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.reports.index');

// 마이페이지 신고 취소 (접수 상태만)
Route::delete('/me/reports/{reportLogId}', [UserReportController::class, 'cancel'])
    ->whereNumber('reportLogId')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('me.reports.cancel');

/*
|--------------------------------------------------------------------------
| User Report Routes (사용자 신고) - 회원 전용
|--------------------------------------------------------------------------
|
| 로그인한 회원만 접근 가능한 신고 API입니다.
| - 신고는 회원만 가능하므로 auth:sanctum 미들웨어 유지
| - 글 대상 동작은 그 게시판 글 읽기 권한(posts.read), 댓글 대상 동작은 글·댓글 읽기 권한을 함께 요구한다
|   (읽을 수 없는 글·댓글을 신고·스크랩·반응하지 못하게)
|
| 최종 URL 예시: /api/modules/sirsoft-board/boards/{slug}/posts/{id}/reports
| 최종 Name 예시: api.modules.sirsoft-board.boards.posts.reports.store
|
*/
Route::prefix('boards/{slug}')->middleware(['throttle:600,1', 'auth:sanctum'])->name('boards.')->group(function () {
    // 게시글 신고
    Route::post('/posts/{postId}/reports', [UserReportController::class, 'storePostReport'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('posts.reports.store');

    // 댓글 신고
    Route::post('/comments/{commentId}/reports', [UserReportController::class, 'storeCommentReport'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read|sirsoft-board.{slug}.comments.read')
        ->name('comments.reports.store');

    // 게시글 스크랩 토글 (상세 버튼 — 있으면 해제, 없으면 등록)
    Route::post('/posts/{postId}/scrap/toggle', [UserPostScrapController::class, 'toggle'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('posts.scrap.toggle');
    // 게시글 반응 (추천/비추천) — 등록/전환/해제 통합 (회원 전용, 확정 07)
    Route::post('/posts/{postId}/react', [UserReactionController::class, 'react'])
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read')
        ->name('posts.react');
    // 댓글 반응 — 게시판이 use_reaction + use_comment_reaction 을 모두 켰을 때만 동작
    Route::post('/comments/{commentId}/react', [UserReactionController::class, 'reactToComment'])
        ->whereNumber('commentId')
        ->middleware('permission:user,sirsoft-board.{slug}.posts.read|sirsoft-board.{slug}.comments.read')
        ->name('comments.react');
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
