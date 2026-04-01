<?php

use App\Http\Controllers\Api\Public\SitemapController;
use Illuminate\Support\Facades\Route;

// 개발용 라우트 - 디버그 모드 + 관리자 인증 필수
Route::get('/dev', function () {
    // 1. 디버그 모드 확인
    if (!config('app.debug')) {
        abort(404);
    }

    // 2. 관리자 인증 확인 (세션 기반 - stateful 미들웨어로 로그인 시 세션 생성)
    $user = \Illuminate\Support\Facades\Auth::guard('web')->user();
    if (!$user || !$user->is_super) {
        abort(403, '관리자 권한이 필요합니다.');
    }

    return view('dev-dashboard');
})->name('web.dev');

// Admin 라우트 - admin 템플릿 의존성 검증
Route::prefix('admin')
    ->middleware('template.dependencies:admin')
    ->group(function () {
        Route::get('/{any?}', function () {
            return view('admin');
        })->where('any', '(?!.*\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)).*');
    });

// Sitemap XML 라우트
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('web.sitemap');

// User 라우트 - user 템플릿 의존성 검증 + SEO 봇 감지
Route::middleware(['template.dependencies:user', 'seo'])
    ->group(function () {
        Route::get('/{any?}', function () {
            return view('app');
        })->where('any', '(?!admin)(?!api)(?!plugins)(?!.*\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)).*');
    });
