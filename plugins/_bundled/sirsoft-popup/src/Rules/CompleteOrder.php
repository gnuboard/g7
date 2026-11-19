<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;

/**
 * 순서 변경 요청이 팝업 전체(빠짐·중복·없는 ID 없음)인지 확인한다.
 *
 * 일부만 보내면 나머지 팝업의 순서 값이 겹쳐 사용자 화면 캐러셀 순서가 꼬이므로 거부한다.
 */
class CompleteOrder implements ValidationRule
{
    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값 (팝업 ID 배열)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $sent = array_map('intval', $value);
        $expected = app(PopupRepositoryInterface::class)->allIds();

        sort($sent);
        sort($expected);

        if ($sent !== $expected) {
            $fail(__('sirsoft-popup::validation.order.incomplete'));
        }
    }
}
