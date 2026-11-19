<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * 사이트 전체 강제는 셀프 가입도 닫는다(W37) — 코어 필터 `core.auth.self_registration_open`.
 *
 * 회사가 만드는 계정(SSO 자동 생성, SCIM, 관리자 추가, CSV)은 이 필터와 무관하게 계속 만들어진다.
 *
 * @since 1.0.0
 */
class SelfRegistrationListener implements HookListenerInterface
{
    /**
     * @param  PolicyService  $policy  정책
     */
    public function __construct(private readonly PolicyService $policy) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.self_registration_open' => ['method' => 'open', 'type' => 'filter', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 가입을 열어 둘지 판정합니다.
     *
     * @param  mixed  $open  앞선 판정
     * @return bool 열려 있으면 true
     */
    public function open(mixed $open = true): bool
    {
        return (bool) $open && ! $this->policy->get('enforce_site', false);
    }
}
