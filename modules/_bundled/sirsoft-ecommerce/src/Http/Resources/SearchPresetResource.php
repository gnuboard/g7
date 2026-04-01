<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 검색 프리셋 리소스
 */
class SearchPresetResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'target_screen' => $this->target_screen,
            'name' => $this->preset_name,
            'conditions' => $this->conditions,
            'filters' => $this->toQueryParams(),
            'sort_order' => $this->sort_order,
            'is_default' => $this->is_default,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
