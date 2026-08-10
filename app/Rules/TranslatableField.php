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
     * @param  int  $maxLength  각 번역의 최대 길이
     * @param  bool  $required  최소 하나의 번역 필수 여부
     * @param  bool  $strictLocales  지원 목록 밖 로케일 키를 실패로 처리할지 여부
     */
    public function __construct(
        private int $maxLength = 255,
        private bool $required = false,
        private bool $strictLocales = false
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
            // 지원 목록 밖 로케일 키
            //
            // app.translatable_locales 는 활성 언어팩 기준으로 부팅마다 덮어써지는 가변 런타임
            // 값이다. 언어팩을 비활성화하면 기존 레코드에 남아 있던 그 로케일 키 때문에 수정
            // 자체가 차단되므로, 기본값은 "길이 검증 대상에서 제외하고 통과"로 둔다.
            // 데이터를 지우지 않으므로 언어팩을 다시 켜면 번역이 그대로 복구된다.
            if (! in_array($lang, $allowedLanguages)) {
                if ($this->strictLocales) {
                    $fail(__('validation.translatable.unsupported_language', ['lang' => $lang]));

                    return;
                }

                continue;
            }

            // null이거나 빈 문자열이 아닌 경우에만 검증
            if ($text !== null && $text !== '') {
                // 문자열이 아닌 경우
                if (! is_string($text)) {
                    $fail(__('validation.translatable.must_be_string', ['lang' => $lang]));

                    return;
                }

                // 최대 길이 초과 (바이트가 아닌 문자 단위로 계산)
                if (mb_strlen($text) > $this->maxLength) {
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
