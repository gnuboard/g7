<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 다국어 필드 검증 규칙
 *
 * 설정된 언어 목록에 대한 번역을 검증합니다.
 */
class TranslatableField implements ValidationRule
{
    /**
     * TranslatableField 생성자
     *
     * @param int $maxLength 각 번역의 최대 길이
     * @param bool $required 최소 하나의 번역 필수 여부
     */
    public function __construct(
        private int $maxLength = 255,
        private bool $required = false
    ) {}

    /**
     * 검증 규칙 실행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 배열이 아닌 경우
        if (! is_array($value)) {
            $fail(__('validation.translatable.must_be_array'));

            return;
        }

        // 허용된 언어 목록 가져오기
        $allowedLanguages = config('app.translatable_locales', ['ko', 'en']);

        // 각 언어별 검증
        foreach ($value as $lang => $text) {
            // 지원하지 않는 언어 코드
            if (! in_array($lang, $allowedLanguages)) {
                $fail(__('validation.translatable.unsupported_language', ['lang' => $lang]));

                return;
            }

            // null이거나 빈 문자열이 아닌 경우에만 검증
            if ($text !== null && $text !== '') {
                // 문자열이 아닌 경우
                if (! is_string($text)) {
                    $fail(__('validation.translatable.must_be_string', ['lang' => $lang]));

                    return;
                }

                // 최대 길이 초과
                if (strlen($text) > $this->maxLength) {
                    $fail(__('validation.translatable.max_length', [
                        'lang' => $lang,
                        'max' => $this->maxLength,
                    ]));

                    return;
                }
            }
        }

        // 필수인 경우 최소 하나의 번역은 있어야 함
        if ($this->required) {
            $hasValue = false;
            foreach ($value as $text) {
                if (! empty($text)) {
                    $hasValue = true;
                    break;
                }
            }

            if (! $hasValue) {
                $fail(__('validation.translatable.at_least_one_required'));
            }
        }
    }
}
