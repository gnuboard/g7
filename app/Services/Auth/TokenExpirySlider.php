<?php

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 인증 토큰 만료 시각 슬라이딩 연장.
 *
 * 남은 시간이 유지시간(`security.auth_token_lifetime`, 분)의 절반 미만이고 아직 만료 전이면 만료 시각을
 * 지금부터 유지시간만큼 뒤로 민다. API 요청(`RefreshTokenExpiration`)과 개발자 화면 요청(`ScreenAuth`, #792)이
 * 같은 함수를 쓴다 — 개발자 화면만 보는 회원이 토큰 만료(기본 30분)로 로그아웃되지 않게 한다.
 *
 * @since 7.2.0
 */
class TokenExpirySlider
{
    /**
     * @param  UserRepositoryInterface  $userRepository  회원 저장소 (토큰 만료 갱신)
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 필요하면 토큰 만료 시각을 연장합니다.
     *
     * @param  PersonalAccessToken  $token  대상 토큰
     * @param  Request|null  $request  현재 요청 (웹 세션이 있으면 세션 수명도 토큰과 맞춘다)
     * @return bool 연장했으면 true
     */
    public function slide(PersonalAccessToken $token, ?Request $request = null): bool
    {
        if (! $token->expires_at) {
            return false;
        }

        $lifetime = (int) g7_core_settings('security.auth_token_lifetime', 30);
        if ($lifetime === 0) {
            return false; // 무한대면 갱신 불필요
        }

        // 남은 시간 계산 (초 단위로 정확히 계산)
        $remainingSeconds = now()->diffInSeconds($token->expires_at, false);
        $thresholdSeconds = ($lifetime / 2) * 60;

        // 임계값 미만이고 아직 만료 전이면 갱신
        if ($remainingSeconds > 0 && $remainingSeconds < $thresholdSeconds) {
            $this->userRepository->updateAccessTokenExpiry($token, now()->addMinutes($lifetime));

            // 세션 만료 시간도 토큰과 동기화 (웹 세션이 있는 경우)
            if ($request?->hasSession()) {
                config(['session.lifetime' => $lifetime]);
            }

            return true;
        }

        return false;
    }
}
