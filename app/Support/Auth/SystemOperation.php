<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Log;

/**
 * 행위자 없는 시스템 작업 문맥.
 *
 * 회원 정보 동기화·프로비저닝·예약 탈퇴처럼 **사람이 아니라 시스템이** 회원을 바꾸는 경로는, 운영자가 회원 수정·탈퇴·삭제 훅에
 * 건 본인인증 정책에 걸리면 안 된다 — 행위자가 없으면 정책이 대상 회원으로 판정해 본인인증(428)을 요구하고 작업이 멈춘다.
 * 이 문맥 안에서 실행된 코드는 본인인증 정책(`EnforceIdentityPolicyListener`, `EnforceIdentityPolicy` 미들웨어)을 건너뛴다.
 *
 * 사람이 행위자인 웹 요청 경로(컨트롤러)에서는 쓰지 않는다 — 본인인증을 우회하는 통로가 된다.
 *
 * @since 7.2.0
 */
final class SystemOperation
{
    /** 중첩 깊이 */
    private static int $depth = 0;

    /** 바깥 작업의 사유 (기록용) */
    private static ?string $reason = null;

    /**
     * 시스템 작업 문맥 안에서 콜백을 실행합니다 (예외가 나도 문맥은 복원된다).
     *
     * @param  string  $reason  작업 사유 (예: `vendor-plugin:provisioning`) — 정책을 건너뛸 때 기록된다
     * @param  callable  $fn  실행할 작업
     * @return mixed 콜백 반환값
     */
    public static function run(string $reason, callable $fn): mixed
    {
        $previousReason = self::$reason;
        self::$depth++;
        self::$reason = $reason;

        try {
            return $fn();
        } finally {
            self::$depth--;
            self::$reason = self::$depth > 0 ? $previousReason : null;
        }
    }

    /**
     * 지금 시스템 작업 문맥 안인지 돌려줍니다.
     *
     * @return bool 문맥 안이면 true
     */
    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * 지금 문맥의 사유를 돌려줍니다.
     *
     * @return string|null 사유 (문맥 밖이면 null)
     */
    public static function reason(): ?string
    {
        return self::$reason;
    }

    /**
     * 정책을 건너뛴 사실을 기록합니다.
     *
     * @param  string  $scope  건너뛴 정책 범위 (훅 이름 등)
     * @return void
     */
    public static function logBypass(string $scope): void
    {
        Log::info('시스템 작업 문맥이라 본인인증 정책을 건너뜁니다', ['scope' => $scope, 'reason' => self::$reason]);
    }
}
