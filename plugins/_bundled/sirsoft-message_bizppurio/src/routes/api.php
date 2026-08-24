<?php

use App\Http\Middleware\EnforceIdentityPolicy;
use App\Http\Middleware\RefreshTokenExpiration;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\BizppurioTemplateController;
use Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\DispatchResultController;
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
    | 알림톡 작성 모달 참조 조회 (#597) — 발신프로필·카테고리
    |----------------------------------------------------------------------
    |
    | 알림톡 템플릿 작성 모달의 발신프로필 셀렉트·카테고리 셀렉트가 소비한다.
    | 실시간 목록/상세 화면(구 Phase 5)은 DB 기반 라이프사이클로 대체되어 제거됐다.
    */
    Route::prefix('alimtalk-templates')->name('alimtalk-templates.')->group(function () {
        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')->group(function () {
            Route::get('/categories', [AlimtalkTemplateController::class, 'categories'])->name('categories');
            Route::get('/profiles', [AlimtalkTemplateController::class, 'profiles'])->name('profiles');
        });
    });

    /*
    |----------------------------------------------------------------------
    | 비즈뿌리오 알림 템플릿 라이프사이클 (#597 §3.2)
    |----------------------------------------------------------------------
    |
    | 시스템 등록(draft) → 검수 신청(requested) → 승인(approved) 후 발송 활성화.
    | 발송 판정은 DB(bizppurio_templates)가 유일한 근거이며, 카카오 상태 정합은
    | 스케줄러(bizppurio:sync-template-status)와 수동 sync 가 유지한다.
    |
    | 조회(index/map/show) = messaging.view / 그 외 변경 = messaging.manage.
    | 구체 경로(map/image)를 {id} 보다 먼저 두어 라우트 충돌을 막는다.
    */
    Route::prefix('templates')->name('templates.')->group(function () {
        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.view')->group(function () {
            Route::get('/', [BizppurioTemplateController::class, 'index'])->name('index');
            Route::get('/map', [BizppurioTemplateController::class, 'map'])->name('map');
            Route::get('/{id}', [BizppurioTemplateController::class, 'show'])->whereNumber('id')->name('show');
        });

        Route::middleware('permission:admin,sirsoft-message_bizppurio.messaging.manage')->group(function () {
            Route::post('/', [BizppurioTemplateController::class, 'store'])->name('store');
            Route::post('/image', [BizppurioTemplateController::class, 'uploadImage'])->name('image');
            Route::put('/delivery/{notificationType}', [BizppurioTemplateController::class, 'upsertDelivery'])->name('delivery');
            Route::put('/{id}', [BizppurioTemplateController::class, 'update'])->whereNumber('id')->name('update');
            Route::post('/{id}/request', [BizppurioTemplateController::class, 'requestInspection'])->whereNumber('id')->name('request');
            Route::post('/{id}/cancel-request', [BizppurioTemplateController::class, 'cancelRequest'])->whereNumber('id')->name('cancel-request');
            Route::post('/{id}/cancel-approval', [BizppurioTemplateController::class, 'cancelApproval'])->whereNumber('id')->name('cancel-approval');
            Route::post('/{id}/release', [BizppurioTemplateController::class, 'release'])->whereNumber('id')->name('release');
            Route::post('/{id}/sync', [BizppurioTemplateController::class, 'sync'])->whereNumber('id')->name('sync');
            Route::delete('/{id}', [BizppurioTemplateController::class, 'destroy'])->whereNumber('id')->name('destroy');
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
