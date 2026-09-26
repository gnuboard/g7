<?php

namespace App\Contracts\Extension;

use App\Models\User;

/**
 * 사용자 단위 challenge 가능 여부를 스스로 판정하는 IDV 프로바이더의 보조 계약.
 *
 * `IdentityVerificationInterface::isAvailable()` 는 런타임 설정(API 키 등) 기준의
 * 전역 가용성만 답합니다. 그러나 TOTP 처럼 **사용자마다 등록 여부가 다른** 수단은
 * "설정은 켜져 있지만 이 사용자는 아직 등록하지 않았다" 를 구분해야 합니다. 구분하지
 * 못하면 미등록 관리자에게 완료할 수 없는 challenge 를 발급해 계정이 영구히 잠깁니다.
 *
 * 이 메서드를 `IdentityVerificationInterface` 에 직접 추가하지 않는 이유는, 이미 배포된
 * 외부 프로바이더(KCP·이니시스 등)가 전부 깨지기 때문입니다. 따라서 **opt-in 보조 계약**
 * 으로 분리하고, 미구현 프로바이더에는 코어가 기존 규칙(`filled($user->email)`)을
 * 그대로 적용합니다 — 기존 동작·테스트 계약 불변.
 *
 * @since 7.1.0
 */
interface UserScopedIdentityProviderInterface
{
    /**
     * 이 사용자에게 지금 challenge 를 발행할 수 있는지 여부.
     *
     * 판정은 **부작용이 없어야** 합니다 (조회만). 로그인 판정 경로에서 호출되므로
     * 이 메서드가 challenge 를 만들거나 로그를 남기면 안 됩니다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 이 사용자로 challenge 발행이 가능하면 true
     */
    public function canChallengeUser(User $user): bool;
}
