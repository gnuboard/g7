<?php

use App\Http\Middleware\EnforceIdentityPolicy;
use App\Http\Middleware\RefreshTokenExpiration;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\DispatchResultController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\NotificationBindingController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\TokenCheckController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\BizppurioWebhookController;

/*
|--------------------------------------------------------------------------
| 비즈뿌리오 메시지 발송 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| 코어 PluginRouteServiceProvider 가 자동 적용:
|  - URL prefix: /api/plugins/sirsoft-message_bizppurio
|  - middleware: api
*/

// 비즈뿌리오 webhook(URL PUSH) 리포트 수신 — 외부 시스템이 호출한다.
//
// api 그룹에 appendToGroup 된 토큰/IDV 미들웨어를 라우트 레벨에서 제외(코어 무수정)하고,
// 인증은 IP 화이트리스트로 대체한다(계획서 D13). 항상 200 응답(멱등).
// IP 화이트리스트는 plugin.php::getMiddleware() 에서 이 라우트명으로 self-gate 선언한다.
Route::post('/webhook', [BizppurioWebhookController::class, 'handle'])
    ->withoutMiddleware([EnforceIdentityPolicy::class, RefreshTokenExpiration::class])
    ->name('webhook');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 리포트 수신 주소 조회 (관리자 설정 페이지 표시용)
    //
    // 비즈뿌리오가 발송 결과(리포트)를 URL PUSH 로 전송할 수신 주소를 사이트 도메인
    // 기준 절대 URL 로 내려준다. url() 헬퍼는 리버스 프록시 뒤에서 요청 host 가
    // localhost 로 떨어질 수 있어, 운영자가 관리하는 config('app.url') 을 신뢰 소스로
    // 삼아 절대화한다. 운영자가 접속한 주소와 무관하게 항상 정식 도메인이 표시된다.
    //
    // ※ 실제 리포트 수신 처리(POST /webhook) 는 Phase 4 에서 구현한다.
    Route::get('/report-url', function () {
        $origin = rtrim((string) config('app.url', 'http://localhost'), '/');

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $origin.'/api/plugins/sirsoft-message_bizppurio/webhook',
            ],
        ]);
    })->middleware('permission:admin,core.plugins.read')
        ->name('report.url');

    // 알림톡 템플릿 화면 준비 상태(값 유무) 조회.
    //
    // 카카오 관리에 필요한 자격증명(api_key·sender_key)은 sensitive:true 라 코어 설정 조회
    // 응답에서 제거된다(보안). 따라서 프론트가 설정 조회 값으로 "설정됨" 을 판정할 수 없어,
    // 값 자체는 노출하지 않고 저장 여부(boolean)만 내려준다. 알림톡 탭 readiness 배너가 소비.
    Route::get('/templates-readiness', function () {
        $settings = app(PluginSettingsService::class)
            ->get('sirsoft-message_bizppurio') ?? [];

        $filled = static fn (string $key): bool => trim((string) ($settings[$key] ?? '')) !== '';

        return response()->json([
            'success' => true,
            'data' => [
                'api_key_set' => $filled('api_key'),
                'sender_key_set' => $filled('sender_key'),
                'ready' => $filled('api_key') && $filled('sender_key'),
            ],
        ]);
    })->middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')
        ->name('templates.readiness');

    /*
    |----------------------------------------------------------------------
    | 알림톡 템플릿 조회 (Phase 5) — 카카오 관리 API(kapi) 실시간 위임
    |----------------------------------------------------------------------
    |
    | 조회 전용(list/detail/categories/profiles) = messaging.view.
    | 템플릿 등록·수정·삭제·검수·상태변경은 비즈뿌리오 콘솔로 위임한다(이 화면은 목록·상태·
    | 내용 조회 + 알림 연결만 담당). 템플릿은 DB 저장 없이 매 요청 실시간 조회하며, 설정
    | 페이지 알림톡 템플릿 탭이 소비한다.
    */
    Route::prefix('alimtalk-templates')->name('alimtalk-templates.')->group(function () {
        // 발송 템플릿 내용 캐시 초기화(수동 갱신) — 카카오에서 템플릿을 방금 바꿔 즉시 반영이
        // 필요할 때 관리자가 캐시를 비운다. 쓰기 동작이므로 messaging.manage 권한. 구체 경로를
        // 먼저 두어 GET /{templateCode} 와 충돌하지 않게 한다.
        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.manage')->group(function () {
            Route::post('/cache/clear', [AlimtalkTemplateController::class, 'clearCache'])->name('cache.clear');
        });

        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')->group(function () {
            Route::get('/', [AlimtalkTemplateController::class, 'index'])->name('index');
            Route::get('/categories', [AlimtalkTemplateController::class, 'categories'])->name('categories');
            Route::get('/profiles', [AlimtalkTemplateController::class, 'profiles'])->name('profiles');
            Route::get('/{templateCode}', [AlimtalkTemplateController::class, 'show'])->name('show');
        });
    });

    /*
    |----------------------------------------------------------------------
    | 알림↔알림톡 템플릿 연동 (Phase 6 재설계) — 코어 편집 모달 전용 칸이 소비
    |----------------------------------------------------------------------
    |
    | 알림톡 탭은 코어 기본 목록·편집 모달을 그대로 쓴다(⚑⚑ 결정 A). 연결 템플릿·SMS 대체
    | 입력은 코어 편집 모달에 얹은 전용 칸(플러그인 overlay)에서 하고, 값을 바꾸면 즉시 이 API
    | 로 저장한다(PO 확정 UX — 별도 저장 버튼 없이 변경 즉시 저장, 코어 저장 버튼 무관 →
    | 코어 템플릿 무오염).
    |
    | 조회(index/approved-templates) = messaging.view / 저장(store) = messaging.manage.
    */
    Route::prefix('notification-bindings')->name('notification-bindings.')->group(function () {
        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')->group(function () {
            Route::get('/', [NotificationBindingController::class, 'index'])->name('index');
            Route::get('/approved-templates', [NotificationBindingController::class, 'approvedTemplates'])->name('approved-templates');
        });

        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.manage')->group(function () {
            Route::post('/', [NotificationBindingController::class, 'store'])->name('store');
        });
    });

    /*
    |----------------------------------------------------------------------
    | 코어 알림 발송 이력 결과 조회 (A-2) — 코어 이력 화면 overlay 가 소비
    |----------------------------------------------------------------------
    |
    | 코어 "알림 발송 이력" 화면에 plugin overlay 로 얹은 결과 컬럼이, 현재 페이지의 코어 알림
    | 로그 id 배열을 이 API 로 넘겨 비즈뿌리오 결과(상태·사유·잔액부족·대체발송)를 한 번에 조회
    | 한다(N+1 회피). 코어 화면·코어 테이블 무수정 — 연결 표식은 우리 dispatch 쪽에만 둔다.
    |
    | 조회 = messaging.view.
    */
    Route::prefix('dispatch-results')->name('dispatch-results.')->group(function () {
        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')->group(function () {
            // 화면 결과 컬럼용: 파라미터 없이 최근 결과 맵을 받아 row.id 로 매칭(타이밍 무관, 기본 경로).
            Route::get('/recent', [DispatchResultController::class, 'recent'])->name('recent');
            // 명시 로그 id 배열 조회(직접 조회용). 화면은 recent 를 쓴다.
            Route::post('/lookup', [DispatchResultController::class, 'lookup'])->name('lookup');
        });
    });

    // 인증 토큰 재검증 — 저장된 계정/비밀번호가 유효한지 그 자리에서 확인한다(설정
    // 화면 "연결 확인" 버튼). 캐시를 거치지 않고 매번 /v1/token 을 새로 호출하므로
    // 조회가 아닌 쓰기 성격에 준해 messaging.manage 권한을 요구한다.
    Route::post('/token/check', [TokenCheckController::class, 'check'])
        ->middleware('permission:admin,sirsoft-message_bizppurio.messaging.manage')
        ->name('token.check');
});
