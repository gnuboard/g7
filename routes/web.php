<?php

use App\Http\Controllers\Api\Public\SitemapController;
use App\Seo\TemplateRouteResolver;
use App\Support\Custom\CustomRoutes;
use App\Support\StaticExtensionPattern;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// 개발자 라우트(custom 예약 자리, #792) — `routes/custom.php` 가 있으면 맨 앞에서 등록한다.
// 아래 G7 화면 catch-all(`/admin/{any?}`·`/{any?}`)보다 먼저 등록돼야 라라벨 라우트 순서대로 개발자 화면이 이긴다.
// 이 파일은 이미 web 그룹이라 미들웨어 묶음 `custom.screen` 만 더 씌운다. 코어 예약 주소를 가리는 라우트는 등록하지 않는다.
CustomRoutes::load(app('router'));

// 개발용 라우트 - 디버그 모드 + 관리자 인증 필수
Route::get('/dev', function () {
    // 1. 디버그 모드 확인
    if (! config('app.debug')) {
        abort(404);
    }

    // 2. 관리자 인증 확인 (세션 기반 - stateful 미들웨어로 로그인 시 세션 생성)
    $user = Auth::guard('web')->user();
    if (! $user || ! $user->is_super) {
        abort(403, '관리자 권한이 필요합니다.');
    }

    return view('dev-dashboard');
})->name('web.dev');

// Admin 라우트 - admin 템플릿 의존성 검증
// canonical.path 는 끝·연속 슬래시 주소를 정규형으로 301 — 그 뒤의 라우트 판정은 정규형만 받는다(공개#156).
Route::prefix('admin')
    ->middleware(['canonical.host', 'canonical.path', 'template.dependencies:admin'])
    ->group(function () {
        Route::get('/{any?}', function (Request $request) {
            // 미등록 경로는 SPA 셸 본문 + HTTP 404 (soft 404 방지 — 공개#47).
            // 본문은 그대로라 클라이언트가 404 레이아웃을 렌더하고, 봇은 404 색인 제외.
            $path = '/'.ltrim($request->getPathInfo(), '/');
            if (! app(TemplateRouteResolver::class)->routeExists($path, 'admin')) {
                return response(view('admin'), 404);
            }

            return view('admin');
        })->where('any', StaticExtensionPattern::catchAllExclusion());
    });

// Sitemap XML 라우트
// 자식 경로는 SitemapFileStore::childUrl() 이 sitemapindex 의 <loc> 에 기록하는 값과 일치해야 한다.
// gzip 여부는 manifest 가 결정하므로 .xml / .xml.gz 를 같은 액션에서 처리한다.
//
// `dualSuffix` 가 아니라 `dualSuffixSegment` 를 쓴다 — 접미사를 떼면 `/sitemap` 이라는
// **맨 이름**을 이 라우트가 선점해 그 주소를 쓰던 사용자 페이지가 도달 불가가 되고,
// `.xml` 과 `.xml.gz` 의 확장자 없는 형태가 서로 충돌한다. 세그먼트로 내리면 둘 다
// 생기지 않는다 (7차 G3 라운드 B-6).
//
// 세션·쿠키 미들웨어를 뺀다 — 이 파일은 `web` 그룹이라 `StartSession` 이 매 응답에 세션
// 쿠키를 싣는데, 사이트맵 응답은 `public, max-age` 다. `Set-Cookie` 가 실린 `public` 응답은
// 공유 캐시가 저장하지 않거나(RFC 9111 §3.5 — 캐시마다 다르다) 세션 쿠키를 다른 방문자에게
// 재사용한다. 봇이 받는 응답이라 세션이 있을 이유도 없다. `ShareErrorsFromSession` 은 세션
// 없이는 예외를 던지므로 함께 뺀다 (16차 G3 라운드 E-5).
$sitemapWithoutSession = [
    StartSession::class,
    ShareErrorsFromSession::class,
    AddQueuedCookiesToResponse::class,
    ValidateCsrfToken::class,
];
Route::dualSuffixSegment('/sitemap', 'xml', [SitemapController::class, 'index'])->withoutMiddleware($sitemapWithoutSession)->name('web.sitemap');
Route::dualSuffixSegment('/sitemap-{n}', 'xml', [SitemapController::class, 'child'])->withoutMiddleware($sitemapWithoutSession)->whereNumber('n')->name('web.sitemap.child');
// gzip 형태는 확장자 없는 짝을 따로 만들지 않는다 — `child()` 는 URL 의 접미사를 보지 않고
// manifest 로 gzip 여부를 정하므로, 바로 위 줄이 등록한 `/sitemap-{n}/xml` 이 gzip 세트에도
// 그대로 도달한다. 짝을 하나 더 만들면 같은 응답을 내는 제3의 주소만 늘어난다.
// audit:allow dynamic-route-static-extension 확장자 없는 짝은 위 줄의 `/sitemap-{n}/xml` 이 소유한다
Route::get('/sitemap-{n}.xml.gz', [SitemapController::class, 'child'])->withoutMiddleware($sitemapWithoutSession)->whereNumber('n')->name('web.sitemap.child.gz');

// User 라우트 - user 템플릿 의존성 검증 + SEO 봇 감지 + 방문자 통계
// 순서가 계약이다: 봇 기록은 SEO 앞에 둬야 그 봇 판정과 캐시 상태를 함께 읽을 수 있고,
// 서버 폴백은 SEO 뒤에 둬야 봇으로 판정된 요청을 사람 방문으로 세지 않는다.
// canonical.path 는 봇 기록·SEO·통계보다 앞에서 301 로 응답해야 끝·연속 슬래시 주소가 그쪽에 닿지 않는다(공개#156).
Route::middleware(['canonical.host', 'canonical.path', 'template.dependencies:user', 'analytics.bots', 'seo', 'analytics.fallback'])
    ->group(function () {
        // `where('any', ...)` 의 예약 프리픽스는 전수 제외한다 — catch-all 이 GET 을 먼저
        // 삼키면 그 뒤에 등록된 라우트가 도달 불가가 되는데, 예외도 404 도 아닌 **SPA 셸 200**
        // 이라 요청한 쪽은 무엇이 잘못됐는지 알 방법이 없다. `_boost`(DevTools) 가 실제로 이렇게
        // 가려져 있었고, "우연히 안전" 한 상태를 게이트 대신 의지하게 만들었다(공개#128).
        // `modules` 는 향후 모듈이 web GET 라우트를 소유할 때 같은 일이 반복되지 않도록 미리
        // 비워 둔다. `admin` catch-all(위)은 `admin/` 스코프 한정이라 대상이 아니다.
        Route::get('/{any?}', function (Request $request) {
            // 미등록 경로는 SPA 셸 본문 + HTTP 404 (soft 404 방지 — 공개#47).
            $path = '/'.ltrim($request->getPathInfo(), '/');
            if (! app(TemplateRouteResolver::class)->routeExists($path, 'user')) {
                return response(view('app'), 404);
            }

            return view('app');
        })->where('any', '(?!admin)(?!api)(?!plugins)(?!_boost)(?!modules)'.StaticExtensionPattern::catchAllExclusion());
    });
