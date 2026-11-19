<?php

namespace App\Http\Middleware;

use App\CoreUpdate\CoreUpdateMaintenanceAccess;
use App\Http\Middleware\Concerns\DetectsRequestLocale;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceModePage
{
    use DetectsRequestLocale;

    /**
     * 점검 중에도 통과시키는 경로 (라우트 이름이 아니라 URI 로 판정한다).
     *
     * 웹 코어 업데이트는 **스스로 점검 모드를 켠 뒤** 다음 단계를 같은 브라우저에서
     * 이어 호출한다. 그 요청까지 503 으로 막으면 사이트를 세워 놓고 진행할 수단이
     * 사라진다 — 「유지보수 모드에 갇혀 복구할 수 없는 상태 금지」(A-7 설계 4)가
     * 성립하려면 진행·되돌리기 경로가 점검 중에도 열려 있어야 한다.
     *
     * 우회 쿠키(`laravel_maintenance`)도 함께 발급하지만 그것만 믿지 않는다 — 쿠키는
     * 만료되고, 브라우저가 지울 수 있으며, 다른 기기에서 접속한 운영자에게는 없다.
     * 이 목록은 그 모든 경우에도 남는 마지막 통로다.
     *
     * 여기 있는 경로는 전부 인증 + `core.system.update` 권한 뒤에 있다 —
     * 통과시키는 것은 미들웨어 한 겹이지 접근 통제가 아니다.
     */
    public const MAINTENANCE_EXEMPT_URIS = [
        'api/admin/core-update/status',
        'api/admin/core-update/advance',
        'api/admin/core-update/dismiss',
    ];

    /**
     * Maintenance 모드일 때 전용 정적 페이지를 렌더링합니다.
     *
     * DB, API, JS 에셋에 의존하지 않는 자체 완결 Blade 페이지를 반환합니다.
     * 메인터넌스 모드는 시스템 불안정 시(DB 마이그레이션, 코어 업데이트 등) 사용되므로
     * 외부 의존성 없이 동작해야 합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isDownForMaintenance()) {
            return $next($request);
        }

        $data = $this->downData();

        // `php artisan down --secret=…` 의 비밀 경로 — 우회 쿠키를 발급하고 첫 화면으로 보낸다.
        // Laravel 기본 점검 미들웨어를 이 미들웨어로 바꾼 뒤로는 쿠키를 검증만 하고 발급하지 않아, 안내된 비밀 주소가 503 이었다(#888).
        if (isset($data['secret']) && is_string($data['secret']) && $data['secret'] !== '' && $request->path() === trim($data['secret'], '/')) {
            return redirect(SiteUrl::absolute('/'))->withCookie(MaintenanceModeBypassCookie::create($data['secret']));
        }

        // secret 쿠키가 있으면 통과 (관리자 접근) — 발급과 같은 점검 드라이버 데이터로 검증한다
        if ($this->hasValidBypassCookie($request, $data)) {
            return $next($request);
        }

        // 웹 코어 업데이트의 진행·되돌리기 경로는 점검 중에도 열어 둔다.
        if ($request->is(self::MAINTENANCE_EXEMPT_URIS)) {
            return $next($request);
        }

        // 우회 쿠키가 없는 관리자의 이어 가기 통로 — 웹 코어 업데이트가 진행·되돌리기·복구 중일 때만 연다.
        // 비밀 경로와 같이 이 미들웨어가 직접 답한다: 라우트(web 그룹)로 보내면 쿠키 암호화가 우회 쿠키를 감싸 이 미들웨어(복호화 전)가
        // 읽지 못하고, 점검 화면은 세션 전에 그려져 CSRF 토큰도 실을 수 없다. 이 요청은 로그인 세션을 만들지 않고, 코어 업데이트 권한
        // 관리자에게 우회 쿠키만 준다(위조 요청이 얻는 것은 그 브라우저의 점검 화면 통과뿐).
        $resumeAccess = $this->coreUpdateResumeAccessAvailable();
        if ($resumeAccess && $request->isMethod('POST') && $request->is(CoreUpdateMaintenanceAccess::PATH)) {
            return $this->handleCoreUpdateResumeAccess($request);
        }

        // 통과 경로(G7_MAINTENANCE_EXCEPT — down 파일에 except 가 있으면 함께)와 허용 IP(G7_MAINTENANCE_ALLOW_IPS)는 통과
        $except = array_merge((array) ($data['except'] ?? []), (array) config('core.maintenance.except', []));
        if ($this->inExceptArray($request, $except) || $this->fromAllowedIp($request)) {
            return $next($request);
        }

        // `down --redirect=…` — 그 경로가 아니면 그쪽으로 보낸다
        if (isset($data['redirect']) && is_string($data['redirect']) && $data['redirect'] !== '') {
            $path = $data['redirect'] === '/' ? '/' : '/'.trim($data['redirect'], '/');
            if ($request->path() !== trim($path, '/') && $request->path() !== $path) {
                return redirect(SiteUrl::absolute($path));
            }
        }

        $status = (int) ($data['status'] ?? 503) ?: 503;
        $headers = $this->maintenanceHeaders($data);

        // 로케일 감지 (SetLocale 미들웨어가 실행되지 않으므로 자체 감지)
        // API/HTML 분기보다 먼저 적용해야 JSON 응답 메시지도 ja 등 활성 언어팩 로케일을 반영한다
        $locale = $this->detectLocale($request);
        app()->setLocale($locale);

        // API 요청은 JSON 응답
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => __('maintenance.message'),
            ], $status, $headers);
        }

        // 정적 메인터넌스 페이지 렌더링 (DB/API/JS 무의존)
        // 웹 코어 업데이트가 진행·되돌리기·복구 중이면 관리자 확인 칸을 함께 그린다 — 브라우저를 닫은 운영자가 다른 기기에서 이어 간다.
        $accessResult = (string) $request->query('maintenance_access', '');

        return response()->view('maintenance', [
            'coreUpdateResumeAccess' => $resumeAccess,
            'coreUpdateAccessPath' => '/'.CoreUpdateMaintenanceAccess::PATH,
            'coreUpdateAccessResult' => in_array($accessResult, [CoreUpdateMaintenanceAccess::RESULT_FAILED, CoreUpdateMaintenanceAccess::RESULT_THROTTLED], true) ? $accessResult : null,
        ], $status, $headers);
    }

    /**
     * 점검 화면 관리자 확인 칸의 제출을 처리합니다 — 확인되면 우회 쿠키를 주고 업데이트 탭으로, 아니면 점검 화면으로 돌려보냅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return Response 이동 응답
     */
    protected function handleCoreUpdateResumeAccess(Request $request): Response
    {
        try {
            $outcome = app(CoreUpdateMaintenanceAccess::class)->attempt(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) app(TrustProxies::class)->handle($request, static fn (Request $trusted): ?string => $trusted->ip()),
                $request->userAgent(),
            );
        } catch (\Throwable $e) {
            // 점검 중이라 오류 화면을 낼 수 없다 — 실패로 돌려보내되 원인은 남긴다
            Log::warning('점검 화면 관리자 확인 처리 중 오류', ['error' => $e->getMessage(), 'exception' => $e::class]);
            $outcome = ['result' => CoreUpdateMaintenanceAccess::RESULT_FAILED, 'cookie' => null];
        }

        if ($outcome['result'] === CoreUpdateMaintenanceAccess::RESULT_GRANTED && $outcome['cookie'] !== null) {
            return redirect(SiteUrl::absolute('/admin/settings?tab=update'))->withCookie($outcome['cookie']);
        }

        return redirect(SiteUrl::absolute('/').'?maintenance_access='.$outcome['result']);
    }

    /**
     * 우회 쿠키가 없는 관리자의 코어 업데이트 이어 가기 통로가 열려 있는지 판정합니다 — 실패하면 닫힌 것으로 본다.
     *
     * @return bool 열려 있으면 true
     */
    protected function coreUpdateResumeAccessAvailable(): bool
    {
        try {
            return app(CoreUpdateMaintenanceAccess::class)->available();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 점검 모드 데이터(`down` 옵션)를 읽습니다 — 읽지 못하면 빈 배열.
     *
     * @return array<string, mixed> down 데이터
     */
    protected function downData(): array
    {
        try {
            $data = app()->maintenanceMode()->data();
        } catch (\Throwable) {
            $data = null;
        }

        return is_array($data) ? $data : [];
    }

    /**
     * 점검 중 통과 경로인지 판정합니다 (Laravel 기본 점검 미들웨어와 같은 비교).
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<int, string>  $except  예외 경로
     * @return bool 예외 경로면 true
     */
    protected function inExceptArray(Request $request, array $except): bool
    {
        foreach ($except as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if ($pattern !== '/') {
                $pattern = trim($pattern, '/');
            }
            if ($request->fullUrlIs($pattern) || $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 점검 중에도 통과시키는 IP 인지 판정합니다 — `core.maintenance.allow_ips`(G7_MAINTENANCE_ALLOW_IPS, 쉼표 구분·CIDR 허용).
     *
     * 클라이언트 IP 는 신뢰 프록시 설정(`TRUSTED_PROXIES`)을 거친 값이다. 이 미들웨어는 전역 스택 맨 앞(prepend)이라
     * Laravel 의 TrustProxies 보다 먼저 돈다 — 그대로 `$request->ip()` 를 읽으면 프록시 뒤에서는 늘 프록시 주소가 된다.
     * 그래서 같은 TrustProxies 를 여기서 먼저 적용한 뒤 읽는다(뒤따르는 전역 TrustProxies 가 같은 값으로 다시 적용해도 무해).
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 허용 IP 면 true
     */
    protected function fromAllowedIp(Request $request): bool
    {
        $allowed = array_values(array_filter(array_map('trim', (array) config('core.maintenance.allow_ips', [])), fn ($v) => $v !== ''));
        if ($allowed === []) {
            return false;
        }

        $ip = app(TrustProxies::class)->handle($request, static fn (Request $trusted): ?string => $trusted->ip());

        return $allowed !== [] && is_string($ip) && IpUtils::checkIp($ip, $allowed);
    }

    /**
     * `down --retry`·`--refresh` 를 응답 헤더로 바꿉니다.
     *
     * @param  array<string, mixed>  $data  down 데이터
     * @return array<string, string> 헤더
     */
    protected function maintenanceHeaders(array $data): array
    {
        $headers = [];
        if (isset($data['retry']) && is_numeric($data['retry'])) {
            $headers['Retry-After'] = (string) (int) $data['retry'];
        }
        if (isset($data['refresh']) && is_numeric($data['refresh'])) {
            $headers['Refresh'] = (string) (int) $data['refresh'];
        }

        return $headers;
    }

    /**
     * 유효한 bypass 쿠키가 있는지 확인합니다.
     *
     * 비밀값은 쿠키 발급과 같은 점검 드라이버 데이터(`app()->maintenanceMode()->data()`)에서 읽는다 — 파일(`storage/framework/down`)만
     * 읽으면 `APP_MAINTENANCE_DRIVER=cache` 에서 발급한 쿠키가 늘 무효였다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<string, mixed>|null  $data  점검 데이터 (없으면 드라이버에서 읽는다)
     * @return bool 유효한 우회 쿠키가 있으면 true
     */
    protected function hasValidBypassCookie(Request $request, ?array $data = null): bool
    {
        $data ??= $this->downData();

        if (! isset($data['secret']) || ! is_string($data['secret']) || $data['secret'] === '') {
            return false;
        }

        $cookie = $request->cookie('laravel_maintenance');

        if (! $cookie) {
            return false;
        }

        return MaintenanceModeBypassCookie::isValid($cookie, $data['secret']);
    }
}
