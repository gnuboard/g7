<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Auth\AccountLockedException;
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
            ['minutes' => $e->remainingMinutes]
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
}
