<?php

namespace Modules\Sirsoft\Board\Rules;

use App\Rules\ForbiddenWords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 금지 키워드 검증 규칙
 *
 * 제목이나 내용에 금지 키워드가 포함되어 있는지 검증합니다.
 * 대소문자를 구분하지 않습니다. 판정은 코어 금지어 규칙(ForbiddenWords)에 맡깁니다.
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
        // 판정은 코어 금지어 규칙이 한다(7.2.0) — 대소문자를 무시한 부분 일치. 문구는 게시판의 것을 그대로 쓴다.
        $keyword = ForbiddenWords::firstMatch($value, $this->keywords);

        if ($keyword !== null) {
            $fail(__('sirsoft-board::validation.post.blocked_keyword', [
                'keyword' => $keyword,
            ]));
        }
    }
}
