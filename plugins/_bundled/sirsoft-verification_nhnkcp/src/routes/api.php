<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\MyKcpIdentityShowController;

/*
|--------------------------------------------------------------------------
| NHN KCP 휴대폰 본인확인 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| 코어 PluginRouteServiceProvider 가 자동 적용:
|  - URL prefix: /api/plugins/sirsoft-verification_nhnkcp
|  - middleware: api
*/

// 마이페이지 본인확인 카드 — 본인의 본인확인 정보 (마스킹) 조회
// `check.user_status` 는 파라미터를 받지 않는다 — Laravel 이 초과 인자를 조용히 버리므로
// 종전의 `:active` 는 아무 일도 하지 않으면서 "이 라우트만 active 로 좁혔다" 는 거짓 신호를
// 냈다. 통과 어휘는 미들웨어가 단독으로 소유한다(활성만 통과, 휴면·차단·비활성·탈퇴는 403) —
// 라우트별로 상태 어휘를 갈라 두면 휴면 같은 상태를 더할 때 게이트 강도가 라우트마다 어긋난다.
Route::middleware(['auth:sanctum', 'check.user_status'])
    ->get('/me/identity/nhnkcp', [MyKcpIdentityShowController::class, 'show'])
    ->name('me.identity.nhnkcp.show');
