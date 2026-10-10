<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * 사용자 상태 Enum
 *
 * 사용자 계정의 상태 값을 정의합니다.
 */
enum UserStatus: string
{
    /**
     * 활성화됨
     */
    case Active = 'active';

    /**
     * 비활성화됨
     */
    case Inactive = 'inactive';

    /**
     * 차단됨
     */
    case Blocked = 'blocked';

    /**
     * 탈퇴됨
     */
    case Withdrawn = 'withdrawn';

    /**
     * 본인인증 대기 (Mode C — 가입 직후 비활성, IDV 통과 후 활성화)
     */
    case PendingVerification = 'pending_verification';

    /**
     * 휴면 (장기 미접속 — 로그인 성공을 본인확인으로 보고 Active 로 되돌린다)
     *
     * 평소에는 `privacy:process-dormant` 가 관리하는 자동 상태지만, 관리자가 손으로도
     * 지정할 수 있다 — 그때 `dormant_at` 을 함께 채우는 것은 상태 변경 경로
     * (`UserService::updateUser()`/`bulkUpdateStatus()`)의 책임이다. 그 짝을 비워 두면
     * 휴면 후 탈퇴 전환 판정이 기준점을 잃는다.
     */
    case Dormant = 'dormant';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인합니다.
     *
     * @param  string  $value  검증할 상태 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 상태의 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'user.status.'.$this->value;
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 상태별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant (success, secondary, danger, warning)
     */
    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'secondary',
            self::Blocked => 'danger',
            self::Withdrawn => 'warning',
            self::PendingVerification => 'info',
            self::Dormant => 'secondary',
        };
    }

    /**
     * 이 상태로 바뀔 때 함께 맞춰야 하는 시각 컬럼의 짝을 반환합니다.
     *
     * 상태 컬럼 하나만 쓰고 딸린 시각 컬럼을 그대로 두면 배치·판정 쿼리가 낡은 표지를
     * 읽는다. 특히 `dormant_notified_at` 은 **단계마다 NULL 로 되돌리는 것이 전제**라
     * (마이그레이션 `2026_09_21_000001` docblock) 한 자리만 빠져도 두 방향으로 깨진다 —
     * 관리자가 휴면 회원을 `active` 로 풀어 주면 낡은 표지 때문에 사전 안내 쿼리
     * (`whereNull('dormant_notified_at')`)에서 빠지고 전환 쿼리(`whereNotNull` + 기한 경과)
     * 로는 즉시 잡혀 **안내 한 번 없이 다시 휴면으로 잠기고**, 반대로 `dormant` 를 손으로
     * 지정하면 낡은 표지가 탈퇴 예고를 건너뛰게 해 **예고 없이 자동 탈퇴(익명화)** 된다.
     *
     * 그래서 짝의 정의를 상태 어휘와 같은 자리에 둔다. 로그인 해제 경로
     * (`AuthService::restoreDormantAccount()`)와 관리자 경로(`UserService` 의 생성·수정·일괄)
     * 가 **이 한 곳을 함께 읽어** 어휘가 갈라지지 않게 한다 — 이전에는 로그인 경로만 짝을
     * 맞추고 관리자 경로 셋이 비대칭이었다.
     *
     * `Withdrawn` 이 여기서 `withdrawn_at` 을 주는 것은 **생성 경로 전용**이다. 수정·일괄
     * 경로의 탈퇴는 `withdrawUser()` 가 익명화·훅과 함께 전담하므로 그쪽 호출부가 이 짝을
     * 쓰지 않는다.
     *
     * @return array<string, Carbon|null> 컬럼명 → 채울 값(`null` 이면 비운다)
     */
    public function transitionTimestamps(): array
    {
        return match ($this) {
            self::Active => [
                'blocked_at' => null,
                'withdrawn_at' => null,
                'dormant_at' => null,
                'dormant_notified_at' => null,
            ],
            self::Blocked => ['blocked_at' => now()],
            self::Withdrawn => ['withdrawn_at' => now()],
            self::Dormant => [
                'dormant_at' => now(),
                'dormant_notified_at' => null,
            ],
            // Enum 에 상태가 더해져도 이 자리가 UnhandledMatchError → 500 이 되지 않도록
            // 남겨 둔다. 검증은 `Rule::in(UserStatus::values())` 로 Enum 전체를 자동 허용해
            // 막아 주지 않는다 — 관리자가 눌러볼 수 있는 경로다.
            default => [],
        };
    }
}
