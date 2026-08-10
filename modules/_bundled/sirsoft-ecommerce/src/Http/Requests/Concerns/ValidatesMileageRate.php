<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * 옵션별 적립률 상한 검증 Trait
 *
 * 전역 `default_earn_rate` 는 `max:100` 인데, 동일한 `/100` 정률 계산에 쓰이는
 * 옵션별 `mileage_value` 에는 상한이 없었다. `mileage_type` 에 따라 의미가 달라
 * (percent = 비율 / fixed = 금액) 필드 단위 규칙으로는 표현할 수 없으므로
 * 인덱스별로 판정한다.
 */
trait ValidatesMileageRate
{
    /**
     * 정률(percent) 적립의 값이 100을 넘지 않는지 검증합니다.
     *
     * `fixed`(정액)는 금액이므로 상한을 두지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @param  string  $arrayKey  검사할 배열 키 (예: `options`, `items`)
     */
    protected function validateMileageRates(Validator $validator, string $arrayKey = 'options'): void
    {
        $rows = $this->input($arrayKey);

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = $row['mileage_type'] ?? null;
            $value = $row['mileage_value'] ?? null;

            if ($type !== 'percent' || $value === null || $value === '') {
                continue;
            }

            if ((float) $value > 100) {
                $validator->errors()->add(
                    "{$arrayKey}.{$index}.mileage_value",
                    __('sirsoft-ecommerce::validation.product.mileage_percent_max')
                );
            }
        }
    }
}
