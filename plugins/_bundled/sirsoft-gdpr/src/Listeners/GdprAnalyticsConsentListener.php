<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

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
 *
 * 코어는 이 플러그인을 모릅니다. 코어가 자기 필터로 묻고 이 리스너가 답합니다:
 *   - `core.analytics.visitor_has_consent` — 동의 모드: 분석 쿠키에 동의했는가
 *   - `core.analytics.tracking_permission` — 식별자(IP·방문자 쿠키)로 추적해도 되는가. 이 플러그인은
 *     기능 동의 전에는 방문자 쿠키를 응답에서 지우므로 분석·기능 **둘 다** 동의해야 허용이다.
 * 동의 철회는 코어 액션 `core.analytics.consent_revoked` 로 알립니다.
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
            'core.analytics.visitor_has_consent' => [
                'method' => 'answerVisitorHasConsent',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
            'core.analytics.tracking_permission' => [
                'method' => 'answerTrackingPermission',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
            // 이 플러그인의 철회 알림을 코어 액션으로 옮긴다 — 코어가 이 플러그인의 훅 이름을 몰라도 되게.
            'sirsoft-gdpr.consent.revoked' => [
                'method' => 'relayConsentRevoked',
                'priority' => 10,
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

    /**
     * 동의 모드에서 방문자가 분석 쿠키에 동의했는지 답합니다.
     *
     * 앞선 구독자가 이미 답했으면 그 답을 그대로 둡니다.
     *
     * @param  mixed  $answer  지금까지의 답 (null 이면 아직 아무도 답하지 않음)
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $sessionId  동의 조회용 세션 식별자
     * @return mixed 동의 여부
     */
    public function answerVisitorHasConsent(mixed $answer, ?int $userId = null, ?string $sessionId = null): mixed
    {
        if ($answer !== null) {
            return $answer;
        }

        return ($this->consents($userId, $sessionId)['analytics'] ?? false) === true;
    }

    /**
     * 방문자를 식별자로 추적해도 되는지 답합니다 (분석·기능 쿠키 둘 다 동의해야 허용).
     *
     * 기능 동의가 없으면 이 플러그인이 방문자 쿠키를 응답에서 지우므로, 분석만 동의한 방문자를 쿠키
     * 방식으로 세면 비콘마다 새 방문이 된다.
     *
     * @param  mixed  $answer  지금까지의 답 (null 이면 아직 아무도 답하지 않음)
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $sessionId  동의 조회용 세션 식별자
     * @return mixed 허용 여부
     */
    public function answerTrackingPermission(mixed $answer, ?int $userId = null, ?string $sessionId = null): mixed
    {
        if ($answer !== null) {
            return $answer;
        }

        $consents = $this->consents($userId, $sessionId);

        return ($consents['analytics'] ?? false) === true && ($consents['functional'] ?? false) === true;
    }

    /**
     * 이 플러그인의 동의 철회를 코어 액션으로 알립니다.
     *
     * @param  mixed  ...$args  철회 훅 인자 (동의 모델·출처)
     * @return void 없음
     */
    public function relayConsentRevoked(...$args): void
    {
        HookManager::doAction('core.analytics.consent_revoked');
    }

    /**
     * 방문자의 현재 쿠키 동의 항목을 읽습니다. 실패하면 빈 배열(동의 없음으로 판정)입니다.
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $sessionId  동의 조회용 세션 식별자
     * @return array<string, mixed> 항목 → 동의 여부
     */
    private function consents(?int $userId, ?string $sessionId): array
    {
        try {
            $consents = app(GdprConsentService::class)->getCurrentCookieConsents($userId, $sessionId);

            return is_array($consents) ? $consents : [];
        } catch (\Throwable $e) {
            Log::warning('[sirsoft-gdpr] 방문자 통계 동의 조회 실패', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
