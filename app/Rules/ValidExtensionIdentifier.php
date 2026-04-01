<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 확장 식별자(identifier) 형식 검증 규칙
 *
 * 유효한 형식:
 * - 하이픈('-')으로 구분된 최소 2개 부분 (vendor-name)
 * - 영문 소문자, 숫자, 언더스코어('_')만 허용
 * - 각 단어(하이픈/언더스코어로 구분)의 첫 글자는 숫자 불가
 * - 빈 부분 불가 (연속 하이픈/언더스코어, 양끝 하이픈/언더스코어)
 *
 * 유효 예시: sirsoft-board, sirsoft-daum_postcode, sirsoft-board2
 * 무효 예시: sirsoftboard, sirsoft-2shop, Sirsoft-Board, sirsoft-my@module
 */
class ValidExtensionIdentifier implements ValidationRule
{
    /**
     * 확장 식별자 형식을 검증합니다.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 문자열 타입 확인
        if (! is_string($value)) {
            $fail(__('validation.extension_identifier.must_be_string'));

            return;
        }

        // 최대 길이 확인
        if (mb_strlen($value) > 255) {
            $fail(__('validation.extension_identifier.max'));

            return;
        }

        // 하이픈으로 분리하여 최소 2개 부분 확인 (vendor-name)
        $parts = explode('-', $value);
        if (count($parts) < 2) {
            $fail(__('validation.extension_identifier.min_parts'));

            return;
        }

        // 빈 부분 확인 (연속 하이픈, 양끝 하이픈)
        foreach ($parts as $part) {
            if ($part === '') {
                $fail(__('validation.extension_identifier.empty_part'));

                return;
            }
        }

        // 각 부분 검증 (하이픈으로 구분된 각 부분)
        foreach ($parts as $part) {
            // 영문 소문자, 숫자, 언더스코어만 허용
            if (! preg_match('/^[a-z0-9_]+$/', $part)) {
                $fail(__('validation.extension_identifier.invalid_characters'));

                return;
            }

            // 언더스코어로 구분된 각 단어 검증
            $words = explode('_', $part);
            foreach ($words as $word) {
                // 빈 단어 확인 (연속 언더스코어, 양끝 언더스코어)
                if ($word === '') {
                    $fail(__('validation.extension_identifier.empty_word'));

                    return;
                }

                // 각 단어의 첫 글자가 숫자이면 안 됨
                if (preg_match('/^[0-9]/', $word)) {
                    $fail(__('validation.extension_identifier.word_starts_with_digit'));

                    return;
                }
            }
        }
    }
}
