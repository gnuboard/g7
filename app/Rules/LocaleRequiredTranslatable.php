<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 현재 로케일 필수 다국어 필드 검증 규칙
 *
 * 현재 로케일(또는 지정된 로케일)의 값만 필수로 검증하고,
 * 다른 로케일은 선택적으로 검증합니다.
 */
class LocaleRequiredTranslatable implements ValidationRule
{
    /**
     * LocaleRequiredTranslatable 생성자
     *
     * @param int $maxLength 각 번역의 최대 길이
     * @param int $minLength 각 번역의 최소 길이 (기본: 0)
     * @param string|null $requiredLocale 필수 로케일 (null이면 현재 로케일 사용)
     */
    public function __construct(
        private int $maxLength = 255,
        private int $minLength = 0,
        private ?string $requiredLocale = null
    ) {}

    /**
     * 검증 규칙 실행
     *
     * @param string $attribute 필드명
     * @param mixed $value 검증 값
     * @param Closure $fail 실패 콜백
     * @return void
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

        // 필수 로케일 결정 (지정된 값 또는 현재 로케일)
        $requiredLocale = $this->requiredLocale ?? app()->getLocale();

        // 필수 로케일이 지원 언어에 없으면 첫 번째 언어 사용
        if (! in_array($requiredLocale, $allowedLanguages)) {
            $requiredLocale = $allowedLanguages[0];
        }

        // 필수 로케일 값 검증
        $requiredValue = $value[$requiredLocale] ?? null;

        if (is_null($requiredValue) || (is_string($requiredValue) && trim($requiredValue) === '')) {
            $fail(__('validation.translatable.current_locale_required', [
                'locale' => $requiredLocale,
            ]));

            return;
        }

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

                $length = mb_strlen($text);

                // 최소 길이 미달
                if ($this->minLength > 0 && $length < $this->minLength) {
                    $fail(__('validation.translatable.min_length', [
                        'lang' => $lang,
                        'min' => $this->minLength,
                    ]));

                    return;
                }

                // 최대 길이 초과
                if ($length > $this->maxLength) {
                    $fail(__('validation.translatable.max_length', [
                        'lang' => $lang,
                        'max' => $this->maxLength,
                    ]));

                    return;
                }
            }
        }
    }
}
