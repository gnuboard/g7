<?php

namespace App\Rules\Analytics;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 구간 경계 목록(쉼표로 구분한 정수)이 순증가하는지 검증합니다.
 *
 * 체류시간·본 페이지 수 구간 리포트는 경계를 차례로 읽어 라벨을 만든다. 경계가 거꾸로이거나
 * 겹치면 어떤 방문은 두 구간에 들고 어떤 구간은 영영 비어, 분포가 조용히 틀린다.
 * 형식(숫자·쉼표)은 정규식 규칙이 먼저 막으므로 여기서는 순서·중복·범위만 본다.
 */
class AscendingBounds implements ValidationRule
{
    /**
     * 규칙을 만듭니다.
     *
     * @param  int  $min  경계 하나의 최솟값
     * @param  int  $max  경계 하나의 최댓값
     */
    public function __construct(
        private readonly int $min,
        private readonly int $max,
    ) {}

    /**
     * 값이 범위 안의 순증가 정수 목록인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $previous = null;

        foreach (explode(',', $value) as $part) {
            if (! ctype_digit($part)) {
                return;
            }

            $bound = (int) $part;

            if ($bound < $this->min || $bound > $this->max || ($previous !== null && $bound <= $previous)) {
                $fail(__('validation.analytics.bucket_bounds', ['min' => $this->min, 'max' => $this->max]));

                return;
            }

            $previous = $bound;
        }
    }
}
