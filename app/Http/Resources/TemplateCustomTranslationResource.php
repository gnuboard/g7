<?php

namespace App\Http\Resources;

use App\Models\TemplateCustomTranslation;
use Illuminate\Http\Request;

/**
 * 템플릿 커스텀 다국어 키 리소스.
 *
 * @property TemplateCustomTranslation $resource
 */
class TemplateCustomTranslationResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'template_id' => $this->getValue('template_id'),
            'layout_name' => $this->getValue('layout_name'),
            'translation_key' => $this->getValue('translation_key'),
            'values' => $this->getValue('values', []),
            'status' => $this->getValue('status'),
            'lock_version' => (int) $this->getValue('lock_version', 0),
            'created_at' => $this->getValue('created_at'),
            'updated_at' => $this->getValue('updated_at'),
        ];
    }
}
