<?php

use App\Support\UmaskHelper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Group-Shared umask Alignment
|--------------------------------------------------------------------------
|
| 운영자가 `storage/` 를 그룹 쓰기(g+w) 로 설정한 경우, Laravel 이 런타임에
| 생성하는 새 디렉토리(예: `storage/framework/cache/data/<hash>`) 도 g+w 를
| 유지하도록 프로세스 umask 를 0002 로 조정한다. 이 동조가 없으면 기본 umask 022
| 로 인해 `0755` (drwxr-xr-x) 로 만들어져 php-fpm(www-data) 그룹 쓰기가 실패한다.
|
| `storage/` 에 g-w 가 설정된 경우(일부 공유 호스팅 특수 환경) 에는 운영자
| 의도를 존중하여 umask 를 건드리지 않는다. `umask` 함수 자체가 비활성인
| 환경에서도 조용히 스킵한다. 상세: App\Support\UmaskHelper.
|
*/
UmaskHelper::configureForGroupSharing(dirname(__DIR__).'/storage');

/*
|--------------------------------------------------------------------------
| Disable putenv() for Thread Safety
|--------------------------------------------------------------------------
|
| Apache mod_php 환경에서 동일 프로세스 내 여러 요청이 동시에 처리될 때,
| putenv()/getenv()는 thread-safe하지 않아 환경변수가 다른 요청에 의해
| 덮어씌워지는 문제가 발생합니다.
|
| 이 설정은 Dotenv가 putenv()를 사용하지 않고 $_ENV/$_SERVER만 사용하도록 합니다.
|
*/
Env::disablePutenv();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // DevTools 라우트 (디버그 모드에서만 활성화)
            Route::middleware('api')
                ->group(base_path('routes/devtools.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel 기본 메인터넌스 미들웨어 제거 (커스텀 MaintenanceModePage로 대체)
        $middleware->remove(\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class);

        // Maintenance 모드 전용 페이지 미들웨어 (인증 불필요, 최우선 실행)
        $middleware->prepend(\App\Http\Middleware\MaintenanceModePage::class);

        // Laravel Boost browser-logs를 G7 디버그 모드와 연동
        // InjectBoost 미들웨어보다 먼저 실행되어야 하므로 최상단에 추가
        $middleware->prependToGroup('web', \App\Http\Middleware\SyncBoostWithDebugMode::class);

        // SetLocale, SetTimezone은 인증 후 실행되어야 사용자 설정을 읽을 수 있음
        $localeTimezoneMiddleware = [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SetTimezone::class,
        ];
        $middleware->appendToGroup('web', $localeTimezoneMiddleware);
        $middleware->appendToGroup('api', $localeTimezoneMiddleware);

        // Gzip 압축 미들웨어 (웹서버 설정 없이 애플리케이션 레벨에서 압축)
        $middleware->append(\App\Http\Middleware\GzipEncodeResponse::class);

        // 토큰 만료 시간 슬라이딩 갱신 미들웨어 (API 요청 시 토큰 만료 시간 자동 연장)
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\RefreshTokenExpiration::class,
        ]);

        // 권한 관련 미들웨어 등록
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'check.user_status' => \App\Http\Middleware\CheckUserStatus::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'template.dependencies' => \App\Http\Middleware\CheckTemplateDependencies::class,
            'optional.sanctum' => \App\Http\Middleware\OptionalSanctumMiddleware::class,
            'start.api.session' => \App\Http\Middleware\StartApiSession::class,
            'seo' => \App\Seo\SeoMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 401 응답 시 잔존 세션 쿠키 정리
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.unauthenticated')], 401)
                    ->withCookie(cookie()->forget(config('session.cookie')));
            }
        });
    })->create();

return $app;
