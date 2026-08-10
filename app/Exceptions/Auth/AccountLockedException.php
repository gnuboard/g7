<?php

namespace App\Exceptions\Auth;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 계정 잠금 예외 — 보안 환경설정의 `max_login_attempts` 도달 후
 * `login_lockout_time` 분 동안 로그인 시도를 차단할 때 발생합니다.
 *
 * HTTP 423 Locked 응답으로 매핑되며, 프론트엔드 토스트는 다국어 키
 * `auth.account_locked` 로 잔여 분(`minutes` 플레이스홀더)을 노출합니다.
 *
 * `login_lockout_time = 0`(무한대) 으로 잠긴 계정은 해제 시각이 없으므로
 * `lockedUntil` / `remainingMinutes` 가 모두 null 입니다. 이 경우 프론트엔드는
 * 잔여 시간 대신 `auth.account_locked_permanently` 안내를 노출합니다.
 *
 * 컨트롤러는 본 예외를 별도로 catch 하여 ResponseHelper 응답을 만들거나,
 * 글로벌 예외 핸들러가 자동으로 423 JSON 응답으로 변환합니다.
 *
 * @since 7.0.0
 */
class AccountLockedException extends HttpException
{
    public function __construct(
        public readonly ?Carbon $lockedUntil,
        public readonly ?int $remainingMinutes,
        ?string $message = null,
    ) {
        parent::__construct(
            423,
            $message ?? ($lockedUntil === null ? 'auth.account_locked_permanently' : 'auth.account_locked'),
            null,
            // 영구 잠금은 재시도 시점을 제시할 수 없으므로 Retry-After 를 붙이지 않는다.
            $remainingMinutes === null ? [] : ['Retry-After' => max(1, $remainingMinutes * 60)]
        );
    }

    /**
     * 영구 잠금 여부를 반환합니다.
     *
     * @return bool 해제 시각이 없는 무기한 잠금이면 true
     */
    public function isPermanent(): bool
    {
        return $this->lockedUntil === null;
    }
}
