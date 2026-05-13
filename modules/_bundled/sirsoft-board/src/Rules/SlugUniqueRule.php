<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Models\Board;

class SlugUniqueRule implements ValidationRule
{
    /**
     * 예약된 slug 목록 (권한 시스템과 충돌 방지)
     *
     * @var array<string>
     */
    private const RESERVED_SLUGS = [
        'boards',  // 모듈 관리 카테고리 (sirsoft-board.boards와 충돌)
    ];

    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 예약어 검증
        if (in_array($value, self::RESERVED_SLUGS, true)) {
            $fail(__('sirsoft-board::validation.slug.reserved', ['value' => $value]));

            return;
        }

        // 중복 검증
        if (Board::where('slug', $value)->exists()) {
            $fail(__('sirsoft-board::validation.slug.unique'));
        }
    }
}
