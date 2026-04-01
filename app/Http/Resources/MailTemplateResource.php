<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class MailTemplateResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청
     * @return array<string, mixed> 리소스 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'type' => $this->getValue('type'),
            'subject' => $this->getValue('subject'),
            'body' => $this->getValue('body'),
            'variables' => $this->getValue('variables'),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'updated_by' => $this->updater?->uuid,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
