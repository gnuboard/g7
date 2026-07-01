<?php

use App\Http\Controllers\Api\Public\SitemapController;
use App\Seo\TemplateRouteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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
Route::prefix('admin')
    ->middleware('template.dependencies:admin')
    ->group(function () {
        Route::get('/{any?}', function (Request $request) {
            // 미등록 경로는 SPA 셸 본문 + HTTP 404 (soft 404 방지 — 공개#47).
            // 본문은 그대로라 클라이언트가 404 레이아웃을 렌더하고, 봇은 404 색인 제외.
            $path = '/'.ltrim($request->getPathInfo(), '/');
            if (! app(TemplateRouteResolver::class)->routeExists($path, 'admin')) {
                return response(view('admin'), 404);
            }

            return view('admin');
        })->where('any', '(?!.*\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)).*');
    });

// Sitemap XML 라우트
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('web.sitemap');

// User 라우트 - user 템플릿 의존성 검증 + SEO 봇 감지
Route::middleware(['template.dependencies:user', 'seo'])
    ->group(function () {
        Route::get('/{any?}', function (Request $request) {
            // 미등록 경로는 SPA 셸 본문 + HTTP 404 (soft 404 방지 — 공개#47).
            $path = '/'.ltrim($request->getPathInfo(), '/');
            if (! app(TemplateRouteResolver::class)->routeExists($path, 'user')) {
                return response(view('app'), 404);
            }

            return view('app');
        })->where('any', '(?!admin)(?!api)(?!plugins)(?!.*\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)).*');
    });
