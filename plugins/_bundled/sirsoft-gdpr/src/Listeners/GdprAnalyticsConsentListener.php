<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;

/**
 * 코어 방문자 통계의 동의 조회 식별자 리스너
 *
 * 이 플러그인은 게스트 동의를 서명된 `gdpr_session` 쿠키의 UUID 로 저장합니다 — 라라벨 세션과
 * 무관한 값입니다. 코어 방문자 통계가 동의 연동 모드에서 게스트 동의를 물을 때 라라벨 세션 ID
 * 로 물으면(수집 API 에는 세션도 없다) 배너에서 동의한 게스트도 「동의 없음」이 되어 한 명도
 * 세어지지 않습니다. 코어가 수집 요청을 조립할 때 여는 `core.analytics.consent_session_id` 필터에
 * 그 UUID 를 답해, 코어가 이 플러그인의 `getCurrentCookieConsents()` 에 같은 키로 묻게 합니다.
 *
 * 회원은 회원 번호로 동의를 찾으므로 건드리지 않습니다. 쿠키는 서명을 검증한 뒤에만 믿습니다 —
 * 수집 API 는 쿠키를 암호화하지 않으므로 위조한 값으로 남의 동의를 빌릴 수 없어야 합니다.
 */
class GdprAnalyticsConsentListener implements HookListenerInterface
{
    use IssuesGuestSessionCookie;

    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int, type?: string, sync?: bool}>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync => true : 요청 쿠키를 읽는다 — 큐로 넘기면 요청 문맥이 없다.
            'core.analytics.consent_session_id' => [
                'method' => 'resolveConsentSessionId',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 요구 메서드.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 게스트 요청이면 검증된 `gdpr_session` UUID 를, 아니면 코어가 준 기본값을 돌려줍니다.
     *
     * @param  mixed  $default  코어 기본값 (시작된 라라벨 세션 ID 또는 null)
     * @param  mixed  $request  수집 요청 (HTTP 요청)
     * @return mixed 동의 조회용 세션 식별자
     */
    public function resolveConsentSessionId(mixed $default, mixed $request = null): mixed
    {
        if (! $request instanceof Request || $request->user() !== null) {
            return $default;
        }

        return $this->verifyGuestSessionId($request->cookie('gdpr_session')) ?? $default;
    }
}
