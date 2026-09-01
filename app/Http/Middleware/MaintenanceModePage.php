<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\DetectsRequestLocale;
use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
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

        // secret 쿠키가 있으면 통과 (관리자 접근)
        if ($this->hasValidBypassCookie($request)) {
            return $next($request);
        }

        // 웹 코어 업데이트의 진행·되돌리기 경로는 점검 중에도 열어 둔다.
        if ($request->is(self::MAINTENANCE_EXEMPT_URIS)) {
            return $next($request);
        }

        // 로케일 감지 (SetLocale 미들웨어가 실행되지 않으므로 자체 감지)
        // API/HTML 분기보다 먼저 적용해야 JSON 응답 메시지도 ja 등 활성 언어팩 로케일을 반영한다
        $locale = $this->detectLocale($request);
        app()->setLocale($locale);

        // API 요청은 JSON 응답
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => __('maintenance.message'),
            ], 503);
        }

        // 정적 메인터넌스 페이지 렌더링 (DB/API/JS 무의존)
        return response()->view('maintenance', [], 503);
    }

    /**
     * 유효한 bypass 쿠키가 있는지 확인합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool
     */
    protected function hasValidBypassCookie(Request $request): bool
    {
        $downFilePath = storage_path('framework/down');

        if (! file_exists($downFilePath)) {
            return false;
        }

        $data = json_decode(file_get_contents($downFilePath), true);

        if (! isset($data['secret'])) {
            return false;
        }

        $cookie = $request->cookie('laravel_maintenance');

        if (! $cookie) {
            return false;
        }

        return MaintenanceModeBypassCookie::isValid($cookie, $data['secret']);
    }
}
