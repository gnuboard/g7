<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

class RateRunResource extends BaseApiResource
{
    /**
     * 비밀값을 제외한 API 응답을 구성합니다.
     *
     * @param  Request  $request  request 입력값
     * @return array 처리 결과
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'trigger' => $this->trigger->value, 'trigger_label' => $this->trigger->label(),
            'status' => $this->status->value, 'status_label' => $this->status->label(), 'search_date' => $this->search_date?->toDateString(),
            'rate_type' => $this->rate_type->value, 'base_currency' => $this->base_currency, 'base_unit' => $this->base_unit,
            'request_count' => $this->request_count, 'applied_count' => $this->applied_count, 'unchanged_count' => $this->unchanged_count,
            'skipped_count' => $this->skipped_count, 'message' => $this->message, 'source_run_id' => $this->source_run_id,
            'started_at' => $this->formatDateTimeStringForUser($this->started_at), 'finished_at' => $this->formatDateTimeStringForUser($this->finished_at),
            'items' => RateRunItemResource::collection($this->whenLoaded('items'))];
    }
}
