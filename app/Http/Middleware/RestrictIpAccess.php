<?php

namespace App\Http\Middleware;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Enums\ActivityLogType;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use App\Http\Middleware\Concerns\DetectsRequestLocale;
use App\Support\IpAccessMatcher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리자 영역·라우트별 IP 접근 제한 (전역 미들웨어).
 *
 * **전역**으로 두는 이유: 코어 admin 라우트 그룹에만 얹으면 확장이 등록한 관리자 라우트가
 * 사각으로 남고, 라우트 미들웨어는 `withoutMiddleware()` 로 빠져나갈 수 있다. 전역 스택은 라우트
 * 매칭보다 먼저 돌아 모든 표면(관리자 셸·코어 API·확장 API)을 같은 판정으로 덮는다.
 *
 * **스택 뒤쪽(append)에 두는 이유**: 신뢰 프록시는 Laravel 기본 전역 스택의 `TrustProxies` 가
 * 요청 시점에 적용한다(`config/trustedproxy.php`). 그보다 앞에서 돌면 프록시 뒤 사이트에서
 * `$request->ip()` 가 프록시 주소가 되어, 전원이 한 IP 로 보이고 제한이 통째로 무너진다.
 *
 * 판정은 {@see IpAccessMatcher} 가 전담한다 — 이 클래스는 설정을 읽어 넘기고, 결과에 따라
 * 차단 응답과 활동 로그만 만든다.
 *
 * @since 7.1.0
 */
class RestrictIpAccess
{
    use DetectsRequestLocale;
    use ResolvesActivityLogType;

    /**
     * 같은 IP 의 차단 기록 간격 (초).
     *
     * 활동 로그는 요청마다 동기 DB 쓰기다. 차단된 IP 가 관리자 영역을 계속 두드리면 이 기록이
     * 그대로 DB 쓰기 증폭기가 된다 — 감사에는 "언제 어느 IP 가 어디를" 의 첫 건이면 충분하다.
     */
    private const BLOCK_LOG_INTERVAL_SECONDS = 60;

    /**
     * 요청 IP 가 허용 범위 밖이면 403 으로 끊습니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $decision = null;

        try {
            $security = g7_core_settings('security', []);

            if (is_array($security) && IpAccessMatcher::isEnabled($security)) {
                // 관리자 영역이면 제외 패턴이 무시된다는 판정은 matcher 가 가진다 — 여기서 같은 판정을
                // 다시 쓰면 관리자 영역 정의가 바뀔 때 두 곳을 함께 고쳐야 한다는 사실이 코드에서 사라진다.
                $decision = IpAccessMatcher::decide(
                    $request->ip(),
                    $request->path(),
                    $security,
                    $this->extensionExemptPatterns(),
                );
            }
        } catch (\Throwable $e) {
            // fail-open — 보안 기능을 열어 두는 의식적 선택이다.
            // 설정 파일(JSON) 자체가 깨지면 JsonConfigRepository 가 기본값(꺼짐)으로 떨어뜨려
            // 이 자리에 오기 전에 이미 열린다. 여기 도달하는 실패는 값의 형태 손상(목록 자리에
            // 배열이 아닌 값)이나 판정 중 예외다. 닫아 두면(fail-closed) 설정 한 줄이 깨졌을 때
            // 관리자를 포함한 전원이 잠기고 복구 수단이 셸뿐이 된다 — 그래서 열고, 반드시 남긴다.
            Log::error('IP 접근 제한 판정 실패 — 이 요청은 제한 없이 통과시킵니다', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            $decision = null;
        }

        if ($decision === null) {
            return $next($request);
        }

        $this->recordBlocked($request, $decision);

        return $this->deny($request);
    }

    /**
     * 확장이 훅으로 등록한 제외 경로(인바운드 콜백)를 수집합니다.
     *
     * @return array<int, string> URI glob 패턴 목록
     */
    private function extensionExemptPatterns(): array
    {
        $patterns = HookManager::applyFilters(IpAccessMatcher::EXEMPT_PATHS_HOOK, []);

        if (! is_array($patterns)) {
            return [];
        }

        return array_values(array_filter($patterns, 'is_string'));
    }

    /**
     * 차단 사실을 활동 로그에 남깁니다 (같은 IP 는 간격 안에서 1건).
     *
     * 로그 유형은 System 으로 **명시**한다 — 자동 판정은 경로로 관리자/사용자를 가르는데,
     * 차단은 그 사용자의 행위가 아니라 시스템이 취한 조치이기 때문이다.
     *
     * @param  Request  $request  차단된 요청
     * @param  array{axis: string, pattern: string|null}  $decision  거부 사유
     * @return void 활동 로그에 직접 남긴다
     */
    private function recordBlocked(Request $request, array $decision): void
    {
        $ip = (string) $request->ip();
        $throttleKey = 'ip_restriction.blocked_log.'.sha1($ip);

        try {
            $cache = new CoreCacheDriver(config('cache.default', 'array'));

            if ($cache->has($throttleKey)) {
                return;
            }

            $cache->put($throttleKey, true, self::BLOCK_LOG_INTERVAL_SECONDS);
        } catch (\Throwable) {
            // 캐시가 죽어도 기록은 남긴다 — 잃는 것은 간격 제한뿐이다.
        }

        $path = Str::limit('/'.IpAccessMatcher::normalizePath($request->path()), 255, '');

        $this->logActivity('security.ip_blocked', [
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.security_ip_blocked',
            'description_params' => [
                'ip' => $ip,
                'path' => $path,
            ],
            'properties' => [
                'ip' => $ip,
                'path' => $path,
                'method' => $request->method(),
                'axis' => $decision['axis'],
                'pattern' => $decision['pattern'],
            ],
        ]);
    }

    /**
     * 차단 응답을 만듭니다 — API 는 403 JSON, 웹은 403 페이지.
     *
     * SetLocale 이 아직 돌지 않은 시점이라 로케일을 직접 감지한다(MaintenanceModePage 와 같은 결).
     *
     * @param  Request  $request  차단된 요청
     * @return Response 403 응답
     */
    private function deny(Request $request): Response
    {
        app()->setLocale($this->detectLocale($request));

        if ($request->expectsJson() || $request->is('api/*')) {
            return ResponseHelper::forbidden('errors.ip_restricted.message');
        }

        return response()->view('errors.403', [
            'ipRestricted' => true,
            'clientIp' => $request->ip(),
        ], 403);
    }
}
