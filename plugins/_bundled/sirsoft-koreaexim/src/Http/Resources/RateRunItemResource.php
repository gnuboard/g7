<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

class RateRunItemResource extends BaseApiResource
{
    /**
     * 비밀값을 제외한 API 응답을 구성합니다.
     *
     * @param  Request  $request  request 입력값
     * @return array 처리 결과
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'currency_code' => $this->currency_code, 'api_currency_unit' => $this->api_currency_unit,
            'unit_divisor' => $this->unit_divisor, 'source_rate' => $this->source_rate, 'previous_rate' => $this->previous_rate,
            'new_rate' => $this->new_rate, 'status' => $this->status->value, 'status_label' => $this->status->label(),
            'currency_snapshot' => $this->currency_snapshot, 'api_fields' => $this->api_fields];
    }
}
