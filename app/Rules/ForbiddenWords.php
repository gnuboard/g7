<?php

namespace App\Rules;

use App\Support\NicknameNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 금지어 검증 규칙.
 *
 * 값에 금지어가 들어 있으면 실패한다. 기본은 대소문자만 무시하는 부분 일치이고, `normalized` 를 켜면
 * 값과 금지어를 모두 닉네임 정규화(전각, 대소문자, 공백 무시)한 뒤 견준다.
 *
 * @since 7.2.0
 */
class ForbiddenWords implements ValidationRule
{
    /**
     * @param  array<int, mixed>|null  $words  금지어 목록
     * @param  Closure(string): string|null  $message  걸린 금지어를 받아 오류 문구를 만드는 함수 (생략하면 코어 문구)
     * @param  bool  $normalized  정규화한 뒤 견줄지
     */
    public function __construct(
        private ?array $words = null,
        private ?Closure $message = null,
        private bool $normalized = false,
    ) {}

    /**
     * 값에 들어 있는 첫 금지어를 반환합니다.
     *
     * @param  mixed  $value  검사할 값
     * @param  array<int, mixed>|null  $words  금지어 목록
     * @param  bool  $normalized  정규화한 뒤 견줄지
     * @return string|null 걸린 금지어 (없으면 null)
     */
    public static function firstMatch(mixed $value, ?array $words, bool $normalized = false): ?string
    {
        if (empty($words) || ! is_string($value) || $value === '') {
            return null;
        }

        $haystack = $normalized ? (NicknameNormalizer::normalize($value) ?? '') : $value;

        foreach ($words as $word) {
            if (! is_string($word) || $word === '') {
                continue;
            }

            if ($normalized) {
                $needle = NicknameNormalizer::normalize($word);
                if ($needle !== null && $haystack !== '' && str_contains($haystack, $needle)) {
                    return $word;
                }

                continue;
            }

            if (mb_stripos($value, $word) !== false) {
                return $word;
            }
        }

        return null;
    }

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $word = self::firstMatch($value, $this->words, $this->normalized);
        if ($word === null) {
            return;
        }

        $fail($this->message !== null
            ? ($this->message)($word)
            : __('validation.forbidden_word', ['word' => $word]));
    }
}
