<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PermissionResource extends BaseApiResource
{
    /**
     * 권한 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'parent_id' => $this->getValue('parent_id'),
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedName(),
            'name_raw' => $this->getValue('name'),
            'description' => $this->getLocalizedDescription(),
            'description_raw' => $this->getValue('description'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'order' => $this->getValue('order', 0),
            'is_assignable' => $this->when(
                is_object($this->resource) && method_exists($this->resource, 'isAssignable'),
                fn () => $this->resource->isAssignable()
            ),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
