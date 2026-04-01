<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 금지 키워드 검증 규칙
 *
 * 제목이나 내용에 금지 키워드가 포함되어 있는지 검증합니다.
 * 대소문자를 구분하지 않습니다.
 */
class BlockedKeywordsRule implements ValidationRule
{
    /**
     * BlockedKeywordsRule 생성자
     *
     * @param  array|null  $keywords  금지 키워드 배열
     */
    public function __construct(
        private ?array $keywords = null
    ) {}

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
        // 금지 키워드가 없거나 빈 배열이면 통과
        if (empty($this->keywords)) {
            return;
        }

        // 값이 문자열이 아니면 통과
        if (! is_string($value)) {
            return;
        }

        // 각 금지 키워드 검사 (대소문자 무시)
        foreach ($this->keywords as $keyword) {
            if (empty($keyword)) {
                continue;
            }

            // 대소문자 무시하여 검색
            if (mb_stripos($value, $keyword) !== false) {
                $fail(__('sirsoft-board::validation.post.blocked_keyword', [
                    'keyword' => $keyword,
                ]));

                return;
            }
        }
    }
}
