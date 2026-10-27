<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * SSO 로그인 뒤 G7 2단계 인증 생략 여부(D13) — 플러그인 필터 `sirsoft-sso.handoff.filter_context` 에 `skip_two_factor` 를 싣습니다.
 *
 * IdP 의 2단계 인증 방식: `always_skip` → 생략 / `skip_if_mfa`(기본) → IdP 가 다중 인증을 알려 준 때만 생략(신호가 없으면 G7 2단계) /
 * `always` → 항상 G7 2단계.
 *
 * @since 1.0.0
 */
class TwoFactorContextListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.handoff.filter_context' => ['method' => 'apply', 'type' => 'filter', 'priority' => 20, 'sync' => true],
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
     * 2단계 인증 생략 여부를 문맥에 싣습니다.
     *
     * @param  mixed  $context  인계 문맥
     * @param  User|null  $user  회원
     * @param  SsoAccount|null  $account  연결
     * @param  ExternalIdentity|null  $identity  IdP 신원
     * @param  IdentityProvider|null  $idp  IdP
     * @return array<string, mixed> 문맥
     */
    public function apply(mixed $context = [], ?User $user = null, ?SsoAccount $account = null, ?ExternalIdentity $identity = null, ?IdentityProvider $idp = null): array
    {
        $context = is_array($context) ? $context : [];

        if (! $idp instanceof IdentityProvider) {
            return $context;
        }

        $context['skip_two_factor'] = self::skip($idp->two_factor_mode, $identity?->mfa);

        return $context;
    }

    /**
     * 방식과 IdP 다중 인증 신호로 생략 여부를 정합니다.
     *
     * @param  string|null  $mode  always_skip, skip_if_mfa, always
     * @param  bool|null  $mfa  IdP 다중 인증 신호 (없으면 null)
     * @return bool 생략하면 true
     */
    public static function skip(?string $mode, ?bool $mfa): bool
    {
        return match ($mode) {
            'always_skip' => true,
            'always' => false,
            default => $mfa === true,
        };
    }
}
