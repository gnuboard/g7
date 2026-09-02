<?php

use App\Exceptions\CannotModifyProtectedRoleException;
use App\Exceptions\CannotModifySuperAdminException;
use App\Exceptions\CoreVersionMismatchException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Exceptions\PermissionEscalationException;
use App\Helpers\ResponseHelper;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckTemplateDependencies;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\DatabaseCredentialGuard;
use App\Http\Middleware\EnforceIdentityPolicy;
use App\Http\Middleware\EnsureDebugMode;
use App\Http\Middleware\ExtensionMiddlewareGate;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Middleware\MaintenanceModePage;
use App\Http\Middleware\OptionalSanctumMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RefreshTokenExpiration;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use App\Http\Middleware\StartApiSession;
use App\Http\Middleware\SyncBoostWithDebugMode;
use App\Seo\SeoMiddleware;
use App\Support\UmaskHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
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
        health: '/up',
        then: function () {
            // DevTools 라우트 — 디버그 모드 게이트를 **그룹 단위**로 건다.
            //
            // 이 한 줄이 `routes/devtools.php` 의 8개 라우트(GET·POST·DELETE) 전부를 덮는다.
            // 게이트를 핸들러 안에 흩어 두면 라우트를 추가할 때마다 함께 적어야 하고, 빠뜨려도
            // 예외·로그가 남지 않아 그 라우트만 조용히 열린다(공개#128 — DELETE clear 가
            // production 에서 미인증 200 으로 storage/debug-dump 를 지웠다).
            //
            // `api` 그룹 전체가 아니라 devtools 래퍼에만 붙이는 것이 유일한 안전 지점이다.
            Route::middleware(['api', 'debug.gate'])
                ->group(base_path('routes/devtools.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel 기본 메인터넌스 미들웨어 제거 (커스텀 MaintenanceModePage로 대체)
        $middleware->remove(PreventRequestsDuringMaintenance::class);

        // DB 자격증명 가드 (인증 불필요) — 최고권한 계정/빈 사용자명으로 인한
        // 확장 로딩 스킵을 전용 에러 페이지로 드러낸다.
        //
        // prepend() 는 새 항목을 기존 prepends 앞에 넣으므로, 아래 MaintenanceModePage
        // prepend 가 뒤따르면 최종 순서는 [MaintenanceModePage, DatabaseCredentialGuard] 가
        // 된다. 점검 중에는 점검 페이지가 우선해야 하므로 이 호출 순서를 유지한다.
        $middleware->prepend(DatabaseCredentialGuard::class);

        // Maintenance 모드 전용 페이지 미들웨어 (인증 불필요, 최우선 실행)
        $middleware->prepend(MaintenanceModePage::class);

        // Laravel Boost browser-logs를 G7 디버그 모드와 연동
        // InjectBoost 미들웨어보다 먼저 실행되어야 하므로 최상단에 추가
        $middleware->prependToGroup('web', SyncBoostWithDebugMode::class);

        // SetLocale, SetTimezone은 인증 후 실행되어야 사용자 설정을 읽을 수 있음
        $localeTimezoneMiddleware = [
            SetLocale::class,
            SetTimezone::class,
        ];
        $middleware->appendToGroup('web', $localeTimezoneMiddleware);
        $middleware->appendToGroup('api', $localeTimezoneMiddleware);

        // Gzip 압축 미들웨어 (웹서버 설정 없이 애플리케이션 레벨에서 압축)
        $middleware->append(GzipEncodeResponse::class);

        // 토큰 만료 시간 슬라이딩 갱신 미들웨어 (API 요청 시 토큰 만료 시간 자동 연장)
        $middleware->appendToGroup('api', [
            RefreshTokenExpiration::class,
        ]);

        // IDV 정책 자동 매핑 — 모든 API 라우트에서 라우트 이름과 매칭되는 scope='route' 정책을 자동 enforce.
        // 정책 DB 토글만으로 즉시 효과 (라우트 코드 수정 불필요). hook scope 정책의 동적 구독과 동일 모델.
        // 캐시된 인덱스 lookup → 무매칭 라우트는 O(1) 통과.
        $middleware->appendToGroup('api', [
            EnforceIdentityPolicy::class,
        ]);

        // 확장 선언 미들웨어 self-gate 래퍼 — 확장이 getMiddleware() 로 선언한 미들웨어를
        // 요청 시점에 라우트명·URI 매칭으로 실행. web/api × before_core/after_core 로 4회 등록.
        //   - before_core → 그룹 선두(prepend): 코어 전처리(인증·SetLocale·IDV) 전체보다 먼저 실행
        //   - after_core  → 그룹 후미(append): 코어 그룹 미들웨어 뒤에서 실행
        // Kernel 의 prepend/append 는 코어 내부 구현이고, 확장 개발자·게이트 파라미터는
        // before_core/after_core 의도 명칭만 본다. 매칭 확장 미들웨어가 없으면 no-op.
        foreach (['web', 'api'] as $extGroup) {
            $middleware->prependToGroup($extGroup, ExtensionMiddlewareGate::class.":{$extGroup},before_core");
            $middleware->appendToGroup($extGroup, ExtensionMiddlewareGate::class.":{$extGroup},after_core");
        }

        // 비인증 게스트 redirect 경로 가드 (공개#39).
        // Laravel 12 기본값은 `redirectGuestsTo(fn () => route('login'))` 인데,
        // 이 프로젝트엔 'login' 이름 라우트가 없어 Accept 헤더 없는 /api/* 비인증 요청이
        // Authenticate::unauthenticated() 의 redirectTo() 평가 단계에서 route('login')
        // → RouteNotFoundException(HTTP 500) 으로 떨어진다.
        // API 경로는 redirect 대상을 null 로 반환해 RouteNotFoundException 을 차단하고,
        // withExceptions 의 AuthenticationException 핸들러가 401 JSON 으로 응답하도록 위임한다.
        $middleware->redirectGuestsTo(function (Request $request) {
            // API 경로는 redirect 하지 않음 → AuthenticationException 으로 propagate → 401 JSON.
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            // web 경로는 'login' 이름 라우트가 정의된 경우에만 redirect (현재 프로젝트엔 없음 → null).
            return Route::has('login') ? route('login') : null;
        });

        // 권한 관련 미들웨어 등록
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'check.user_status' => CheckUserStatus::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'template.dependencies' => CheckTemplateDependencies::class,
            'optional.sanctum' => OptionalSanctumMiddleware::class,
            'start.api.session' => StartApiSession::class,
            'seo' => SeoMiddleware::class,
            'identity.policy' => EnforceIdentityPolicy::class,
            'extension.middleware' => ExtensionMiddlewareGate::class,
            'debug.gate' => EnsureDebugMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 401 응답 시 잔존 세션 쿠키 정리
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // API 경로는 Accept 헤더 유무와 무관하게 항상 JSON 401 (web redirect/route('login') 폴백 차단).
            // 동일 파일의 IDV/CoreVersionMismatch 핸들러와 같은 가드 패턴.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => __('auth.unauthenticated')], 401)
                    ->withCookie(cookie()->forget(config('session.cookie')));
            }
        });

        // IDV 정책 위반 → HTTP 428 (Precondition Required) + verification payload
        $exceptions->render(function (IdentityVerificationRequiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ResponseHelper::identityRequired($e->getPayload());
            }
        });

        // 등급 상한(rank ceiling) / 권한 상승 차단 예외 → HTTP 403 (KVE-2026-1919).
        // 컨트롤러 catch 가 우선 처리하지만, 새 호출부가 로컬 catch 없이 이 예외를 던져도
        // 조용한 500 대신 일관된 403 이 되도록 전역 렌더러를 둔다(심층 방어). 메시지는 예외
        // 생성자가 lang 키로 설정한 것을 그대로 쓴다 — 컨트롤러 매핑과 동일 문구.
        $exceptions->render(function (PermissionEscalationException|CannotModifySuperAdminException|CannotModifyProtectedRoleException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
        });

        // 확장 코어 버전 호환성 검사 실패 → HTTP 422 + error_code: 'core_version_mismatch'
        // (extension update/activate/recovery 등 사전 검증 진입 지점에서 throw)
        $exceptions->render(function (CoreVersionMismatchException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'core_version_mismatch',
                    'data' => $e->getPayload(),
                ], 422);
            }
        });
    })->create();

return $app;
