<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use App\Support\RuntimeRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * stage 역할 서버에서 확장·코어·언어팩 수명주기 API 와 사이트맵 재생성을 막습니다 (409 `errors.stage_role_locked`).
 *
 * 검수 서버는 운영 서버와 DB·저장소를 공유한다. 그 서버의 관리자 화면에서 확장을 설치·제거·업데이트하면 운영 서버의 상태가
 * 바뀐다. 수명주기 변경은 운영 서버(또는 CLI 배포 절차)에서만 한다 — CLI 명령은 막지 않는다.
 *
 * @since 7.2.0
 */
class DenyOnStageRole
{
    /**
     * 거절 사유별 안내 문구 키 — 매개변수 없이 쓰면 수명주기 안내(`errors.stage_role_locked`).
     *
     * @var array<string, string>
     */
    private const REASON_MESSAGES = [
        'sitemap' => 'errors.stage_role_locked_sitemap',
    ];

    /**
     * stage 역할이면 409 로 거절합니다.
     *
     * 라우트가 `deny.stage:sitemap` 처럼 사유를 넘기면 그 사유의 안내를 낸다 — 사이트맵 재생성에 「확장·코어·언어팩 변경」
     * 안내가 나오지 않게.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @param  string|null  $reason  거절 사유 (`sitemap`, 없으면 수명주기)
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next, ?string $reason = null): Response
    {
        if (RuntimeRole::isStage()) {
            return ResponseHelper::error(self::REASON_MESSAGES[$reason] ?? 'errors.stage_role_locked', 409);
        }

        return $next($request);
    }
}
