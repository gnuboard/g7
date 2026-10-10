<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\IdentityVerificationChannel;
use App\Exceptions\Auth\AccountLockedException;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use Illuminate\Http\JsonResponse;

/**
 * 세션을 발급하는 엔드포인트가 공유하는 실패 응답을 구성하는 트레이트.
 *
 * 사용자 로그인과 관리자 로그인은 서로 다른 베이스 컨트롤러를 상속하지만, 계정 잠금과
 * 인증번호 발송 실패는 **같은 사건**이므로 같은 형태로 응답해야 합니다. 두 컨트롤러가
 * 각자 사본을 들고 있으면 한쪽 페이로드에 필드가 추가될 때 다른 쪽이 조용히 뒤처져,
 * 같은 실패인데 화면이 다르게 안내하게 됩니다.
 *
 * @since 7.0.11
 */
trait BuildsAuthFailureResponses
{
    /**
     * 계정 잠금 응답(423)을 구성합니다.
     *
     * @param  AccountLockedException  $e  잠금 예외
     * @return JsonResponse 423 응답
     */
    protected function lockedResponse(AccountLockedException $e): JsonResponse
    {
        // 영구 잠금(무한대 설정)은 해제 시각·잔여 시간이 없다 — null 그대로 노출.
        return $this->error(
            $e->isPermanent() ? 'auth.account_locked_permanently' : 'auth.account_locked',
            423,
            [
                'locked_until' => $e->lockedUntil?->toIso8601String(),
                'retry_after_seconds' => $e->remainingMinutes === null ? null : $e->remainingMinutes * 60,
                'permanent' => $e->isPermanent(),
            ],
            // `count` 로 「1 minute」/「2 minutes」 를 고른다(영구 잠금은 null — 고르지 않음).
            ['minutes' => $e->remainingMinutes, 'count' => $e->remainingMinutes]
        );
    }

    /**
     * 인증번호 발송 실패 응답(503)을 구성합니다.
     *
     * 자격 증명은 올바르므로 401 로 답하지 않는다 — 사용자는 비밀번호를 의심하며 같은
     * 실패를 반복하고, 운영자는 메일 설정이 깨진 사실을 알 방법이 없다.
     *
     * @return JsonResponse 503 응답
     */
    protected function deliveryFailedResponse(): JsonResponse
    {
        return $this->error('auth.two_factor_delivery_failed', 503);
    }

    /**
     * 비밀번호 변경 단계 발급 실패 응답(503)을 구성합니다.
     *
     * 자격 증명은 올바르고 비밀번호를 바꿔야 하는데 단계를 열 수 없다 — 토큰을 그냥 주면 만료·임시
     * 비밀번호 강제가 조용히 열리므로 막고, 401 로 뭉개지 않는다.
     *
     * @return JsonResponse 503 응답
     *
     * @since 7.1.0
     */
    protected function passwordChangeUnavailableResponse(): JsonResponse
    {
        return $this->error('auth.password_change_unavailable', 503);
    }

    /**
     * 비밀번호 변경 단계 안내 메시지 키를 고릅니다 (만료 / 관리자가 정해 준 비밀번호).
     *
     * @param  array<string, mixed>  $data  login/completeTwoFactor 의 변경 단계 응답
     * @return string 번역 키
     *
     * @since 7.1.0
     */
    protected function passwordChangeRequiredMessageKey(array $data): string
    {
        return ($data['reason'] ?? null) === 'admin_assigned'
            ? 'auth.password_change_required_admin_assigned'
            : 'auth.password_change_required';
    }

    /**
     * 2단계 인증 수단 미등록 차단 응답(401)을 구성합니다.
     *
     * 운영자가 메일 폴백을 껐고 이 사용자에게 쓸 수 있는 수단이 없을 때다. 통과시키면
     * 운영자가 끈 것과 정반대(2단계 인증 없는 로그인)가 되므로 막는다 — 구제는 등록이나
     * 관리자 초기화다.
     *
     * @return JsonResponse 401 응답
     */
    protected function enrollmentRequiredResponse(): JsonResponse
    {
        return $this->error('auth.two_factor_enrollment_required', 401);
    }

    /**
     * 관리자 권한 거절 문구를 고릅니다 — 휴면이 풀린 로그인이면 그 사실까지 알립니다.
     *
     * 관리자 로그인에 비관리자 휴면 회원이 들어오면 비밀번호 확인은 성공하므로
     * `AuthService::issueLoginSession()` 이 **먼저** 휴면을 해제하고 활동 로그를 남긴다.
     * 그 뒤 권한 판정이 403 으로 거절하면서 발급분만 회수하는데, 403 응답에는
     * `dormancy_restored` 가 실리지 않아 그 플래그에 묶인 토스트도 뜨지 않는다.
     * 그대로 두면 회원은 **로그인이 실패한 줄 알지만 실제로는 휴면이 해제된** 상태가 된다 —
     * 계획서가 약속한 「해제 + 활동 로그 + 안내」 중 안내만 사라지는 자리다.
     *
     * 상태를 되돌리지 않고 문구로 알리는 이유: 비밀번호 확인 성공은 그 자체가 본인확인이라
     * 해제 요건을 이미 충족했고, 되돌리면 그 회원이 일반 로그인으로 다시 풀어야 한다.
     *
     * @param  array<string, mixed>  $login  login/completeTwoFactor 반환 페이로드
     * @return string 번역 키
     */
    protected function adminRequiredMessageKey(array $login): string
    {
        return ($login['dormancy_restored'] ?? false)
            ? 'auth.admin_required_dormancy_restored'
            : 'auth.admin_required';
    }

    /**
     * challenge 를 발행한 수단에 맞는 안내 메시지 키를 고릅니다.
     *
     * 기본 문구("인증번호를 보냈습니다")는 메일을 전제로 한 안내다. 인증 앱(TOTP)은
     * 아무것도 보내지 않으므로 그대로 쓰면 오지 않는 메일을 기다리게 만든다.
     * 채널이 코어 어휘로 `app` 인 프로바이더만 앱 안내로 바꾸고, 그 밖의 수단(메일·
     * 외부 프로바이더)은 기존 문구를 그대로 유지한다 — 기존 테스트 계약 보존.
     *
     * @param  array<string, mixed>  $challenge  startTwoFactorChallenge 반환 페이로드
     * @return string 번역 키
     */
    protected function twoFactorRequiredMessageKey(array $challenge): string
    {
        $providerId = (string) ($challenge['provider_id'] ?? '');

        if ($providerId === '') {
            return 'auth.two_factor_required';
        }

        $manager = app(IdentityVerificationManager::class);

        if (! $manager->has($providerId)) {
            return 'auth.two_factor_required';
        }

        $channels = $manager->get($providerId)->getChannels();

        return in_array(IdentityVerificationChannel::App->value, $channels, true)
            ? 'auth.two_factor_required_app'
            : 'auth.two_factor_required';
    }
}
