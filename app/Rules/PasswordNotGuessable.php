<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 추측하기 쉬운 비밀번호 금지 규칙 (ISMS-P 2.5.4)
 *
 * 운영자가 켠 항목만 검사한다(전부 기본 꺼짐).
 * ① 아이디(이메일 `@` 앞부분, 3자 이상) 포함 — 대소문자 무시
 * ② 같은 문자 N회 이상 연속 (N = `password_repeated_chars_length`, 3~6)
 * ③ 오름·내림 연속 N자 — 숫자 0-9, 영문 a-z, 자판 행 qwertyuiop·asdfghjkl·zxcvbnm (대소문자 무시)
 *
 * 글자 수는 한계값으로 클램프해 읽는다 — 단건 저장 경로로 범위 밖 값이 들어와도 판정이 무너지지 않는다.
 *
 * @since 7.1.0
 */
class PasswordNotGuessable implements ValidationRule
{
    /**
     * 연속 문자 판정에 쓰는 문자열 (오름차순 — 내림차순은 뒤집어 본다)
     */
    private const SEQUENCES = [
        '0123456789',
        'abcdefghijklmnopqrstuvwxyz',
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
    ];

    /**
     * 아이디로 볼 이메일 앞부분의 최소 길이
     */
    private const MIN_IDENTIFIER_LENGTH = 3;

    /**
     * @param  string|null  $identifier  아이디 판정에 쓸 이메일 (없으면 아이디 포함 검사 생략)
     */
    public function __construct(private readonly ?string $identifier = null) {}

    /**
     * 비밀번호가 추측하기 쉬운 형태인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (PasswordPolicy::enabled('password_block_identifier') && $this->containsIdentifier($value)) {
            $fail(__('validation.password_policy.contains_identifier'));
        }

        if (PasswordPolicy::enabled('password_block_repeated_chars')) {
            $length = PasswordPolicy::clampedSetting('password_repeated_chars_length', 4);

            if (self::hasRepeatedChars($value, $length)) {
                $fail(__('validation.password_policy.repeated_chars', ['count' => $length]));
            }
        }

        if (PasswordPolicy::enabled('password_block_sequential_chars')) {
            $length = PasswordPolicy::clampedSetting('password_sequential_chars_length', 4);

            if (self::hasSequentialChars($value, $length)) {
                $fail(__('validation.password_policy.sequential_chars', ['count' => $length]));
            }
        }
    }

    /**
     * 비밀번호에 이메일 앞부분이 들어 있는지 판정합니다.
     *
     * @param  string  $value  비밀번호
     * @return bool 들어 있으면 true
     */
    private function containsIdentifier(string $value): bool
    {
        if ($this->identifier === null || $this->identifier === '') {
            return false;
        }

        $local = mb_strtolower(explode('@', $this->identifier, 2)[0]);

        if (mb_strlen($local) < self::MIN_IDENTIFIER_LENGTH) {
            return false;
        }

        return str_contains(mb_strtolower($value), $local);
    }

    /**
     * 같은 문자가 $length 회 이상 연속하는지 판정합니다.
     *
     * @param  string  $value  비밀번호
     * @param  int  $length  연속 기준 길이
     * @return bool 연속하면 true
     */
    public static function hasRepeatedChars(string $value, int $length): bool
    {
        if ($length < 2) {
            return false;
        }

        return preg_match('/(.)\1{'.($length - 1).',}/u', $value) === 1;
    }

    /**
     * 오름·내림 연속 문자가 $length 자 이상 들어 있는지 판정합니다.
     *
     * @param  string  $value  비밀번호
     * @param  int  $length  연속 기준 길이
     * @return bool 들어 있으면 true
     */
    public static function hasSequentialChars(string $value, int $length): bool
    {
        if ($length < 2) {
            return false;
        }

        $lower = mb_strtolower($value);

        foreach (self::SEQUENCES as $sequence) {
            foreach ([$sequence, strrev($sequence)] as $run) {
                $last = strlen($run) - $length;

                for ($i = 0; $i <= $last; $i++) {
                    if (str_contains($lower, substr($run, $i, $length))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
