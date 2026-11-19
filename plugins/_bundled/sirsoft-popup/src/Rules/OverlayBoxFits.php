<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 글 상자가 이미지 오른쪽 끝을 넘지 않는지 — 왼쪽 위치(x) + 폭(w) ≤ 100%.
 *
 * 각 값의 범위·형식은 필드 규칙이 따로 본다. 둘 중 하나라도 숫자가 아니면 여기서는 보지 않는다(필드 규칙이 알린다).
 */
class OverlayBoxFits implements ValidationRule
{
    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  상자 한 개
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! is_numeric($value['x'] ?? null) || ! is_numeric($value['w'] ?? null)) {
            return;
        }

        // 소수 1자리로 저장하므로 반올림 오차만큼은 허용한다
        if (round((float) $value['x'] + (float) $value['w'], 1) > 100) {
            $fail(__('sirsoft-popup::validation.overlays.box_past_right_edge'));
        }
    }
}
