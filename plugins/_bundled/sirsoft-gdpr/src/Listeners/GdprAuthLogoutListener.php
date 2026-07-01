<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;

/**
 * GDPR 로그아웃 시 쿠키 폐기 리스너
 *
 * 코어 `core.auth.logout` 훅 구독 — 사용자 로그아웃 시 GDPR 게스트 세션 쿠키를
 * 응답에 폐기 명령으로 큐잉한다 (Cookie::queue + forget).
 *
 * 이유: 회원 A 로그아웃 → 같은 브라우저로 회원 B 로그인 시 A 의 게스트 세션이
 * B 에게 적용되지 않도록 차단 (계획서 §8.3.3 M-7).
 *
 * 폐기 대상 쿠키:
 * - gdpr_session: 게스트 세션 식별자
 *
 * 참고 (v1.5.0): 클라이언트 사이드 동의 캐시(gdpr_consent 쿠키)는 제거되었으며,
 * 동의 SSoT 는 서버 DB 다. 회원 동의는 user_id 기반으로 저장되어 로그아웃 시점에
 * 별도 폐기가 필요 없음.
 */
class GdprAuthLogoutListener implements HookListenerInterface
{
    /**
     * 폐기 대상 쿠키 키 목록
     */
    private const COOKIES_TO_FORGET = ['gdpr_session'];

    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int}>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.logout' => [
                'method' => 'forgetGdprCookies',
                'priority' => 10,
                // 응답에 쿠키 큐잉이 필요하므로 동기 실행
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 요구 메서드.
     *
     * @param mixed ...$args
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드 분기
    }

    /**
     * 로그아웃 시 GDPR 쿠키들을 폐기 큐잉.
     *
     * Cookie::queue(Cookie::forget(...)) 는 Laravel 의 AddQueuedCookiesToResponse
     * 미들웨어가 응답에 자동 포함시킨다. 클라이언트는 epoch expires 헤더를 받아
     * 즉시 쿠키 삭제.
     *
     * @param User $user 로그아웃한 사용자 (사용 안 함, 시그니처 호환용)
     * @return void
     */
    public function forgetGdprCookies(User $user): void
    {
        foreach (self::COOKIES_TO_FORGET as $name) {
            Cookie::queue(Cookie::forget($name));
        }
    }
}
