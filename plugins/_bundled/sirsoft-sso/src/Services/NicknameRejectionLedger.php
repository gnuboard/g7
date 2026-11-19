<?php

namespace Plugins\Sirsoft\Sso\Services;

/**
 * 코어가 닉네임 정책에 걸려 버린 닉네임의 사유를 요청 한 번 동안 들고 있는 장부.
 *
 * 리스너(`RecordNicknameRejectionListener`)가 적고, 회원을 고친 서비스가 꺼내 자기 기록에 싣는다.
 * 컨테이너가 인스턴스를 여러 번 만들어도 같은 장부를 보도록 저장소는 클래스에 둔다.
 *
 * @since 1.0.0
 */
class NicknameRejectionLedger
{
    /** @var array<int, string> 회원 ID => 버린 사유 */
    private static array $reasons = [];

    /**
     * 버린 사유를 적습니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $reason  사유 (`duplicate`, `forbidden_word`)
     * @return void
     */
    public function record(int $userId, string $reason): void
    {
        self::$reasons[$userId] = $reason;
    }

    /**
     * 적어 둔 사유를 꺼내고 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @return string|null 사유, 없으면 null
     */
    public function pull(int $userId): ?string
    {
        $reason = self::$reasons[$userId] ?? null;
        unset(self::$reasons[$userId]);

        return $reason;
    }
}
