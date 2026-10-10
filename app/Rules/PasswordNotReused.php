<?php

namespace App\Rules;

use App\Contracts\Repositories\UserPasswordHistoryRepositoryInterface;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * 비밀번호 재사용 금지 규칙 (ISMS-P 2.5.4)
 *
 * `security.password_reuse_prevention_enabled` 가 켜져 있으면 최근 N개(현재 비밀번호 + 이전 N-1개)와
 * 같은 비밀번호를 거부한다. N 은 `password_reuse_count`(1~10, 클램프).
 *
 * **본인 증명이 통과한 뒤에만 붙인다** — 증명 전에 평가하면 "재사용" 오류 유무로 현재·이전
 * 비밀번호를 맞혀 보는 추측 오라클이 된다. 각 FormRequest 의 `withValidator()->after()` 가 앞선
 * 오류(토큰·현재 비밀번호·challenge)가 없을 때만 실행한다.
 *
 * @since 7.1.0
 */
class PasswordNotReused implements ValidationRule
{
    /**
     * @param  User  $user  비밀번호를 바꾸는 회원
     */
    public function __construct(private readonly User $user) {}

    /**
     * 비밀번호가 최근 N개 중 하나와 같은지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PasswordPolicy::enabled('password_reuse_prevention_enabled') || ! is_string($value) || $value === '') {
            return;
        }

        $count = PasswordPolicy::clampedSetting('password_reuse_count', 3);

        if ($this->matchesRecent($value, $count)) {
            $fail(trans_choice('validation.password_policy.reused', $count));
        }
    }

    /**
     * 현재 비밀번호와 최근 이전 비밀번호 ($count - 1)개 중 하나와 같은지 판정합니다.
     *
     * @param  string  $plain  새 비밀번호 평문
     * @param  int  $count  비교할 최근 비밀번호 수 (현재 포함)
     * @return bool 같으면 true
     */
    private function matchesRecent(string $plain, int $count): bool
    {
        $current = $this->user->getRawOriginal('password');

        if (is_string($current) && $current !== '' && Hash::check($plain, $current)) {
            return true;
        }

        $hashes = app(UserPasswordHistoryRepositoryInterface::class)
            ->latestHashes((int) $this->user->getKey(), $count - 1);

        foreach ($hashes as $hash) {
            if (Hash::check($plain, $hash)) {
                return true;
            }
        }

        return false;
    }
}
