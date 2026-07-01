<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * 길이 제한 필드의 최소/최대 교차 검증 트레이트
 *
 * 제목/본문/댓글 길이의 `min_*_length` 와 `max_*_length` 는 각 필드의 절대 범위만
 * 검증되므로, `min_*_length > max_*_length` 같은 논리적으로 모순된 조합이 저장될 수 있다.
 * 이 트레이트는 두 값이 모두 요청에 존재할 때 min ≤ max 를 보장한다.
 *
 * 생성/수정 Request 의 withValidator() 에서 applyLengthRangeValidation() 을 호출한다.
 */
trait ValidatesLengthRange
{
    /**
     * 교차 검증 대상 필드 쌍 (min 필드 => max 필드).
     *
     * @return array<string, string>
     */
    protected function lengthRangePairs(): array
    {
        return [
            'min_title_length' => 'max_title_length',
            'min_content_length' => 'max_content_length',
            'min_comment_length' => 'max_comment_length',
        ];
    }

    /**
     * min_*_length 가 max_*_length 보다 큰 조합을 검증 실패로 처리합니다.
     *
     * 두 값이 모두 요청에 존재할 때만 비교한다. 부분 수정(한쪽만 전송)에서는
     * DB 기존값과의 비교 없이 통과시킨다. 오류는 max_* 필드에 추가하여
     * 기존 레이아웃의 max_* 오류 표시 요소가 그대로 렌더하도록 한다.
     *
     * @param  Validator  $validator  검증기
     */
    protected function applyLengthRangeValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // 검증 대상 데이터는 validator 에서 직접 읽는다 (단위 테스트의 Validator::make 와
            // 실제 HTTP 요청 양쪽에서 동일하게 동작).
            $data = $validator->getData();
            $errors = $validator->errors();

            foreach ($this->lengthRangePairs() as $minField => $maxField) {
                if (! array_key_exists($minField, $data) || ! array_key_exists($maxField, $data)) {
                    continue;
                }

                if ($errors->has($minField) || $errors->has($maxField)) {
                    continue;
                }

                $min = $data[$minField];
                $max = $data[$maxField];

                if (! is_numeric($min) || ! is_numeric($max)) {
                    continue;
                }

                if ((int) $min > (int) $max) {
                    $validator->errors()->add(
                        $maxField,
                        __("sirsoft-board::validation.{$maxField}.gte_min")
                    );
                }
            }
        });
    }
}
