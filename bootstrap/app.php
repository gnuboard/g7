<?php

use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Exceptions\Auth\TwoFactorMeansRequiredException;
use App\Exceptions\CannotModifyProtectedRoleException;
use App\Exceptions\CannotModifySuperAdminException;
use App\Exceptions\CoreVersionMismatchException;
use App\Exceptions\ExtensionOperationLockException;
use App\Exceptions\IdentityPolicyProviderUnavailableException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Exceptions\PermissionEscalationException;
use App\Exceptions\PhpExtensionRequirementException;
use App\Helpers\ResponseHelper;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AllowInactiveUser;
use App\Http\Middleware\AllowPendingRequiredFields;
use App\Http\Middleware\Analytics\EnsureArchiveFresh;
use App\Http\Middleware\Analytics\TrackBotHit;
use App\Http\Middleware\Analytics\TrackServerVisit;
use App\Http\Middleware\CheckTemplateDependencies;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\Custom\RequireScreenAdmin;
use App\Http\Middleware\Custom\RequireScreenUser;
use App\Http\Middleware\DatabaseCredentialGuard;
use App\Http\Middleware\DenyOnStageRole;
use App\Http\Middleware\EnforceIdentityPolicy;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureDebugMode;
use App\Http\Middleware\EnsureFieldSetPermission;
use App\Http\Middleware\EnsureRequiredFieldsCompleted;
use App\Http\Middleware\ExtensionMiddlewareGate;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Middleware\MaintenanceModePage;
use App\Http\Middleware\MarkBotRequest;
use App\Http\Middleware\OptionalSanctumMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\RedirectToCanonicalPath;
use App\Http\Middleware\RefreshTokenExpiration;
use App\Http\Middleware\RestrictIpAccess;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\RunScheduleOnVisit;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use App\Http\Middleware\StartApiSession;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Middleware\SyncBoostWithDebugMode;
use App\Seo\SeoMiddleware;
use App\Support\ExecutionEnvironment;
use App\Support\TrustedHosts;
use App\Support\UmaskHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // 코어 CSP 헤더 (#158) — 관리자 환경설정 > 보안의 모드가 끔이 아니면 web HTML 응답에 붙인다.
        // web 그룹이라 플러그인 웹 라우트(결제·본인인증 브리지)도 함께 덮는다. 제외 조건은 미들웨어 주석 참조.
        $middleware->appendToGroup('web', ContentSecurityPolicy::class);

        // IP 접근 제한 (인증 불필요, 전역) — 관리자 셸·코어 API·확장 API 전 표면을 한 판정으로 덮는다.
        // prepend 가 아니라 append 인 이유: 신뢰 프록시는 기본 전역 스택의 TrustProxies 가 적용하므로
        // 그보다 앞에서 돌면 프록시 뒤에서 $request->ip() 가 프록시 주소가 된다. append 도 그룹·라우트
        // 미들웨어보다는 먼저 돌아 withoutMiddleware() 로 빠져나갈 수 없다. Gzip 보다 앞에 두어 차단
        // 응답이 압축 단계를 거치지 않게 한다.
        $middleware->append(RestrictIpAccess::class);

        // 신뢰 Host (전역, 인증 불필요) — 사이트 주소(APP_URL)의 도메인·하위 도메인·www 쌍과 TRUSTED_HOSTS 가
        // 아닌 Host 헤더로 온 요청을 받지 않는다. 위조 Host 로 절대 URL 을 만드는 코드가 남아 있을 때의
        // 마지막 겹이다(보안: 근본 조치는 절대 URL 을 요청 Host 가 아니라 사이트 주소로 만드는 것 —
        // App\Support\SiteUrl). 하위 도메인·포트만 바꾼 Host 는 통과하므로 그 자체로는 충분하지 않다.
        // 설치 전·TRUSTED_HOSTS_ENABLED=false 면 빈 목록(제한 없음), 로컬·테스트는 Laravel 이 건너뛴다.
        $middleware->trustHosts(at: fn (): array => TrustedHosts::patterns(), subdomains: false);

        // Gzip 압축 미들웨어 (웹서버 설정 없이 애플리케이션 레벨에서 압축)
        $middleware->append(GzipEncodeResponse::class);

        // 방문 트리거 예약 실행 폴백 (기본 꺼짐 — D5). 인증을 요구하지 않으므로 전역
        // 등록이 가능하고, 실행은 응답을 보낸 뒤(terminate)에만 일어난다. 설정이 꺼져
        // 있으면 판정 한 번으로 끝나는 no-op 이다.
        $middleware->append(RunScheduleOnVisit::class);

        // 방문자 통계 수집 제외 표식은 암호화하지 않는다.
        // 사용자 화면의 트래커가 `document.cookie` 로 이 값을 직접 읽고 보내기 전에 스스로
        // 멈춘다 — 서버가 암호문으로만 받으면 그 표식을 서버가 되읽지 못해, 트래커를 거치지
        // 않는 경로(서버 폴백·내부 검색 훅)에서 제외가 무시된다. 담긴 값은 「제외됨」이라는
        // 사실 하나뿐이라 위조돼도 잃는 것이 자기 방문 통계뿐이다.
        // 방문자 쿠키(`g7_vid`)도 같은 이유로 암호화하지 않는다. 이 쿠키는 수집 API(api 그룹 —
        // 쿠키 암호화 미들웨어가 없다)가 평문으로 심는데, 화면 요청(web 그룹)이 그것을
        // 복호화하려다 실패해 없는 쿠키로 본다. 그러면 재방문자의 서버 폴백이 임시 키로
        // 따로 방문을 열어 전체 로드마다 방문이 둘로 갈라진다. 담긴 값은 뜻 없는 난수다.
        // 이 지점은 설정이 아직 올라오기 전이라 `config()` 를 쓸 수 없다 — 이름을 그대로 적는다
        // (`config/analytics.php` 의 `cookies.opt_out`·`cookies.visitor` 기본값과 같아야 한다).
        // 로그인 인계 묶음 쿠키(`g7_login_bind`)와 외부 인증 확장의 묶음 쿠키(`g7_sso_bind`)도 암호화하지 않는다 —
        // web 라우트(외부 인증 콜백)가 굽고 api 라우트(인계 교환·동의 완료)가 읽는다. 암호화하면 api 쪽이 암호문을
        // 읽어 늘 불일치가 된다. 값은 sha256 대조용 난수라 평문이어도 잃는 것이 없다.
        $middleware->encryptCookies(except: ['g7_analytics_optout', 'g7_vid', 'g7_login_bind', 'g7_sso_bind']);

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

        // 공통 회원 상태 관문(7.2.0) — 코어·모듈·플러그인 API 전부가 같은 회원 상태 재검사를 받는다. 확장 라우트에 따로 붙이지
        // 않는다. 인증 미들웨어(`auth:sanctum`, `optional.sanctum`) **뒤**에서 돌아야 회원을 안다 — 그룹 미들웨어는 라우트 미들웨어보다
        // 앞에 놓이므로 우선순위 목록으로 순서를 보장한다(인증 → 선택 인증 → 상태 관문).
        $middleware->appendToGroup('api', [
            EnsureActiveUser::class,
        ]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, OptionalSanctumMiddleware::class);
        $middleware->appendToPriorityList(OptionalSanctumMiddleware::class, EnsureActiveUser::class);

        // 추가 정보 입력 요구 관문(7.2.0) — 비어 있는 필수 항목이 있는 회원의 쓰기 요청을 코어, 모듈, 플러그인 API 전부에서 거부한다
        // (403 `required_fields_pending`). 회원 상태 관문 **뒤**에서 돈다 — 차단, 휴면 회원은 상태 사유로 먼저 끊긴다.
        // 통과시킬 라우트에는 표식 `allow.pending_required_fields` 를 붙이고, 확장은 필터 `core.required_fields.filter_exempt_routes` 로 보탠다.
        $middleware->appendToGroup('api', [
            EnsureRequiredFieldsCompleted::class,
        ]);
        $middleware->appendToPriorityList(EnsureActiveUser::class, EnsureRequiredFieldsCompleted::class);

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

        // HTTPS 강제 이동 (#888) — 관리자 환경설정 > 보안의 「HTTPS 강제 적용」이 켜져 있으면 http 요청을 같은 주소의 https 로 보낸다.
        // web·api 그룹 **맨 앞**: prependToGroup 은 나중에 부른 것이 앞에 놓이므로 그룹 prepend 가운데 마지막에 부른다(확장 before_core
        // 게이트보다도 앞). 점검 모드·신뢰 프록시는 전역 미들웨어라 이보다 먼저 판정이 끝난다.
        $middleware->prependToGroup('web', ForceHttps::class);
        $middleware->prependToGroup('api', ForceHttps::class);

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
            'deny.stage' => DenyOnStageRole::class,
            'check.user_status' => CheckUserStatus::class,
            // 공통 회원 상태 관문 통과 표식 — 가입 후 인증 대기 회원의 본인인증 경로(`api.identity.*`)에만 붙인다(7.2.0)
            'allow.inactive_user' => AllowInactiveUser::class,
            // 추가 정보 입력 요구 관문 통과 표식 — 회원이 빠져나갈 길(로그아웃, 내 정보, 본인인증, 탈퇴)과 기술 요청에 붙인다(7.2.0)
            'allow.pending_required_fields' => AllowPendingRequiredFields::class,
            'permission' => PermissionMiddleware::class,
            // 항목 집합의 조회·수정 권한 — 집합마다 권한이 달라 라우트의 {set} 으로 찾아 확인한다(7.2.0)
            'field_set.permission' => EnsureFieldSetPermission::class,
            'role' => RoleMiddleware::class,
            // 최고관리자 전용 — 판정은 회원의 최고관리자 표지(is_super). 코어에 최고관리자 역할은 없다.
            'super.admin' => SuperAdminMiddleware::class,
            'template.dependencies' => CheckTemplateDependencies::class,
            'optional.sanctum' => OptionalSanctumMiddleware::class,
            'start.api.session' => StartApiSession::class,
            'seo' => SeoMiddleware::class,
            // 사이트 주소가 아닌 Host 의 화면 요청 → 사이트 주소로 301 (화면 셸 라우트에만 붙인다)
            'canonical.host' => RedirectToCanonicalHost::class,
            // 끝·연속 슬래시 화면 요청 → 정규형 경로로 301 (canonical.host 바로 뒤, 화면 셸 라우트에만 붙인다)
            'canonical.path' => RedirectToCanonicalPath::class,
            'identity.policy' => EnforceIdentityPolicy::class,
            'extension.middleware' => ExtensionMiddlewareGate::class,
            'debug.gate' => EnsureDebugMode::class,
            // 방문자 통계: 봇 기록은 SEO 앞(그 판정과 캐시 상태를 함께 읽는다),
            // 서버 폴백은 SEO 뒤(봇으로 판정된 요청을 사람 방문으로 세지 않는다).
            'analytics.bots' => TrackBotHit::class,
            'analytics.fallback' => TrackServerVisit::class,
            // 집계 조회 라우트 앞에서 오래된 집계를 다시 계산하고 응답에 신선도를 싣는다(권한 미들웨어 뒤).
            'analytics.archive_on_view' => EnsureArchiveFresh::class,
            // 개발자 화면(custom 예약 자리, #792) 회원 필수 / 관리자 필수 — routes/custom.php 에서 라우트에 붙인다
            'custom.auth' => RequireScreenUser::class,
            'custom.admin' => RequireScreenAdmin::class,
        ]);

        // 개발자 화면(routes/custom.php, #792) 묶음 — G7 화면 catch-all 과 같은 순서 계약을 따른다:
        // 정식 주소 이동(호스트 → 끝·연속 슬래시) → 봇 표식(SEO 렌더 없이 표식만) → 봇 기록 → 서버 폴백 집계(봇 표식이 있으면 세지 않음).
        // 사용자 템플릿 의존성 검사(template.dependencies)는 넣지 않는다 — 템플릿이 없어도 개발자 화면은 뜬다.
        $middleware->group('custom.screen', [
            'canonical.host',
            'canonical.path',
            MarkBotRequest::class,
            'analytics.bots',
            'analytics.fallback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 경로는 Accept 헤더와 무관하게 항상 JSON 으로 렌더링한다 — 기본 검증 실패 처리는 JSON 을
        // 기대하지 않는 요청을 previous()(Referer → 세션 → 요청 Host)로 302 이동시킨다.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        // API 401 응답 시 잔존 세션 쿠키 정리
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // API 경로는 Accept 헤더 유무와 무관하게 항상 JSON 401 (web redirect/route('login') 폴백 차단).
            // 동일 파일의 IDV/CoreVersionMismatch 핸들러와 같은 가드 패턴.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => __('auth.unauthenticated')], 401)
                    ->withCookie(cookie()->forget(config('session.cookie')));
            }
        });

        // API 경로의 검증 실패 → 항상 JSON 422 (브라우저 리다이렉트 금지).
        //
        // Laravel 기본 동작은 `expectsJson()` 이 false 이면 **이전 페이지로 리다이렉트**한다.
        // 그런데 그 "이전 페이지" 는 `url()->previous()` 이고, 그 값의 출처는 요청의
        // `Referer` 헤더다 — 즉 인증 없는 API 주소 하나로 **임의 외부 주소로 튕겨 보낼 수
        // 있는 통로**가 된다. 자산 서빙 라우트에서는 그 조건이 일상적으로 성립한다:
        // `<img src="/api/system/public-storage/…">` 는 `Accept: image/*` 로 오므로
        // `expectsJson()` 이 false 이고, 경로 검증에 실패하면 302 가 나간다.
        //
        // 응답 형태 자체도 갈렸다 — 링크가 있는 서버의 정적 서빙은 같은 요청에 404 를 준다.
        // 같은 라우트가 `Accept` 헤더에 따라 다른 종류의 응답을 내는 상태를 없앤다.
        //
        // 이 통로만 고치지 않는 이유는 형제 자산 라우트(템플릿·모듈·플러그인)가 전부 같은
        // 구조이기 때문이다. 한 곳만 막으면 나머지가 그대로 우회로로 남는다 (제품 결정).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') && ! $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        // IDV 정책 위반 → HTTP 428 (Precondition Required) + verification payload
        $exceptions->render(function (IdentityVerificationRequiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ResponseHelper::identityRequired($e->getPayload());
            }
        });

        // 정책이 지정한 본인인증 수단을 지금 쓸 수 없음 → HTTP 503 (다른 수단으로 대신 발급하지 않는다 — 7.1.6)
        $exceptions->render(function (IdentityPolicyProviderUnavailableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ResponseHelper::error($e->getMessage(), 503, [
                    'failure_code' => 'POLICY_PROVIDER_UNAVAILABLE',
                    'policy_key' => $e->policyKey,
                ]);
            }
        });

        // 등급 상한(rank ceiling) / 권한 상승 차단 예외 → HTTP 403.
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

        // `api/*` 의 HTTP 예외(`abort(404)`·`abort(403)`·`abort(429)` 등) → 항상 JSON.
        //
        // 위 검증 실패 렌더러와 같은 사유다. 파일 서빙 헬퍼(`fileResponse()`)는 판정과 서빙
        // 사이에 파일이 사라지면 `abort(404)`, 읽을 수 없으면 `abort(403)` 을 던지는데, 그
        // 요청은 `<img src>` 라 `Accept: image/*` 로 온다 — 프레임워크 기본값은 그 요청에
        // **HTML 오류 페이지**를 돌려준다. 같은 라우트가 `Accept` 헤더에 따라 JSON 과 HTML 을
        // 갈라 내는 상태이고, 링크가 되는 서버의 정적 서빙(빈 본문 404)과도 갈린다. 형제
        // 렌더러 다섯이 전부 `expectsJson() || is('api/*')` 로 JSON 을 고정하는데 이 종류만
        // 빠져 있었다.
        //
        // 조건은 `expectsJson() || is('api/*')` 다 — 종전의 `is('api/*') && !expectsJson()`
        // 은 XHR(`Accept: application/json`) 요청을 이 렌더러 밖으로 흘려, 프레임워크 기본값이
        // 본문 없는 HTTP 예외를 `{"message":""}`(빈 문자열)로 돌려줬다. 그러면 같은 라우트가
        // 요청의 `Accept` 에 따라 `{success,message}` 와 `{message:""}` 로 응답 형태가 갈린다.
        // `api/*` 응답 형태 분기는 이 렌더러 한 곳이 소유한다.
        //
        // 예외가 실은 헤더(`Retry-After` 등)는 그대로 승계한다 — 예: 레이트리밋(429) 이 봇에
        // 재시도 간격을 알리는 통로다.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $status = $e->getStatusCode();
                $message = $e->getMessage();

                if ($message === '') {
                    $message = trans()->has("errors.{$status}.message")
                        ? __("errors.{$status}.message")
                        : __('errors.500.message');
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], $status, $e->getHeaders());
            }
        });

        // 비밀번호 인증 정책 거절 → 403 + `errors.code = auth.password_auth_disabled` + `data{method_label, redirect_url}` (7.2.0).
        // 로그인·재설정 컨트롤러는 직접 잡아 상태 코드를 고른다. 비밀번호 설정·관리자 지정처럼 회원 서비스 안에서 나는 경로는
        // 여기서 같은 모양으로 답한다 — 화면이 「회사 계정으로 로그인」 안내를 한 분기로 그린다.
        $exceptions->render(function (PasswordAuthDisallowedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                [$messageKey, $params] = PasswordAuthDisallowedException::messageKeyAndParams($e->decision);

                return ResponseHelper::error($messageKey, 403, ['code' => PasswordAuthDisallowedException::ERROR_CODE] + $e->decision->toResponseData(), $params, 'core', $e->decision->toResponseData());
            }
        });

        // 정책이 요구한 2단계 인증의 수단이 없음 → 422 (7.2.0)
        $exceptions->render(function (TwoFactorMeansRequiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ResponseHelper::error('auth.two_factor_means_required', 422, ['code' => 'auth.two_factor_means_required']);
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

        // 확장·언어팩·코어 작업 잠금 거절 → 409(같은 대상 진행 중) / 503(잠금 저장소 오류) (#161)
        // 컨트롤러가 잡지 않는 진입점(멈춘 업데이트 정리 등)의 안전망 — APP_DEBUG 에서도 trace·서버 경로를 싣지 않는다.
        $exceptions->render(function (ExtensionOperationLockException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ResponseHelper::error($e->getMessageKey(), $e->getStatusCode(), $e->getErrors(), $e->getMessageParams());
            }
        });

        // 확장이 요구하는 PHP 확장(composer.json require.ext-*) 미충족 → HTTP 422 + error_code: 'php_extension_missing'
        // (확장 install/activate 진입 지점에서 throw — update 는 경고만)
        $exceptions->render(function (PhpExtensionRequirementException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'php_extension_missing',
                    'data' => $e->getPayload(),
                ], 422);
            }
        });
    })->create();

/*
|--------------------------------------------------------------------------
| Public Path 통일 (B-2)
|--------------------------------------------------------------------------
|
| `public_path()` 오버라이드 지점을 여기 한 곳으로 통일해 CLI·웹·테스트가 같은 값을 본다.
| 문서 루트 분리 배치처럼 public 디렉토리를 앱 루트 밖에 두는 레이아웃은 진입 파일이
| `G7_PUBLIC_PATH` 상수를 정의하고, 그때만 이 자리가 값을 고정한다.
|
| **배포본의 어떤 파일도 이 상수를 정의하지 않는다** — 그 배치는 운영자가 자기 진입 파일을
| 두는 형태이고, 이 자리는 그 진입 파일이 값을 넘길 통로다(같은 계열로 `public/index.php` 의
| `G7_BASE_PATH` 가 있다). 정의처가 없다는 이유로 이 분기를 지우면 그 배치가 성립하지 않게
| 되므로, 「죽은 코드」로 오해하지 않도록 여기 적어 둔다.
|
| 상수를 정의할 진입 파일이 없는 문맥(콘솔 `artisan`·테스트)도 **같은 값**을 볼 수 있어야
| 한다. 그 통로가 없으면 문서 루트 분리 배치에서 웹은 운영자의 public 디렉토리를 보고
| 콘솔은 앱 루트의 `public/` 을 봐, `storage:link` 복구처럼 두 문맥이 함께 쓰는 코드가
| 서로 다른 트리를 대상으로 돈다(오류 없이 링크만 엉뚱한 곳에 생긴다). 그래서 상수가
| 없으면 같은 이름의 **프로세스 환경변수**를 읽는다 — 판정 지점은 여전히 이 한 곳이다.
| 값의 우선순위는 상수 > 환경변수이고, 둘 다 없으면 종전대로 아무것도 걸지 않는다.
| `.env` 는 이 시점에 아직 로드되지 않으므로(프레임워크 부팅 이후다) 셸·서버 환경변수로
| 전달해야 한다.
|
| 상수가 없는 기본 배치에서는 **아무것도 걸지 않는다.** `usePublicPath()` 가 담은 값은
| 절대경로로 저장되어 이후 `setBasePath()` 에 반응하지 않는다(Laravel `publicPath()` 는
| 저장된 값을 우선한다). 기본값으로라도 채워 두면 앱 루트를 갈아 끼우는 지점에서
| `storage_path()` 만 따라가고 `public_path()` 는 실 저장소를 가리켜, `public/storage`
| 복구처럼 두 경로를 함께 쓰는 코드가 서로 다른 트리를 대상으로 돈다.
|
*/
$g7PublicPath = null;

if (defined('G7_PUBLIC_PATH')) {
    $g7PublicPath = G7_PUBLIC_PATH;
} elseif (isset($_SERVER['G7_PUBLIC_PATH'])) {
    // 웹서버가 넘긴 환경변수(`SetEnv`·`fastcgi_param`)는 SAPI 에 따라 `getenv()` 가 아니라
    // 여기에만 실린다.
    $g7PublicPath = $_SERVER['G7_PUBLIC_PATH'];
} elseif (ExecutionEnvironment::hasFunction('getenv')) {
    // 셸 환경변수(콘솔·크론)는 `variables_order` 설정에 따라 `$_ENV` 가 비어 있을 수 있어
    // `getenv()` 로 읽는다. 그 함수를 막은 호스팅에서는 이 통로를 건너뛴다 (가드 SSoT 경유).
    $g7PublicPath = getenv('G7_PUBLIC_PATH');
}

if (is_string($g7PublicPath) && trim($g7PublicPath) !== '') {
    $app->usePublicPath(rtrim(trim($g7PublicPath), '/\\'));
}

/*
|--------------------------------------------------------------------------
| Core Update: Stale Package Manifest Self-Heal — 코어 업데이트 트리 안에서 부팅하는 프로세스
| (core:update 부모, G7_UPDATE_IN_PROGRESS=1 을 물려받은 spawn 자식, 그 안의 config:cache/route:cache
| 일회용 앱)는 bootstrap/cache/packages.php · services.php 를 비우고 부팅한다. Laravel 은 두 파일이
| 없을 때만 vendor/composer/installed.json 에서 다시 만들므로, vendor 교체 뒤 남은 이전 설치본의
| 목록(dev composer 의 require-dev 전이 provider 등)으로 부팅하다 "Class ... not found" 로 죽는 일을
| 막는다. 7.0.11 미만 부모는 spawn 직전에 비우지 않으므로 그 부모 아래에서 도는 신버전 자식의
| 유일한 방어다 (7.0.9/7.0.10 → 7.0.11). 7.0.11+ 부모는 이미 비우므로 여기서는 no-op.
| 규칙: App\ 클래스를 참조하지 않는다 (부팅 전이라 오토로드를 신뢰할 수 없고, 자가 치유가 자기
| 실패로 부팅을 막아서는 안 된다). 판정은 App\Support\CoreUpdateContext::isInProgress() 와 동일 —
| 조건을 바꾸면 양쪽을 함께 고친다.
| argv 채널은 명령줄 SAPI 에서만 읽는다. CGI/FPM 은 register_argc_argv=On 이면 $_SERVER['argv'] 를
| 쿼리스트링을 '+' 로 쪼갠 값으로 채우므로(`GET /?x+core:update` → argv[1] === 'core:update'), 이 게이트가
| 없으면 비인증 웹 요청이 요청마다 매니페스트를 지우고 다시 만들게 한다. env 플래그 채널은 웹 요청으로
| 주입할 수 없어 그대로 두며, 웹 요청 안에서 시작하는 업데이트 흐름은 그 플래그를 프로세스 안에서 세운다.
| `getenv` 는 `??` 사슬의 꼬리라 정상 요청마다 도달한다 — 그 함수를 막은 호스팅에서는 PHP 8 이 함수
| 테이블에서 제거해 호출이 치명 오류가 되므로 순수 PHP `function_exists` 로 가린다. 이 블록은 App\ 클래스를
| 참조하지 않는 규칙이라 `ExecutionEnvironment::hasFunction()` 을 부르지 않는다 — PHP 8 은 차단 함수를
| 등록하지 않으므로 두 판정은 같다(위 Public Path 블록은 부팅 뒤에도 도는 앱 코드라 SSoT 를 경유한다).
|
| Core Update: APP_VERSION 정합 — 같은 조건 안에서 프로세스 env 의 APP_VERSION 을 디스크 `.env` 값으로 올린다
| (bootstrap/g7-update-env.php). 7.0.0~7.0.10 부모는 `.env` 만 바꾸고 자기 env 는 옛 버전으로 두므로, 그 env 를
| 물려받은 새 코드 자식과 부모 안의 config:cache 일회용 앱이 옛 버전을 config/hooks 캐시에 구워 업데이트 뒤 첫
| 프로세스가 확장을 자동 비활성화했다(#145, #137). 옛 부모 코드는 고칠 수 없지만 config:cache 는 어느 프로세스에서
| 돌든 이 파일을 디스크에서 새로 require 하므로, 여기가 옛 부모 안과 모든 자식을 한 번에 덮는 유일한 지점이다.
*/
$g7UpdateFlag = $_ENV['G7_UPDATE_IN_PROGRESS'] ?? $_SERVER['G7_UPDATE_IN_PROGRESS']
    ?? (function_exists('getenv') ? getenv('G7_UPDATE_IN_PROGRESS') : false);
$g7UpdateArgv = in_array(PHP_SAPI, ['cli', 'phpdbg'], true) ? ($_SERVER['argv'][1] ?? '') : '';

if ($g7UpdateFlag === '1' || $g7UpdateFlag === 1 || $g7UpdateFlag === true
    || in_array($g7UpdateArgv, ['core:update', 'core:execute-upgrade-steps'], true)) {
    try {
        if (is_file(__DIR__.'/g7-update-env.php')) {
            require_once __DIR__.'/g7-update-env.php';
            g7_reconcile_update_app_version(dirname(__DIR__));
        }
    } catch (Throwable) {
        // 정합 실패는 부팅을 막지 않는다.
    }

    try {
        foreach ([$app->getCachedPackagesPath(), $app->getCachedServicesPath()] as $g7ManifestPath) {
            if (is_file($g7ManifestPath)) {
                @unlink($g7ManifestPath); // Windows 핸들 점유 시 실패 가능 — 무시(종전 동작)
            }
        }
        clearstatcache();
    } catch (Throwable) {
        // 자가 치유 실패는 부팅을 막지 않는다.
    }
}

unset($g7UpdateFlag, $g7UpdateArgv, $g7ManifestPath);

return $app;
